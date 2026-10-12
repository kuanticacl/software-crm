<?php

namespace App\Services\Billing;

use App\Models\Client;
use App\Models\ClientService;
use App\Models\Invoice;
use App\Models\ServiceExpense;
use App\Services\UfService;
use Illuminate\Support\Collection;

/**
 * Lectura de la salud de las cuentas (clientes con servicios contratados): ingreso recurrente, cobranza, renovaciones y
 * el estado automático que ordena el Kanban de cuentas. Todo en pesos (los montos en UF se convierten con la UF de hoy).
 */
class AccountInsights
{
    /** Columnas del Kanban de cuentas, de más a menos urgente. */
    public const COLUMNS = [
        'overdue' => ['name' => 'En mora', 'color' => '#EF4444', 'hint' => 'Tienen facturas vencidas'],
        'to_issue' => ['name' => 'Por facturar', 'color' => '#FFA165', 'hint' => 'Cobro generado sin factura (PDF)'],
        'due' => ['name' => 'Por cobrar', 'color' => '#4A8CFF', 'hint' => 'Facturas emitidas pendientes de pago'],
        'renewal' => ['name' => 'Renovación próxima', 'color' => '#A855F7', 'hint' => 'Servicios que terminan en 60 días sin renovación automática'],
        'ok' => ['name' => 'Al día', 'color' => '#A9606B', 'hint' => 'Servicios activos sin pendientes'],
        'pending' => ['name' => 'Por iniciar', 'color' => '#38BDF8', 'hint' => 'Contratados que aún no parten'],
        'inactive' => ['name' => 'Sin servicios activos', 'color' => '#94A3B8', 'hint' => 'Bloqueados, cancelados o finalizados'],
    ];

    private float $uf;

    public function __construct()
    {
        $this->uf = (float) (app(UfService::class)->today()['value'] ?? 0);
    }

    /** Valor mensual equivalente (neto, en pesos) de un servicio recurrente. */
    public function monthlyClp(ClientService $s): float
    {
        if (! $s->isRecurring()) {
            return 0;
        }
        $clp = $s->currency === 'UF' ? $s->price * $this->uf : $s->price;

        return match ($s->billing_cycle) { 'monthly' => $clp, 'quarterly' => $clp / 3, 'yearly' => $clp / 12, default => 0 };
    }

