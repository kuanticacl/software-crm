<?php

namespace App\Services\Billing;

use App\Models\Automation;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Support\BillingPeriod;
use Carbon\CarbonInterface;

/**
 * Tarea diaria de cobranza: emite los cobros de renovación, avanza los períodos, marca suscripciones con pago vencido
 * y ejecuta las automatizaciones por días (antes/después del vencimiento, antes de la renovación).
 */
class BillingRunner
{
    /** Cuántos días hacia atrás se tolera que el cron no haya corrido (el registro de envíos evita repetir). */
    private const CATCH_UP_DAYS = 1;

    public function __construct(private InvoiceService $invoices, private SubscriptionService $subs, private BillingAutomations $automations) {}

    /** @return array{issued: int, renewed: int, expired: int, emails: int} */
    public function run(?CarbonInterface $today = null): array
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $out = ['issued' => 0, 'renewed' => 0, 'expired' => 0, 'emails' => 0];

        Subscription::whereIn('status', ['active', 'past_due'])->with('plan')->each(function (Subscription $s) use ($today, &$out) {
            // 1) Cobro de la renovación, con anticipación configurable.
            if ($s->auto_renew && $today->gte($s->current_period_end->copy()->subDays(BillingSettings::issueDaysBefore()))) {
                $before = Invoice::where('subscription_id', $s->id)->count();
                $this->invoices->issueForPeriod($s, $s->current_period_end->copy(), $s->current_period_end->copy(), $today);
                $out['issued'] += Invoice::where('subscription_id', $s->id)->count() > $before ? 1 : 0;
            }

            // 2) Al llegar la fecha: renueva el período o finaliza la suscripción.
            if ($today->gte($s->current_period_end)) {
                if ($s->auto_renew) {
                    $start = $s->current_period_end->copy();
                    $s->forceFill(['current_period_start' => $start, 'current_period_end' => $start->copy()->addMonthsNoOverflow(BillingPeriod::months($s->period))])->save();
                    $out['renewed']++;
                } else {
                    $s->forceFill(['status' => 'expired'])->save();
                    $out['expired']++;
                }
            }

            $this->subs->refreshStatus($s->fresh(), $today);
        });

        $out['emails'] = $this->runAutomations($today);

        return $out;
    }

    private function runAutomations(CarbonInterface $today): int
    {
        $sent = 0;

        foreach (Automation::whereIn('trigger', Automation::OFFSET_TRIGGERS)->where('is_active', true)->with('template')->get() as $a) {
            $offset = (int) ($a->offset_days ?? 0); // negativo = antes; positivo = después

            if ($a->trigger === 'invoice.due') {
                // Objetivo: cobros cuyo vencimiento + offset cae hoy (o en los últimos días, si el cron se saltó uno).
                $to = $today->copy()->subDays($offset);
                Invoice::where('status', 'pending')->whereDate('due_date', '<=', $to)->whereDate('due_date', '>=', $to->copy()->subDays(self::CATCH_UP_DAYS))
                    ->with(['client', 'subscription'])->each(function (Invoice $i) use ($a, &$sent) {
                        if ($this->automations->matches($a, $i) && $this->automations->fireScheduled($a, $i, 'due'.$a->offset_days)) {
                            $sent++;
                        }
                    });
            } else {
                $to = $today->copy()->subDays($offset);
                Subscription::whereIn('status', ['active', 'past_due'])->whereDate('current_period_end', '<=', $to)->whereDate('current_period_end', '>=', $to->copy()->subDays(self::CATCH_UP_DAYS))
                    ->with(['client', 'plan'])->each(function (Subscription $s) use ($a, &$sent) {
                        if ($this->automations->matches($a, $s) && $this->automations->fireScheduled($a, $s, $s->current_period_end->toDateString().'r'.$a->offset_days)) {
                            $sent++;
                        }
                    });
            }
        }

        return $sent;
    }
}
