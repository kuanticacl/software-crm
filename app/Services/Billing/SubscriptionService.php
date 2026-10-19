<?php

namespace App\Services\Billing;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Proposal;
use App\Models\Subscription;
use App\Services\Proposals\ProposalCalculator;
use App\Support\BillingPeriod;
use App\Support\Rut;
use App\Support\TaxDocument;
use Illuminate\Support\Carbon;

class SubscriptionService
{
    public function __construct(private InvoiceService $invoices, private BillingAutomations $automations) {}

    /** Crea la suscripción de una propuesta aceptada con plan. Idempotente. */
    public function fromProposal(Proposal $p): ?Subscription
    {
        if (! $p->plan_id || ! BillingPeriod::valid($p->plan_period)) {
            return null;
        }
        if ($existing = Subscription::where('proposal_id', $p->id)->first()) {
            return $existing;
        }

        $plan = Plan::withTrashed()->with('prices')->find($p->plan_id);
        if (! $plan) {
            return null;
        }

        $client = $p->client ?? $this->clientFromRecipient($p);
        if ($client && $p->client_id !== $client->id) {
            $p->forceFill(['client_id' => $client->id])->saveQuietly();
        }
        if (! $client) {
            return null;
        }

        // Precio del plan tras el descuento de la propuesta.
        $net = (int) round(ProposalCalculator::totals(
            [['billing' => 'one_time', 'quantity' => 1, 'unit_price' => $this->priceOf($p, $plan), 'discount_pct' => 0]],
            (string) $p->discount_type, (float) $p->discount_value, null, 0.0, 0, 'none',
        )['total_net']);

        return $this->create([
            'client_id' => $client->id, 'plan_id' => $plan->id, 'proposal_id' => $p->id, 'period' => $p->plan_period, 'amount' => $net,
            'tax_rate' => $p->tax_mode === 'iva' ? (float) $p->tax_rate : 0.0,
            'auto_renew' => (bool) $p->auto_renew, 'started_at' => ($p->start_date ?? today())->toDateString(),
            'contract_text' => BillingSettings::contract(), 'created_by' => $p->user_id,
        ]);
    }

    /** Precio de lista del plan en el período de la propuesta (el que figura en la propuesta, no el actual si cambió). */
    private function priceOf(Proposal $p, Plan $plan): int
    {
        $item = $p->items->sortBy('sort_order')->first();

        return (int) round($item?->unit_price ?? ($plan->priceFor((string) $p->plan_period) ?? 0));
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Subscription
    {
        $start = Carbon::parse($data['started_at'])->startOfDay();
        $months = BillingPeriod::months($data['period']);

        $s = Subscription::create([
            ...$data,
            'currency' => 'CLP', 'status' => 'active',
            'tax_rate' => $data['tax_rate'] ?? TaxDocument::rate(TaxDocument::default()),
            'started_at' => $start,
            'current_period_start' => $start,
            'current_period_end' => $start->copy()->addMonthsNoOverflow($months),
            'contract_text' => $data['contract_text'] ?? BillingSettings::contract(),
        ]);

        $this->invoices->issueForPeriod($s, $start, $start->copy()->addDays(BillingSettings::firstDueDays()));
        $this->automations->fire('subscription.activated', $s);

        return $s;
    }

    private function clientFromRecipient(Proposal $p): ?Client
    {
        $r = $p->recipient ?? [];
        $name = trim((string) ($r['company'] ?? $r['legal_name'] ?? ''));
        if ($name === '') {
            return null;
        }

        if (! empty($r['rut']) && ($c = Client::where('tax_id', Rut::format($r['rut']))->first())) {
            return $c;
        }

        return Client::create([
            'name' => $name, 'legal_name' => $r['legal_name'] ?? null, 'tax_id' => ! empty($r['rut']) ? Rut::format($r['rut']) : null,
            'activity' => $r['activity'] ?? null, 'address' => $r['address'] ?? null, 'email' => $r['email'] ?? null, 'phone' => $r['phone'] ?? null,
            'contact_name' => $r['contact_name'] ?? null, 'contact_role' => $r['contact_role'] ?? null, 'is_active' => true, 'created_by' => $p->user_id,
        ]);
    }

    public function cancel(Subscription $s, ?string $reason = null): Subscription
    {
        $s->forceFill(['status' => 'cancelled', 'auto_renew' => false, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
        Invoice::where('subscription_id', $s->id)->where('status', 'pending')->where('period_start', '>', $s->current_period_start)->update(['status' => 'void']);

        return $s;
    }

    public function suspend(Subscription $s): Subscription
    {
        $s->forceFill(['status' => 'suspended', 'suspended_at' => now()])->save();

        return $s;
    }

    public function reactivate(Subscription $s): Subscription
    {
        $s->forceFill(['status' => 'active', 'suspended_at' => null, 'cancelled_at' => null, 'cancel_reason' => null])->save();

        return $this->refreshStatus($s);
    }

    /** «Con pago vencido» si hay un cobro pendiente atrasado; vuelve a «Activa» cuando se regulariza. Suspendida/cancelada/finalizada no cambian sola. */
    public function refreshStatus(Subscription $s, ?\Carbon\CarbonInterface $on = null): Subscription
    {
        if (! in_array($s->status, ['active', 'past_due'], true)) {
            return $s;
        }

        $limit = ($on ?? today())->copy()->subDays(BillingSettings::pastDueAfterDays());
        $late = Invoice::where('subscription_id', $s->id)->where('status', 'pending')->whereDate('due_date', '<', $limit)->exists();
        $status = $late ? 'past_due' : 'active';
        if ($s->status !== $status) {
            $s->forceFill(['status' => $status])->save();
        }

        return $s;
    }
}
