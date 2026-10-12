<?php

namespace App\Services\Agent\Tools;

use App\Models\Lead;
use App\Models\Proposal;
use App\Services\Proposals\ProposalBuilder;
use App\Support\Rut;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class CreateProposal extends CrmTool
{
    protected ?string $permission = 'proposals.create';

    public function name(): string
    {
        return 'create_proposal';
    }

    public function description(): string
    {
        return 'Crea una propuesta comercial en BORRADOR con servicios, precios netos y secciones de texto. Los totales los calcula el servidor. '
            .'Asocia client_id (cliente) y/o lead_id (prospecto) existentes (créalos antes si faltan). No la envía al cliente. '
            .'Condiciones de pago (cuotas, hitos) van en la sección «Condiciones comerciales»; los montos de cada servicio en items.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required()->description('Título de la propuesta.'),
            'client_id' => $schema->integer()->description('Cliente existente (id de search_clients).'),
            'lead_id' => $schema->integer()->description('Prospecto existente (opcional).'),
            'contact_name' => $schema->string()->description('Contacto destinatario.'),
            'contact_role' => $schema->string(),
            'contact_email' => $schema->string(),
            'contact_phone' => $schema->string(),
            'currency' => $schema->string()->enum(['UF', 'CLP'])->description('UF por defecto; CLP si el texto habla de pesos.'),
            'valid_until' => $schema->string()->description('Vigencia YYYY-MM-DD (opcional).'),
            'contract_months' => $schema->integer()->description('Meses de contrato si hay servicios mensuales.'),
            'discount_type' => $schema->string()->enum(['percent', 'amount']),
            'discount_value' => $schema->number(),
            'tax_mode' => $schema->string()->enum(array_keys(\App\Support\TaxDocument::MODES))->description('Documento tributario. Omítelo para usar el del estudio («'.\App\Support\TaxDocument::MODES[\App\Support\TaxDocument::default()].'»); cámbialo solo si el texto lo pide.'),
            'items' => $schema->array()->items($schema->object([
                'service_id' => $schema->integer()->description('ID del catálogo si corresponde.'),
                'name' => $schema->string()->required(),
                'description' => $schema->string(),
                'deliverables' => $schema->array()->items($schema->string()),
                'billing' => $schema->string()->enum(['one_time', 'monthly'])->required(),
                'quantity' => $schema->number(),
                'unit_price' => $schema->number()->required()->description('Precio NETO unitario en la moneda de la propuesta.'),
                'discount_pct' => $schema->integer(),
            ]))->required(),
            'sections' => $schema->array()->items($schema->object([
                'title' => $schema->string()->required(),
                'body' => $schema->string()->required()->description('Texto; admite listas con "- " y **negrita**.'),
            ]))->description('Secciones: Resumen del proyecto, Objetivos, Alcance, Etapas y plazos, Condiciones comerciales… Si se omiten se usan las estándar.'),
            'internal_notes' => $schema->string()->description('Notas internas (no las ve el cliente).'),
        ];
    }

    protected function run(Request $r): array|string
    {
        $user = $this->ctx->user;
        if ($r['lead_id'] && ! Lead::visibleTo($user)->whereKey((int) $r['lead_id'])->exists()) {
            return 'ERROR: prospecto no encontrado o sin acceso.';
        }

        $data = $r->validate([
            'title' => ['required', 'string', 'max:200'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'], 'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'currency' => ['nullable', 'in:UF,CLP'], 'valid_until' => ['nullable', 'date'],
            'contract_months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'discount_type' => ['nullable', 'in:percent,amount'], 'discount_value' => ['nullable', 'numeric', 'min:0'],
            'tax_mode' => ['nullable', 'in:'.implode(',', array_keys(\App\Support\TaxDocument::MODES))],
            'items' => ['required', 'array', 'min:1', 'max:40'],
            'items.*.name' => ['required', 'string', 'max:200'], 'items.*.billing' => ['required', 'in:one_time,monthly'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'], 'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'sections' => ['nullable', 'array', 'max:15'],
        ]);

        $client = ! empty($data['client_id']) ? \App\Models\Client::find($data['client_id']) : null;
        $lead = ! empty($data['lead_id']) ? Lead::find($data['lead_id']) : null;
        $recipient = array_filter([
            ...ProposalBuilder::recipientFor($client, $lead),
            'contact_name' => $r['contact_name'] ?: ($client?->contact_name ?: $lead?->full_name),
            'contact_role' => $r['contact_role'] ?: $client?->contact_role,
            'email' => $r['contact_email'] ?: ($client?->email ?: $lead?->email),
            'phone' => $r['contact_phone'] ?: ($client?->phone ?: $lead?->phone),
        ]);

        $payload = [
            ...$data,
            'recipient' => $recipient,
            'currency' => $data['currency'] ?? 'UF',
            'discount_type' => $data['discount_type'] ?? 'percent',
            'tax_mode' => $data['tax_mode'] ?? null,
            'items' => collect($r['items'])->map(fn ($i) => [...$i, 'quantity' => $i['quantity'] ?? 1])->all(),
            'sections' => ! empty($r['sections']) ? $r['sections'] : $this->defaultSections($client?->name ?: ($lead?->company ?: 'el cliente')),
            'internal_notes' => $r['internal_notes'] ?? null,
        ];

        $p = app(ProposalBuilder::class)->save($payload, null, $user->id);
        if ($p->lead) {
            app(\App\Services\LeadService::class)->log($p->lead, 'proposal', $user, "Propuesta {$p->number} creada (borrador) por el Agent", ['proposal_id' => $p->id]);
        }
        $this->ctx->record($this->name(), "Creó la propuesta {$p->number} · {$p->title}", url('/proposals/'.$p->id));

        return [
            'id' => $p->id, 'number' => $p->number, 'status' => 'draft', 'currency' => $p->currency,
            'total_net' => $p->total_net, 'total_tax' => $p->total_tax, 'total_gross' => $p->total_gross, 'totals_labels' => $p->taxLabels(), 'url' => url('/proposals/'.$p->id),
            'note' => 'Borrador creado; no se envió. Para enviarla usa el botón «Enviar por correo» en la ficha.',
        ];
    }

    /** @return array<int, array{title: string, body: string}> */
    private function defaultSections(string $client): array
    {
        return collect(ProposalBuilder::defaultSections())->map(fn ($s) => [...$s, 'body' => str_replace('[CLIENTE]', $client, $s['body'])])->all();
    }
}
