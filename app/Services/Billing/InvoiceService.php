<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Subscription;
use App\Support\BillingPeriod;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function __construct(private BillingAutomations $automations) {}

    /** Emite el cobro de un período de la suscripción (idempotente por suscripción + inicio de período). */
    public function issueForPeriod(Subscription $s, CarbonInterface $periodStart, CarbonInterface $dueDate, ?CarbonInterface $issuedAt = null): Invoice
    {
        $existing = Invoice::where('subscription_id', $s->id)->whereDate('period_start', $periodStart)->where('status', '!=', 'void')->first();
        if ($existing) {
            return $existing;
        }

        $end = $periodStart->copy()->addMonthsNoOverflow(BillingPeriod::months($s->period));
        $net = $s->net();
        $tax = (int) round($net * $s->tax_rate / 100);
        $s->loadMissing('plan');

        $invoice = DB::transaction(fn () => Invoice::create([
            'number' => Invoice::nextNumber(),
            'subscription_id' => $s->id,
            'client_id' => $s->client_id,
            'concept' => "Suscripción MecaniQ · Plan {$s->plan->name} ({$s->periodLabel()}) · {$periodStart->format('d-m-Y')} al {$end->copy()->subDay()->format('d-m-Y')}",
            'period_start' => $periodStart, 'period_end' => $end,
            'currency' => $s->currency, 'amount_net' => $net, 'tax_rate' => $s->tax_rate, 'amount_tax' => $tax, 'amount_total' => $net + $tax,
            'issued_at' => ($issuedAt ?? today())->toDateString(), 'due_date' => $dueDate->toDateString(),
            'status' => 'pending',
        ]));

        $this->automations->fire('invoice.issued', $invoice);

        return $invoice;
    }

    /** @param array{method?: ?string, reference?: ?string, paid_at?: ?string, document_number?: ?string} $data */
    public function markPaid(Invoice $invoice, array $data = []): Invoice
    {
        if ($invoice->status === 'paid') {
            return $invoice;
        }

        $invoice->forceFill([
            'status' => 'paid',
            'paid_at' => ! empty($data['paid_at']) ? Carbon::parse($data['paid_at']) : now(),
            'payment_method' => $data['method'] ?? null,
            'payment_reference' => $data['reference'] ?? null,
            'document_number' => $data['document_number'] ?? $invoice->document_number,
        ])->save();

        if ($s = $invoice->subscription) {
            app(SubscriptionService::class)->refreshStatus($s);
        }

        $this->automations->fire('invoice.paid', $invoice->fresh());

        return $invoice;
    }

    public function void(Invoice $invoice): Invoice
    {
        if ($invoice->status === 'pending') {
            $invoice->forceFill(['status' => 'void'])->save();
            if ($s = $invoice->subscription) {
                app(SubscriptionService::class)->refreshStatus($s);
            }
        }

        return $invoice;
    }
}
