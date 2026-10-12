<?php

namespace App\Services\Agent\Tools;

use App\Models\ClientService;
use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class BillingOverview extends CrmTool
{
    protected ?string $permission = 'billing.view';

    public function name(): string
    {
        return 'billing_overview';
    }

    public function description(): string
    {
        return 'Resumen de cobranza: cobros por emitir (sin PDF), por pagar, vencidos y cobrado del mes (en pesos), servicios activos y los próximos vencimientos.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $r): array|string
    {
        $this->ctx->record($this->name(), 'Consultó el resumen de cobranza', url('/billing'));

        return [
            'por_emitir' => Invoice::where('status', 'scheduled')->count(),
            'por_pagar' => ['cantidad' => Invoice::where('status', 'issued')->count(), 'total_clp' => Money::format(Invoice::where('status', 'issued')->sum('total_clp'))],
            'vencidas' => ['cantidad' => Invoice::overdue()->count(), 'total_clp' => Money::format(Invoice::overdue()->sum('total_clp'))],
            'cobrado_este_mes_clp' => Money::format(Invoice::where('status', 'paid')->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('total_clp')),
            'servicios_activos' => ClientService::where('status', 'active')->count(),
            'proximos_vencimientos' => Invoice::with('client:id,name')->where('status', 'issued')->orderBy('due_date')->limit(5)->get()
                ->map(fn (Invoice $i) => ['id' => $i->id, 'cliente' => $i->client?->name, 'concepto' => $i->concept, 'vence' => $i->due_date->toDateString(), 'total' => Money::format($i->amount_total, $i->currency)])->all(),
            'url' => url('/billing'),
        ];
    }
}
