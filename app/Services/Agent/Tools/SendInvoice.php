<?php

namespace App\Services\Agent\Tools;

use App\Models\Invoice;
use App\Services\Billing\InvoiceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class SendInvoice extends CrmTool
{
    protected ?string $permission = 'billing.manage';

    public function name(): string
    {
        return 'send_invoice';
    }

    public function description(): string
    {
        return 'Envía por correo el COBRO (o un recordatorio si ya se envió) de una factura «por pagar» a los contactos con acceso al portal del cliente. Es una acción hacia el cliente: confirma con el usuario antes de ejecutarla.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['invoice_id' => $schema->integer()->required()];
    }

    protected function run(Request $r): array|string
    {
        $i = Invoice::find((int) $r['invoice_id']);
        if (! $i) {
            return 'ERROR: cobro no encontrado.';
        }
        $n = app(InvoiceService::class)->send($i, $i->reminders()->where('kind', 'issued')->exists() ? 'manual' : 'issued');
        $this->ctx->record($this->name(), "Envió el cobro ".($i->number ?: '#'.$i->id)." a {$n} contacto(s)", url('/billing'));

        return "OK: cobro enviado a {$n} contacto(s).";
    }
}
