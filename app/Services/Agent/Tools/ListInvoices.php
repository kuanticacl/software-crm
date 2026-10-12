<?php

namespace App\Services\Agent\Tools;

use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class ListInvoices extends CrmTool
{
    protected ?string $permission = 'billing.view';

    public function name(): string
    {
        return 'list_invoices';
    }

    public function description(): string
    {
        return 'Lista cobros/facturas. status: pending (por pagar), overdue (vencidas), scheduled (por emitir: falta el PDF), paid, cancelled o all. Filtra por cliente (client_id) o texto (número, concepto).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(['pending', 'overdue', 'scheduled', 'paid', 'cancelled', 'all']),
            'client_id' => $schema->integer(),
            'query' => $schema->string(),
            'limit' => $schema->integer(),
        ];
    }

    protected function run(Request $r): array|string
    {
        $status = $r['status'] ?: 'pending';
        $rows = Invoice::query()->with(['client:id,name', 'service:id,name'])
            ->when($r['client_id'], fn ($q, $c) => $q->where('client_id', (int) $c))
            ->when($r['query'], fn ($q, $t) => $q->where(fn ($w) => $w->where('number', 'like', "%$t%")->orWhere('concept', 'like', "%$t%")))
            ->when($status !== 'all', fn ($q) => match ($status) {
                'pending' => $q->where('status', 'issued'), 'overdue' => $q->overdue(), default => $q->where('status', $status),
            })
            ->orderBy('due_date')->limit($this->limit($r, 10, 30))->get();
        $this->ctx->record($this->name(), "Consultó cobros ({$status}): {$rows->count()}", url('/billing'));

        return $rows->map(fn (Invoice $i) => [
            'id' => $i->id, 'cliente' => $i->client?->name, 'numero' => $i->number, 'concepto' => $i->concept, 'servicio' => $i->service?->name,
            'total' => Money::format($i->amount_total, $i->currency), 'vence' => $i->due_date->toDateString(), 'estado' => Invoice::STATUSES[$i->status] ?? $i->status,
            'vencida' => $i->isOverdue(), 'tiene_pdf' => $i->hasPdf(), 'url' => url('/billing?status=&q='.urlencode((string) ($i->number ?: $i->concept))),
        ])->all() ?: 'Sin resultados.';
    }
}
