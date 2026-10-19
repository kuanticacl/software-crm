<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\LimitType;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionService;
use App\Support\BillingPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subs) {}

    public function index(Request $request): Response
    {
        $filters = ['q' => (string) $request->input('q', ''), 'status' => (string) $request->input('status', ''), 'plan' => (string) $request->input('plan', '')];

        $rows = Subscription::query()->with(['client:id,name,customer_id', 'plan:id,name'])
            ->when($filters['status'], fn ($q, $v) => $q->where('status', $v))
            ->when($filters['plan'], fn ($q, $v) => $q->where('plan_id', $v))
            ->when($filters['q'], fn ($q, $t) => $q->whereHas('client', fn ($c) => $c->where('name', 'like', "%{$t}%")->orWhere('customer_id', 'like', "%{$t}%")->orWhere('tax_id', 'like', "%{$t}%")))
            ->orderBy('current_period_end')->paginate(20)->withQueryString();

        $rows->getCollection()->transform(fn (Subscription $s) => $this->row($s));

        $live = Subscription::whereIn('status', ['active', 'past_due']);
        $mrr = (int) round((clone $live)->get(['amount', 'period'])->sum(fn ($s) => $s->amount / BillingPeriod::months($s->period)));

        return Inertia::render('billing/Subscriptions', [
            'subscriptions' => $rows,
            'filters' => $filters,
            'statuses' => Subscription::STATUSES,
            'plans' => Plan::orderBy('sort_order')->get(['id', 'name']),
            'kpis' => [
                'active' => (clone $live)->count(),
                'mrr' => $mrr,
                'past_due' => Subscription::where('status', 'past_due')->count(),
                'pending_amount' => (int) Invoice::where('status', 'pending')->sum('amount_total'),
                'overdue_amount' => (int) Invoice::overdue()->sum('amount_total'),
                'renewing' => Subscription::whereIn('status', ['active', 'past_due'])->whereDate('current_period_end', '<=', today()->addDays(30))->count(),
            ],
            'formData' => [
                'clients' => Client::where('is_active', true)->orderBy('name')->get(['id', 'name', 'customer_id']),
                'plans' => Plan::where('is_active', true)->with('prices')->orderBy('sort_order')->get()->map(fn (Plan $p) => [
                    'id' => $p->id, 'name' => $p->name, 'prices' => $p->prices->where('is_active', true)->mapWithKeys(fn ($pr) => [$pr->period => (int) $pr->amount])->all(),
                ]),
                'periods' => BillingPeriod::LABELS,
            ],
            'can' => ['manage' => $request->user()->hasPermission('billing.manage')],
        ]);
    }

    public function show(Request $request, Subscription $subscription): Response
    {
        $subscription->load(['client', 'plan.limits', 'plan.prices', 'proposal:id,number,title,public_token', 'invoices' => fn ($q) => $q->orderByDesc('period_start')->orderByDesc('id')]);

        return Inertia::render('billing/SubscriptionShow', [
            'subscription' => [
                ...$subscription->toApi(),
                'db_id' => $subscription->id,
                'client' => $subscription->client->only(['id', 'name', 'legal_name', 'tax_id', 'email', 'customer_id']),
                'notes' => $subscription->notes,
                'cancel_reason' => $subscription->cancel_reason,
                'proposal' => $subscription->proposal?->only(['id', 'number', 'title']),
                'limit_overrides' => $subscription->limit_overrides ?? [],
                'contract_text' => $subscription->contract_text,
            ],
            'invoices' => $subscription->invoices->map(fn (Invoice $i) => ['id' => $i->id, ...$i->toApi()]),
            'limitTypes' => LimitType::orderBy('sort_order')->get(['id', 'key', 'name', 'unit']),
            'planLimits' => $subscription->plan->limits->mapWithKeys(fn ($l) => [$l->key => $l->pivot->value === null ? null : (int) $l->pivot->value]),
            'plans' => Plan::where('is_active', true)->with('prices')->orderBy('sort_order')->get()->map(fn (Plan $p) => [
                'id' => $p->id, 'name' => $p->name, 'prices' => $p->prices->where('is_active', true)->mapWithKeys(fn ($pr) => [$pr->period => (int) $pr->amount])->all(),
            ]),
            'periods' => BillingPeriod::LABELS,
            'statuses' => Subscription::STATUSES,
            'methods' => Invoice::METHODS,
            'can' => ['manage' => $request->user()->hasPermission('billing.manage')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')],
            'period' => ['required', Rule::in(array_keys(BillingPeriod::LABELS))],
            'amount' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'auto_renew' => ['boolean'],
            'started_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['client_id.required' => 'Elige la empresa.', 'plan_id.required' => 'Elige un plan.']);

        $plan = Plan::with('prices')->findOrFail($data['plan_id']);
        $price = $data['amount'] ?? $plan->priceFor($data['period']);
        if ($price === null) {
            return back()->withErrors(['period' => 'El plan no tiene precio para ese período.']);
        }

        $s = $this->subs->create([...collect($data)->except('amount')->all(), 'amount' => (int) $price, 'created_by' => $request->user()->id]);
        $this->toast('Suscripción creada y primer cobro emitido.');

        return to_route('billing.subscriptions.show', $s);
    }

    public function update(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $request->validate([
            'auto_renew' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'limit_overrides' => ['nullable', 'array'],
            'limit_overrides.*' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'limit_unlimited' => ['nullable', 'array'],
            'limit_unlimited.*' => ['boolean'],
            'amount' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'plan_id' => ['nullable', 'integer', Rule::exists('plans', 'id')],
            'period' => ['nullable', Rule::in(array_keys(BillingPeriod::LABELS))],
        ]);

        $overrides = [];
        foreach ($data['limit_overrides'] ?? [] as $key => $v) {
            if ($v !== null && $v !== '') {
                $overrides[$key] = (int) $v;
            }
        }
        foreach ($data['limit_unlimited'] ?? [] as $key => $on) {
            if ($on) {
                $overrides[$key] = null;
            }
        }

        $subscription->fill([
            'auto_renew' => $data['auto_renew'] ?? $subscription->auto_renew,
            'notes' => $data['notes'] ?? null,
            'limit_overrides' => $overrides ?: null,
        ]);
        // Cambio de plan, período o precio: rige desde el próximo cobro (los ya emitidos no se tocan).
        if (! empty($data['plan_id'])) {
            $subscription->plan_id = $data['plan_id'];
        }
        if (! empty($data['period'])) {
            $subscription->period = $data['period'];
        }
        if (isset($data['amount'])) {
            $subscription->amount = (int) $data['amount'];
        }
        $subscription->save();

        $this->toast('Suscripción actualizada. Los cambios de plan, período o precio rigen desde el próximo cobro.');

        return back();
    }

    public function cancel(Request $request, Subscription $subscription): RedirectResponse
    {
        $this->subs->cancel($subscription, $request->input('reason'));
        $this->toast('Suscripción cancelada. Los cobros futuros pendientes se anularon.');

        return back();
    }

    public function suspend(Subscription $subscription): RedirectResponse
    {
        $this->subs->suspend($subscription);
        $this->toast('Suscripción suspendida: la API informa que no tiene acceso.');

        return back();
    }

    public function reactivate(Subscription $subscription): RedirectResponse
    {
        $this->subs->reactivate($subscription);
        $this->toast('Suscripción reactivada.');

        return back();
    }

    /** @return array<string, mixed> */
    private function row(Subscription $s): array
    {
        return [
            'id' => $s->id, 'uid' => $s->uid, 'status' => $s->status, 'period' => $s->period, 'amount' => $s->amount, 'total' => $s->gross(), 'auto_renew' => $s->auto_renew,
            'current_period_end' => $s->current_period_end->toDateString(), 'started_at' => $s->started_at->toDateString(),
            'client' => $s->client?->only(['id', 'name', 'customer_id']), 'plan' => $s->plan?->only(['id', 'name']),
        ];
    }
}
