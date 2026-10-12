<?php

namespace App\Services\Agent;

use App\Models\Client;
use App\Models\ClientService;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ServiceExpense;
use App\Models\User;
use App\Services\Billing\ContractService;
use App\Services\Billing\InvoiceService;
use App\Services\CascadeDelete;
use App\Services\LeadService;
use App\Support\Money;
use App\Support\Rut;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ediciones y eliminaciones que propone el Agent. Nunca se ejecutan solas: se arma un resumen «antes → después» que la persona
 * confirma en el chat, y recién ahí se aplican (con sus permisos y la visibilidad de los datos al momento de confirmar).
 * Las eliminaciones son «suaves» (papelera) salvo los gastos de un servicio.
 */
class AgentActions
{
    /** Entidades: nombre legible, permiso para editar y para eliminar. */
    public const ENTITIES = [
        'lead' => ['label' => 'prospecto', 'update' => 'leads.update', 'delete' => 'leads.delete'],
        'client' => ['label' => 'cliente', 'update' => 'clients.update', 'delete' => 'clients.delete'],
        'proposal' => ['label' => 'propuesta', 'update' => 'proposals.create', 'delete' => 'proposals.delete'],
        'contract' => ['label' => 'proyecto (servicio contratado)', 'update' => 'contracts.update', 'delete' => 'contracts.delete'],
        'invoice' => ['label' => 'cobro/boleta', 'update' => 'billing.manage', 'delete' => 'billing.delete'],
        'expense' => ['label' => 'gasto de un servicio', 'update' => null, 'delete' => 'contracts.costs'],
    ];

    public function __construct(private User $user) {}

