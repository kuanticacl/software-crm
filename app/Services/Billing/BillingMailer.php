<?php

namespace App\Services\Billing;

use App\Models\Automation;
use App\Models\Client;
use App\Models\EmailSuppression;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Email\EmailService;
use App\Support\BillingPeriod;

/** Arma las variables y envía las plantillas de cobranza que disparan las automatizaciones. */
class BillingMailer
{
    public function __construct(private EmailService $emails) {}

    /** @return array<int, array{email: string, name: string}> */
    public function recipients(Client $client, Automation $a): array
    {
        if ($a->to_mode === 'fixed' && $a->to_email) {
            return [['email' => $a->to_email, 'name' => 'Equipo']];
        }

        return $client->email ? [['email' => $client->email, 'name' => $client->contact_name ?: $client->name]] : [];
    }

    /** Devuelve cuántos correos se pusieron en cola. */
    public function send(Automation $a, Invoice|Subscription $subject): int
    {
        $template = $a->template;
        if (! $template || ! $template->is_active) {
            return 0;
        }

        $client = $subject->client;
        $count = 0;

        foreach ($this->recipients($client, $a) as $r) {
            if (! filter_var($r['email'], FILTER_VALIDATE_EMAIL) || EmailSuppression::isSuppressed($r['email'])) {
                continue;
            }
            $defaults = collect($template->variables ?? [])->filter(fn ($v) => ($v['default'] ?? '') !== '')->mapWithKeys(fn ($v) => [$v['key'] => $v['default']])->all();
            $vars = [...$defaults, ...($a->variables ?? []), ...$this->variables($subject, $r['name'])];

            $this->emails->queueTemplate($template, $r['email'], $r['name'], $vars, [
                'subject' => $a->subject ?: null, 'client_id' => $client->id, 'automation_id' => $a->id,
                'delay_minutes' => $a->delay_minutes, 'track_opens' => false, 'track_clicks' => false,
            ]);
            $count++;
        }

        if ($count) {
            $a->forceFill(['runs_count' => $a->runs_count + 1, 'last_run_at' => now()])->saveQuietly();
            if ($subject instanceof Invoice) {
                $subject->forceFill(['sent_at' => now()])->saveQuietly();
            }
        }

        return $count;
    }

    /** @return array<string, mixed> */
    public function variables(Invoice|Subscription $subject, string $name): array
    {
        $sub = $subject instanceof Invoice ? $subject->subscription : $subject;
        $sub?->loadMissing('plan');
        $client = $subject->client;

        $vars = [
            'first_name' => explode(' ', trim($name))[0] ?? '',
            'company' => $client->name,
            'customer_id' => $client->customer_id,
            'plan_name' => $sub?->plan?->name ?? '',
            'period' => $sub ? $sub->periodLabel() : '',
            'auto_renew' => $sub ? ($sub->auto_renew ? 'Sí' : 'No') : '',
            'renewal_date' => $sub ? $sub->current_period_end->format('d-m-Y') : '',
            'renewal_price' => $sub ? self::money($sub->gross()).' (IVA incl.)' : '',
            'bank_details' => BankAccounts::html(),
        ];

        if ($subject instanceof Invoice) {
            $days = (int) today()->diffInDays($subject->due_date, false); // >0 faltan; <0 atrasada
            [$headline, $message] = $this->wording($subject, $days);
            $vars += [
                'invoice_number' => $subject->number,
                'concept' => $subject->concept,
                'amount_net' => self::money($subject->amount_net),
                'amount_total' => self::money($subject->amount_total),
                'due_date' => $subject->due_date->format('d-m-Y'),
                'payment_link' => (string) $subject->payment_link,
                'headline' => $headline,
                'message' => $message,
            ];
        }

        return $vars;
    }

    /** @return array{0: string, 1: string} */
    private function wording(Invoice $i, int $days): array
    {
        $n = $i->number;
        $due = $i->due_date->format('d-m-Y');

        if ($i->status === 'paid') {
            return ['Pago recibido', "recibimos el pago del cobro {$n}. ¡Gracias!"];
        }
        if ($days > 1) {
            return ["Tu cobro vence en {$days} días", "te recordamos que el cobro {$n} vence el {$due}."];
        }
        if ($days === 1) {
            return ['Tu cobro vence mañana', "te recordamos que el cobro {$n} vence mañana ({$due})."];
        }
        if ($days === 0) {
            return ['Tu cobro vence hoy', "te recordamos que el cobro {$n} vence hoy ({$due})."];
        }
        $late = abs($days) === 1 ? '1 día' : abs($days).' días';

        return ["Tu cobro está vencido hace {$late}", "el cobro {$n} venció el {$due} y aún figura pendiente. Regularízalo para mantener tu servicio activo."];
    }

    public static function money(int $n): string
    {
        return '$'.number_format($n, 0, ',', '.');
    }
}
