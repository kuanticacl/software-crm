<?php

namespace App\Services\Agent\Tools;

use App\Models\ClientService;
use App\Services\Billing\InvoiceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Ai\Tools\Request;

class CreateInvoice extends CrmTool
{
    protected ?string $permission = 'billing.manage';

    public function name(): string
    {
        return 'create_invoice';
    }

    public function description(): string
    {
        return 'Crea un COBRO (queda «por emitir»). El PDF de la boleta lo adjunta una persona desde «Cobros y boletas» (tú no puedes subir archivos): avísalo y entrega el enlace. Por defecto es BOLETA DE HONORARIOS: el monto es el BRUTO de honorarios, sin IVA; la retención vigente se calcula sola (si el cliente es empresa se descuenta del pago; si es persona paga el bruto). document_type=factura agrega IVA; kind=obra es un cobro de obra.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->required(),
            'concept' => $schema->string()->required(),
            'amount_net' => $schema->number()->required()->description('Honorarios brutos (boleta) o neto (factura).'),
            'currency' => $schema->string()->enum(['CLP', 'UF']),
            'document_type' => $schema->string()->enum(['boleta', 'factura', 'otro'])->description('Por defecto boleta (de honorarios).'),
            'kind' => $schema->string()->enum(['honorarios', 'obra'])->description('Por defecto honorarios.'),
            'due_date' => $schema->string()->required()->description('YYYY-MM-DD'),
            'client_service_id' => $schema->integer()->description('Servicio contratado asociado (opcional).'),
            'number' => $schema->string()->description('N° de boleta o factura si ya se conoce.'),
            'auto_remind' => $schema->boolean()->description('Recordatorios automáticos de pago (para cobros recurrentes).'),
            'payment_link' => $schema->string(),
        ];
    }

    protected function run(Request $r): array|string
    {
        $d = $r->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'concept' => ['required', 'string', 'max:255'], 'amount_net' => ['required', 'numeric', 'min:0'], 'currency' => ['nullable', Rule::in(['CLP', 'UF'])],
            'document_type' => ['nullable', Rule::in(['boleta', 'factura', 'otro'])], 'kind' => ['nullable', Rule::in(['honorarios', 'obra'])],
            'due_date' => ['required', 'date'], 'client_service_id' => ['nullable', 'integer', Rule::exists('client_services', 'id')->whereNull('deleted_at')],
            'number' => ['nullable', 'string', 'max:60'], 'auto_remind' => ['nullable', 'boolean'], 'payment_link' => ['nullable', 'url', 'max:500'],
        ]);
        if (! empty($d['client_service_id']) && ClientService::find($d['client_service_id'])?->client_id !== (int) $d['client_id']) {
            return 'ERROR: el servicio pertenece a otro cliente.';
        }

        $i = app(InvoiceService::class)->create([...$d, 'currency' => $d['currency'] ?? 'CLP', 'document_type' => $d['document_type'] ?? 'boleta', 'kind' => $d['kind'] ?? 'honorarios', 'auto_remind' => (bool) ($d['auto_remind'] ?? false)], $this->ctx->user);
        $this->ctx->record($this->name(), "Creó el cobro «{$i->concept}»", url('/billing?status=scheduled'));

        return ['id' => $i->id, 'estado' => 'Por emitir (falta adjuntar el PDF en Cobros y boletas)', 'documento' => $i->documentLabel(), 'honorarios_brutos' => $i->amount_net, 'retencion' => $i->retention_amount, 'a_cobrar' => $i->amount_total, 'url' => url('/billing?status=scheduled')];
    }
}