    /** Campos editables por entidad y sus reglas de validación. @return array<string, array<int, mixed>> */
    public static function fields(string $entity): array
    {
        $n = ['nullable'];

        return match ($entity) {
            'lead' => [
                'first_name' => ['required', 'string', 'max:120'], 'last_name' => [...$n, 'string', 'max:120'], 'email' => [...$n, 'email:rfc', 'max:255'], 'phone' => [...$n, 'string', 'max:40'],
                'job_title' => [...$n, 'string', 'max:255'], 'company' => [...$n, 'string', 'max:255'], 'message' => [...$n, 'string', 'max:5000'],
                'client_id' => [...$n, 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')], 'priority' => [Rule::in(array_keys(Lead::PRIORITIES))],
                'estimated_amount' => [...$n, 'numeric', 'min:0'], 'estimated_currency' => [Rule::in(['CLP', 'UF'])], 'tags' => [...$n, 'string', 'max:300'], 'next_follow_up_at' => [...$n, 'date'],
            ],
            'client' => [
                'kind' => [Rule::in(array_keys(Client::KINDS))], 'name' => ['required', 'string', 'max:255'], 'legal_name' => [...$n, 'string', 'max:255'], 'activity' => [...$n, 'string', 'max:255'],
                'tax_id' => [...$n, 'string', 'max:20', fn ($a, $v, $fail) => $v && ! Rut::isValid($v) ? $fail('El RUT no es válido (revisa el dígito verificador).') : null],
                'email' => [...$n, 'email:rfc', 'max:255'], 'phone' => [...$n, 'string', 'max:40'], 'website' => [...$n, 'string', 'max:255'], 'address' => [...$n, 'string', 'max:255'],
                'commune' => [...$n, 'string', 'max:120'], 'city' => [...$n, 'string', 'max:120'], 'contact_name' => [...$n, 'string', 'max:255'], 'contact_role' => [...$n, 'string', 'max:255'],
                'notes' => [...$n, 'string', 'max:20000'], 'is_active' => ['boolean'],
            ],
            'proposal' => ['title' => ['required', 'string', 'max:255'], 'internal_notes' => [...$n, 'string', 'max:5000'], 'valid_until' => [...$n, 'date']],
            'contract' => [
                'name' => ['required', 'string', 'max:255'], 'description' => [...$n, 'string', 'max:5000'], 'billing_cycle' => [Rule::in(array_keys(ClientService::CYCLES))],
                'modality' => [Rule::in(array_keys(ClientService::MODALITIES))], 'project_address' => [...$n, 'string', 'max:255'],
                'currency' => [Rule::in(['CLP', 'UF'])], 'price' => ['numeric', 'min:0'], 'start_date' => ['date'], 'end_date' => [...$n, 'date'], 'auto_renew' => ['boolean'],
                'status' => [Rule::in(array_keys(ClientService::STATUSES))], 'billing_day' => [...$n, 'integer', 'between:1,31'], 'payment_link' => [...$n, 'url', 'max:500'], 'internal_notes' => [...$n, 'string', 'max:5000'],
            ],
            'invoice' => [
                'number' => [...$n, 'string', 'max:60'], 'concept' => ['required', 'string', 'max:255'], 'due_date' => ['date'], 'issue_date' => [...$n, 'date'], 'period_start' => [...$n, 'date'], 'period_end' => [...$n, 'date'],
                'currency' => [Rule::in(['CLP', 'UF'])], 'amount_net' => ['numeric', 'min:0'], 'document_type' => [Rule::in(array_keys(\App\Models\Invoice::DOCUMENTS))], 'retention_rate' => [...$n, 'numeric', 'between:0,100'], 'tax_rate' => ['numeric', 'between:0,100'], 'payment_link' => [...$n, 'url', 'max:500'], 'notes' => [...$n, 'string', 'max:3000'], 'auto_remind' => ['boolean'],
            ],
            default => [],
        };
    }

    public function find(string $entity, int $id): ?Model
    {
        return match ($entity) {
            'lead' => Lead::visibleTo($this->user)->find($id),
            'client' => Client::find($id),
            'proposal' => Proposal::visibleTo($this->user)->find($id),
            'contract' => ClientService::find($id),
            'invoice' => Invoice::find($id),
            'expense' => ServiceExpense::find($id),
            default => null,
        };
    }

    /**
     * Valida el pedido y arma lo que verá la persona. No cambia nada.
     *
     * @param  array<string, mixed>  $changes
     * @return array{entity: string, id: int, op: string, title: string, lines: list<string>, changes: array<string, mixed>, destructive: bool}
     */
    public function propose(string $op, string $entity, int $id, array $changes = []): array
    {
        $meta = self::ENTITIES[$entity] ?? throw ValidationException::withMessages(['entity' => 'Tipo de registro desconocido.']);
        $perm = $meta[$op === 'delete' ? 'delete' : 'update'];
        if (! $perm || ! $this->user->hasPermission($perm)) {
            throw ValidationException::withMessages(['permiso' => "El usuario no tiene permiso para ".($op === 'delete' ? 'eliminar' : 'editar')." {$meta['label']} ({$perm})."]);
        }
        $model = $this->find($entity, $id) ?? throw ValidationException::withMessages(['id' => ucfirst($meta['label'])." #{$id} no existe o no tienes acceso."]);

        if ($op === 'delete') {
            [$title, $lines] = $this->describeDelete($entity, $model);

            return ['entity' => $entity, 'id' => $id, 'op' => 'delete', 'title' => $title, 'lines' => $lines, 'changes' => [], 'destructive' => true];
        }

        $clean = $this->validateChanges($entity, $model, $changes);
        $lines = [];
        foreach ($clean as $field => $new) {
            $lines[] = $this->label($field).': '.$this->show($this->readable($entity, $field, $this->current($entity, $model, $field))).' → '.$this->show($this->readable($entity, $field, $new));
        }

        return ['entity' => $entity, 'id' => $id, 'op' => 'update', 'title' => 'Editar '.$meta['label'].' «'.$this->name($entity, $model).'»', 'lines' => $lines, 'changes' => $clean, 'destructive' => false];
    }

    /** Aplica una acción ya confirmada. Revalida permisos y visibilidad. */
    public function run(array $a): string
    {
        $entity = $a['entity'];
        $model = $this->find($entity, (int) $a['id']);
        $perm = self::ENTITIES[$entity][$a['op'] === 'delete' ? 'delete' : 'update'] ?? null;
        if (! $model || ! $perm || ! $this->user->hasPermission($perm)) {
            throw ValidationException::withMessages(['permiso' => 'Ya no tienes acceso a este registro o a esta acción.']);
        }

        return $a['op'] === 'delete' ? $this->doDelete($entity, $model) : $this->doUpdate($entity, $model, $a['changes']);
    }

    // ------------------------------------------------------------------ edición

    /** @param  array<string, mixed>  $changes @return array<string, mixed> */
    private function validateChanges(string $entity, Model $model, array $changes): array
    {
        $rules = self::fields($entity);
        if ($changes === []) {
            throw ValidationException::withMessages(['changes' => 'Indica qué campos cambiar.']);
        }
        $unknown = array_diff(array_keys($changes), array_keys($rules));
        if ($unknown) {
            throw ValidationException::withMessages(['campos' => 'Campos no editables: '.implode(', ', $unknown).'. Editables: '.implode(', ', array_keys($rules)).'.']);
        }
        if ($entity === 'invoice' && $model->status === 'cancelled') {
            throw ValidationException::withMessages(['estado' => 'Un cobro anulado no se edita.']);
        }

        $data = [];
        foreach ($changes as $f => $v) {
            $isBool = in_array('boolean', array_filter($rules[$f], 'is_string'), true);
            $v = is_string($v) ? trim($v) : $v;
            $data[$f] = $isBool ? in_array(strtolower((string) $v), ['1', 'true', 'si', 'sí', 'yes'], true) : ($v === '' ? null : $v);
        }

        $validated = Validator::make($data, array_intersect_key($rules, $data))->validate();

        if ($entity === 'client' && ! empty($validated['tax_id'])) {
            $validated['tax_id'] = Rut::format($validated['tax_id']);
            if (Client::where('tax_id', $validated['tax_id'])->whereKeyNot($model->getKey())->exists()) {
                throw ValidationException::withMessages(['tax_id' => 'Ya existe otro cliente con ese RUT.']);
            }
        }

        return $validated + array_diff_key($data, $validated); // conserva los null explícitos
    }

    /** @param  array<string, mixed>  $c */
    private function doUpdate(string $entity, Model $model, array $c): string
    {
        $validated = $this->validateChanges($entity, $model, $c); // vuelve a validar al confirmar

        switch ($entity) {
            case 'lead':
                $attrs = $validated;
                if (array_key_exists('tags', $attrs)) {
                    $attrs['tags'] = collect(explode(',', (string) $attrs['tags']))->map(fn ($t) => trim($t))->filter()->unique()->values()->all() ?: null;
                }
                if (array_key_exists('estimated_amount', $attrs) && ! isset($attrs['estimated_currency'])) {
                    $attrs['estimated_currency'] = $model->estimated_currency ?: 'CLP';
                }
                app(LeadService::class)->update($model, $attrs, $this->user);
                break;
            case 'contract':
                app(ContractService::class)->update($model, $validated);
                break;
            case 'invoice':
                app(InvoiceService::class)->update($model, $validated);
                break;
            default:
                $model->update($validated);
        }

        return 'Cambios aplicados en '.self::ENTITIES[$entity]['label'].' «'.$this->name($entity, $model->refresh()).'».';
    }

    // ---------------------------------------------------------------- eliminación

    /** @return array{0: string, 1: list<string>} */
    private function describeDelete(string $entity, Model $m): array
    {
        $name = $this->name($entity, $m);
        $title = 'Eliminar '.self::ENTITIES[$entity]['label'].' «'.$name.'»';
        $trash = 'Se envía a la Papelera (se puede restaurar).';

        $lines = match ($entity) {
            'lead' => [(string) $m->proposals()->count().' propuesta(s) asociada(s) también irán a la Papelera.', $trash],
            'client' => [
                $m->leads()->count().' cliente(s), '.$m->proposals()->count().' propuesta(s), '.$m->services()->count().' servicio(s) y '.$m->invoices()->count().' cobro(s) también irán a la Papelera.',
                'Sus accesos al portal quedan desactivados.', $trash,
            ],
            'proposal' => [$trash],
            'contract' => $this->contractDeleteLines($m),
            'invoice' => $this->invoiceDeleteLines($m),
            'expense' => ['Se elimina este gasto de forma definitiva: '.Money::format($m->amount, $m->currency).' · '.$m->concept.'.'],
            default => [],
        };

        return [$title, $lines];
    }

    /** @return list<string> */
    private function contractDeleteLines(ClientService $s): array
    {
        $this->assertContractDeletable($s);

        return [$s->invoices()->where('status', 'scheduled')->count().' cobro(s) por emitir también se eliminan.', 'Se envía a la Papelera (se puede restaurar).'];
    }

    private function assertContractDeletable(ClientService $s): void
    {
        if ($s->children()->exists() || $s->invoices()->whereIn('status', ['issued', 'paid'])->exists()) {
            throw ValidationException::withMessages(['servicio' => 'Tiene servicios asociados o facturas emitidas: no se elimina. Sugiere cambiar su estado a «Cancelado» o «Finalizado».']);
        }
    }

    /** @return list<string> */
    private function invoiceDeleteLines(Invoice $i): array
    {
        if ($i->status === 'paid') {
            throw ValidationException::withMessages(['estado' => 'Una factura pagada no se elimina: se puede anular o reabrir desde Facturación.']);
        }

        return [($i->number ?: 'Sin N°').' · '.Money::format($i->amount_total, $i->currency).' · vence '.$i->due_date->format('d-m-Y').'.', 'Se envía a la Papelera (se puede restaurar).'];
    }

    private function doDelete(string $entity, Model $m): string
    {
        $name = $this->name($entity, $m);

        match ($entity) {
            'lead' => app(CascadeDelete::class)->lead($m),
            'client' => app(CascadeDelete::class)->client($m),
            'proposal' => $m->delete(),
            'contract' => (function () use ($m) {
                $this->assertContractDeletable($m);
                $m->invoices()->where('status', 'scheduled')->get()->each->delete();
                $m->delete();
            })(),
            'invoice' => (function () use ($m) {
                $this->invoiceDeleteLines($m);
                $m->delete();
            })(),
            'expense' => $m->delete(),
        };

        return ucfirst(self::ENTITIES[$entity]['label'])." «{$name}» eliminado".($entity === 'expense' ? '.' : ' (está en la Papelera).');
    }

    // -------------------------------------------------------------------- helpers

    private function name(string $entity, Model $m): string
    {
        return match ($entity) {
            'lead' => $m->full_name, 'client' => $m->name, 'proposal' => $m->number.' · '.$m->title, 'contract' => $m->name,
            'invoice' => ($m->number ?: '#'.$m->id).' · '.$m->concept, 'expense' => $m->concept, default => '#'.$m->getKey(),
        };
    }

    private function current(string $entity, Model $m, string $field): mixed
    {
        if ($entity === 'lead' && $field === 'estimated_amount') {
            return $m->estimated_amount ?? $m->estimated_value;
        }
        if ($entity === 'lead' && $field === 'tags') {
            return implode(', ', $m->tags ?? []);
        }

        return $m->getAttribute($field);
    }

    /** Valores de lista con su nombre legible (p. ej. «blocked» → «Bloqueado»). */
    private function readable(string $entity, string $field, mixed $v): mixed
    {
        $map = match ([$entity, $field]) {
            ['contract', 'status'] => ClientService::STATUSES,
            ['contract', 'billing_cycle'] => ClientService::CYCLES,
            ['lead', 'priority'] => Lead::PRIORITIES,
            default => null,
        };

        return $map && is_string($v) && isset($map[$v]) ? $map[$v] : $v;
    }

    private function show(mixed $v): string
    {
        return match (true) {
            $v === null || $v === '' => '(vacío)',
            is_bool($v) => $v ? 'sí' : 'no',
            $v instanceof \DateTimeInterface => $v->format('d-m-Y'),
            default => '«'.mb_strimwidth((string) $v, 0, 90, '…').'»',
        };
    }

    private function label(string $field): string
    {
        return [
            'first_name' => 'Nombre', 'last_name' => 'Apellido', 'email' => 'Correo', 'phone' => 'Teléfono', 'job_title' => 'Cargo', 'company' => 'Empresa (texto)', 'message' => 'Mensaje',
            'client_id' => 'Cliente (id)', 'priority' => 'Prioridad', 'estimated_amount' => 'Valor estimado', 'estimated_currency' => 'Moneda del valor', 'tags' => 'Etiquetas', 'next_follow_up_at' => 'Próximo seguimiento',
            'name' => 'Nombre', 'legal_name' => 'Razón social', 'activity' => 'Giro', 'tax_id' => 'RUT', 'website' => 'Sitio web', 'address' => 'Dirección', 'commune' => 'Comuna', 'city' => 'Ciudad',
            'contact_name' => 'Contacto', 'contact_role' => 'Cargo del contacto', 'notes' => 'Notas', 'is_active' => 'Activo', 'title' => 'Título', 'internal_notes' => 'Notas internas', 'valid_until' => 'Válida hasta',
            'description' => 'Descripción', 'billing_cycle' => 'Ciclo de cobro', 'currency' => 'Moneda', 'price' => 'Honorarios', 'start_date' => 'Inicio', 'end_date' => 'Término', 'auto_renew' => 'Renovación automática',
            'status' => 'Estado', 'billing_day' => 'Día de cobro', 'payment_link' => 'Enlace de pago', 'number' => 'N° de documento', 'concept' => 'Concepto', 'due_date' => 'Vencimiento', 'issue_date' => 'Emisión',
            'period_start' => 'Período desde', 'period_end' => 'Período hasta', 'amount_net' => 'Honorarios brutos / neto', 'tax_rate' => 'IVA %', 'kind' => 'Tipo', 'modality' => 'Modalidad', 'project_address' => 'Dirección del proyecto', 'document_type' => 'Documento', 'retention_rate' => 'Retención %', 'auto_remind' => 'Recordatorios automáticos',
        ][$field] ?? $field;
    }
}
