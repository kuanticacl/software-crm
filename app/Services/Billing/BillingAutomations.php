<?php

namespace App\Services\Billing;

use App\Models\Automation;
use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Dispara las automatizaciones de email de cobranza (las mismas de Email → Automatizaciones). Nunca interrumpe el flujo de cobro. */
class BillingAutomations
{
    /** Evento inmediato (cobro emitido, pago recibido, suscripción iniciada). */
    public function fire(string $trigger, Invoice|Subscription $subject): void
    {
        $this->each($trigger, $subject, fn (Automation $a) => $this->deliver($a, $subject, "{$trigger}:{$subject->getKey()}"));
    }

    /** Evento programado por días: lo ejecuta el runner diario; el registro de envíos evita repetirlo. */
    public function fireScheduled(Automation $a, Invoice|Subscription $subject, string $day): bool
    {
        return $this->deliver($a, $subject, ($subject instanceof Invoice ? 'i' : 's').$subject->getKey().':'.$day);
    }

    private function each(string $trigger, Invoice|Subscription $subject, callable $fn): void
    {
        try {
            $subject->loadMissing('client');
            foreach (Automation::where('trigger', $trigger)->where('is_active', true)->with('template')->get() as $a) {
                if ($this->matches($a, $subject)) {
                    $fn($a);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Automatización de cobranza falló: '.$e->getMessage(), ['trigger' => $trigger]);
        }
    }

    public function matches(Automation $a, Invoice|Subscription $subject): bool
    {
        $planIds = array_map('intval', $a->conditions['plan_ids'] ?? []);
        if (! $planIds) {
            return true;
        }
        $sub = $subject instanceof Invoice ? $subject->subscription : $subject;

        return $sub && in_array($sub->plan_id, $planIds, true);
    }

    private function deliver(Automation $a, Invoice|Subscription $subject, string $key): bool
    {
        try {
            $inserted = DB::table('automation_deliveries')->insertOrIgnore(['automation_id' => $a->id, 'dedupe_key' => $key, 'sent_at' => now()]);
            if (! $inserted) {
                return false;
            }
            $subject->loadMissing('client');
            $a->loadMissing('template');

            return app(BillingMailer::class)->send($a, $subject) > 0;
        } catch (\Throwable $e) {
            Log::error('Envío de cobranza falló: '.$e->getMessage(), ['automation' => $a->id]);

            return false;
        }
    }
}