    /** @return array<string, mixed> */
    public function dashboard(bool $withBilling, bool $withCosts): array
    {
        $services = ClientService::with('client:id,name')->whereNotIn('status', ['cancelled'])->get();
        $active = $services->filter(fn (ClientService $s) => $s->isLive());

        $mrr = $active->sum(fn (ClientService $s) => $this->monthlyClp($s));
        $byClient = $active->groupBy('client_id')->map(fn (Collection $g) => [
            'client' => $g->first()->client?->only(['id', 'name']), 'mrr' => round($g->sum(fn ($s) => $this->monthlyClp($s))), 'services' => $g->count(),
        ])->sortByDesc('mrr')->values();

        $renewals = $active->filter(fn (ClientService $s) => $s->end_date && $s->end_date->between(today(), today()->addDays(90)))
            ->sortBy('end_date')->values()->map(fn (ClientService $s) => [
                'id' => $s->id, 'name' => $s->name, 'client' => $s->client?->only(['id', 'name']), 'end_date' => $s->end_date->toDateString(),
                'auto_renew' => $s->auto_renew, 'days' => (int) today()->diffInDays($s->end_date, false), 'price' => $s->price, 'currency' => $s->currency,
            ]);

        $data = [
            'kpis' => [
                'mrr' => round($mrr), 'arr' => round($mrr * 12),
                'accounts_active' => $byClient->count(),
                'services_active' => $active->count(),
                'avg_per_account' => $byClient->count() ? round($mrr / $byClient->count()) : 0,
                'renewals_30' => $renewals->filter(fn ($r) => $r['days'] <= 30 && ! $r['auto_renew'])->count(),
                'accounts_total' => $services->pluck('client_id')->unique()->count(),
            ],
            'top_accounts' => $byClient->take(6)->map(fn ($a) => [...$a, 'share' => $mrr > 0 ? round($a['mrr'] / $mrr * 100, 1) : 0])->all(),
            'renewals' => $renewals->take(10)->all(),
            'without_services' => Client::where('is_active', true)->whereDoesntHave('services', fn ($q) => $q->whereIn('status', ['active', 'pending_payment']))
                ->whereHas('invoices')->limit(8)->get(['id', 'name'])->all(),
            'cycle_mix' => $active->groupBy('billing_cycle')->map(fn ($g, $k) => ['cycle' => $k, 'count' => $g->count(), 'mrr' => round($g->sum(fn ($s) => $this->monthlyClp($s)))])->values()->all(),
            'billing' => null,
            'costs' => null,
        ];

        if ($withBilling) {
            $data['billing'] = $this->billing();
        }
        if ($withCosts) {
            $year = now()->subMonths(11)->startOfMonth();
            $billed = (float) Invoice::whereIn('status', ['issued', 'paid'])->where('due_date', '>=', $year)->sum('net_clp');
            $exp = (float) ServiceExpense::where('incurred_on', '>=', $year)->sum('amount_clp');
            $data['costs'] = ['billed_12m' => round($billed), 'expenses_12m' => round($exp), 'margin_12m' => round($billed - $exp), 'margin_pct' => $billed > 0 ? round(($billed - $exp) / $billed * 100, 1) : null];
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function billing(): array
    {
        $issued = Invoice::with('client:id,name')->where('status', 'issued')->get();
        $overdue = $issued->filter(fn (Invoice $i) => $i->isOverdue());

        $aging = ['0-30' => 0.0, '31-60' => 0.0, '61+' => 0.0];
        foreach ($overdue as $i) {
            $d = (int) $i->due_date->diffInDays(today());
            $aging[$d <= 30 ? '0-30' : ($d <= 60 ? '31-60' : '61+')] += (float) $i->total_clp;
        }

        $months = collect(range(5, 0))->map(fn ($n) => now()->subMonths($n)->startOfMonth());
        $series = $months->map(function ($m) {
            $end = $m->copy()->endOfMonth();

            return [
                'month' => $m->toDateString(),
                'billed' => round((float) Invoice::whereIn('status', ['issued', 'paid'])->whereBetween('due_date', [$m, $end])->sum('net_clp')),
                'collected' => round((float) Invoice::where('status', 'paid')->whereBetween('paid_at', [$m, $end])->sum('net_clp')),
            ];
        })->all();

        return [
            'receivable' => round((float) $issued->sum('total_clp')),
            'overdue' => round((float) $overdue->sum('total_clp')),
            'overdue_count' => $overdue->count(),
            'collected_month' => round((float) Invoice::where('status', 'paid')->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('total_clp')),
            'to_issue' => Invoice::where('status', 'scheduled')->count(),
            'aging' => collect($aging)->map(fn ($v, $k) => ['bucket' => $k, 'amount' => round($v)])->values()->all(),
            'series' => $series,
            'overdue_list' => $overdue->sortBy('due_date')->take(8)->values()->map(fn (Invoice $i) => [
                'id' => $i->id, 'number' => $i->number, 'concept' => $i->concept, 'client' => $i->client?->only(['id', 'name']),
                'due_date' => $i->due_date->toDateString(), 'days' => (int) $i->due_date->diffInDays(today()), 'currency' => $i->currency, 'amount' => $i->amount_total, 'total_clp' => $i->total_clp,
            ])->all(),
            'to_issue_list' => Invoice::with('client:id,name')->where('status', 'scheduled')->orderBy('due_date')->limit(8)->get()->map(fn (Invoice $i) => [
                'id' => $i->id, 'concept' => $i->concept, 'client' => $i->client?->only(['id', 'name']), 'due_date' => $i->due_date->toDateString(), 'days' => (int) today()->diffInDays($i->due_date, false),
            ])->all(),
        ];
    }

    /**
     * Cuentas del Kanban: una tarjeta por cliente con servicios (o cobros), en la columna que corresponda por su situación.
     *
     * @return array<string, mixed>
     */
    public function kanban(?string $q = null): array
    {
        $clients = Client::query()
            ->where(fn ($w) => $w->whereHas('services')->orWhereHas('invoices'))
            ->when($q, fn ($w, $t) => $w->where(fn ($x) => $x->where('name', 'like', "%{$t}%")->orWhere('legal_name', 'like', "%{$t}%")->orWhere('tax_id', 'like', "%{$t}%")))
            ->with([
                'services' => fn ($s) => $s->where('status', '!=', 'cancelled'),
                'invoices' => fn ($i) => $i->whereIn('status', ['scheduled', 'issued']),
            ])->orderBy('name')->get();

        $cards = $clients->map(function (Client $c) {
            $active = $c->services->filter(fn (ClientService $s) => $s->isLive());
            $issued = $c->invoices->where('status', 'issued');
            $overdue = $issued->filter(fn (Invoice $i) => $i->isOverdue());
            $scheduled = $c->invoices->where('status', 'scheduled');
            $renewal = $active->filter(fn (ClientService $s) => ! $s->auto_renew && $s->end_date && $s->end_date->between(today(), today()->addDays(60)))->sortBy('end_date')->first();
            $toIssue = $scheduled->filter(fn (Invoice $i) => $i->due_date->lte(today()->addDays(10)));

            $column = match (true) {
                $overdue->isNotEmpty() => 'overdue',
                $toIssue->isNotEmpty() => 'to_issue',
                $issued->isNotEmpty() || $c->services->contains(fn (ClientService $s) => $s->status === 'pending_payment') => 'due',
                $renewal !== null => 'renewal',
                $active->isNotEmpty() => 'ok',
                $c->services->contains(fn (ClientService $s) => $s->effectiveStatus() === 'pending') => 'pending',
                default => 'inactive',
            };

            return [
                'column' => $column,
                'id' => $c->id, 'name' => $c->name, 'tax_id' => $c->tax_id,
                'mrr' => round($active->sum(fn ($s) => $this->monthlyClp($s))),
                'services_active' => $active->count(),
                'services' => $active->take(3)->pluck('name')->all(),
                'pending_clp' => round((float) $issued->sum('total_clp')),
                'overdue_clp' => round((float) $overdue->sum('total_clp')),
                'overdue_days' => $overdue->isNotEmpty() ? (int) $overdue->min('due_date')->diffInDays(today()) : null,
                'next_due' => $issued->sortBy('due_date')->first()?->due_date?->toDateString(),
                'to_issue' => $toIssue->count(),
                'renewal' => $renewal ? ['name' => $renewal->name, 'date' => $renewal->end_date->toDateString(), 'days' => (int) today()->diffInDays($renewal->end_date, false)] : null,
            ];
        });

        $columns = collect(self::COLUMNS)->map(function ($meta, $key) use ($cards) {
            $items = $cards->where('column', $key)->sortByDesc(fn ($c) => $key === 'overdue' ? ($c['overdue_days'] ?? 0) : $c['mrr'])->values();

            return [...$meta, 'key' => $key, 'total' => $items->count(), 'mrr' => round($items->sum('mrr')), 'amount' => round($items->sum(fn ($c) => $key === 'overdue' ? $c['overdue_clp'] : $c['pending_clp'])), 'cards' => $items->all()];
        })->values()->all();

        return ['columns' => $columns, 'totals' => ['accounts' => $cards->count(), 'mrr' => round($cards->sum('mrr'))]];
    }

    /**
     * Pipeline de cobros: por emitir → por pagar → vencidas → pagadas (últimos 30 días).
     *
     * @return list<array<string, mixed>>
     */
    public function invoicePipeline(?string $q = null): array
    {
        $base = fn () => Invoice::with(['client:id,name', 'service:id,name'])
            ->when($q, fn ($w, $t) => $w->where(fn ($x) => $x->where('number', 'like', "%{$t}%")->orWhere('concept', 'like', "%{$t}%")->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$t}%"))));

        $present = fn (Invoice $i) => app(\App\Http\Controllers\Billing\InvoiceController::class)->present($i);
        $defs = [
            ['key' => 'scheduled', 'name' => 'Por emitir', 'color' => '#FFA165', 'q' => $base()->where('status', 'scheduled')->orderBy('due_date')],
            ['key' => 'issued', 'name' => 'Por pagar', 'color' => '#4A8CFF', 'q' => $base()->where('status', 'issued')->whereDate('due_date', '>=', today())->orderBy('due_date')],
            ['key' => 'overdue', 'name' => 'Vencidas', 'color' => '#EF4444', 'q' => $base()->overdue()->orderBy('due_date')],
            ['key' => 'paid', 'name' => 'Pagadas (30 días)', 'color' => '#A9606B', 'q' => $base()->where('status', 'paid')->where('paid_at', '>=', now()->subDays(30))->orderByDesc('paid_at')],
        ];

        return collect($defs)->map(function ($d) use ($present) {
            $all = $d['q']->limit(60)->get();

            return ['key' => $d['key'], 'name' => $d['name'], 'color' => $d['color'], 'total' => $all->count(), 'amount' => round((float) $all->sum('total_clp')), 'cards' => $all->map($present)->values()->all()];
        })->all();
    }
}
