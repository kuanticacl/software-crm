<?php

namespace App\Services\Agent\Tools;

use App\Models\ClientService;
use App\Services\Billing\ContractService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Ai\Tools\Request;

class CreateContract extends CrmTool
{
    protected ?string $permission = 'contracts.create';

    public function name(): string
    {
        return 'create_contract';
    }

    public function description(): string
    {
        return 'Registra un PROYECTO/SERVICIO CONTRATADO por un cliente (client_id obligatorio, créala antes si no existe). Los servicios mensuales/trimestrales/anuales generan cobros programados; «one_time» no. Asocia propuesta (proposal_id) y servicio principal (parent_id: p. ej. el hosting o dominio de un diseño web). Valores NETOS.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->required(),
            'name' => $schema->string()->required(),
            'description' => $schema->string()->description('Descripción visible para el cliente en su portal.'),
            'billing_cycle' => $schema->string()->enum(['one_time', 'monthly', 'quarterly', 'yearly'])->required()->description('Casi siempre one_time (pago único).'),
            'modality' => $schema->string()->enum(['honorarios', 'honorarios_obra'])->description('Solo honorarios u honorarios + obra completa. Por defecto honorarios.'),
            'project_address' => $schema->string()->description('Dirección de la propiedad o proyecto.'),
            'currency' => $schema->string()->enum(['CLP', 'UF']),
            'price' => $schema->number()->required()->description('Honorarios totales (bruto de la boleta).'),
            'start_date' => $schema->string()->required()->description('YYYY-MM-DD'),
            'end_date' => $schema->string()->description('YYYY-MM-DD (opcional).'),
            'auto_renew' => $schema->boolean(),
            'billing_day' => $schema->integer()->description('Día del mes del cobro (1-31).'),
            'proposal_id' => $schema->integer(),
            'parent_id' => $schema->integer()->description('Id del servicio principal al que está asociado.'),
            'payment_link' => $schema->string(),
            'internal_notes' => $schema->string(),
        ];
    }

    protected function run(Request $r): array|string
    {
        $d = $r->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'],
            'billing_cycle' => ['required', Rule::in(array_keys(ClientService::CYCLES))], 'modality' => ['nullable', Rule::in(array_keys(ClientService::MODALITIES))], 'project_address' => ['nullable', 'string', 'max:255'], 'currency' => ['nullable', Rule::in(['CLP', 'UF'])],
            'price' => ['required', 'numeric', 'min:0'], 'start_date' => ['required', 'date'], 'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'auto_renew' => ['nullable', 'boolean'], 'billing_day' => ['nullable', 'integer', 'between:1,31'],
            'proposal_id' => ['nullable', 'integer', Rule::exists('proposals', 'id')->whereNull('deleted_at')],
            'parent_id' => ['nullable', 'integer', Rule::exists('client_services', 'id')->whereNull('deleted_at')->whereNull('parent_id')],
            'payment_link' => ['nullable', 'url', 'max:500'], 'internal_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if (! empty($d['parent_id']) && ClientService::find($d['parent_id'])?->client_id !== (int) $d['client_id']) {
            return 'ERROR: el servicio principal pertenece a otro cliente.';
        }

        $s = app(ContractService::class)->create([...$d, 'currency' => $d['currency'] ?? 'CLP', 'auto_renew' => (bool) ($d['auto_renew'] ?? false), 'status' => 'active'], $this->ctx->user);
        $this->ctx->record($this->name(), "Registró el servicio contratado «{$s->name}»", url('/contracts/'.$s->id));

        return ['id' => $s->id, 'servicio' => $s->name, 'proximo_cobro' => $s->next_charge_on?->toDateString(), 'url' => url('/contracts/'.$s->id)];
    }
}
