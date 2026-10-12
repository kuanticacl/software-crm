<?php

namespace App\Services\Email;

use App\Models\Automation;
use App\Models\EmailSuppression;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;

/** Ejecuta las automatizaciones de email cuando ocurre un evento de lead. Nunca interrumpe el flujo del lead. */
class AutomationRunner
{
    public function __construct(private EmailService $emails) {}

    /**
     * @param  array<string, mixed>  $context  variables extra del evento (p. ej. etapa anterior)
     * @param  string|null  $channel  «api» (web/formularios), «api+template» (la web ya pidió su plantilla) o «manual»; por dónde llegó el cliente al crearse: «api» (web/formularios) o «manual»
     */
    public function fire(string $trigger, Lead $lead, array $context = [], ?string $channel = null): void
    {
        try {
            $automations = Automation::where('trigger', $trigger)->where('is_active', true)->with('template')->get();

            foreach ($automations as $automation) {
                if (! $this->matches($automation, $lead, $channel)) {
                    continue;
                }

                $this->run($automation, $lead, $context);
            }
        } catch (\Throwable $e) {
            Log::error('Automatización de email falló: '.$e->getMessage(), ['lead' => $lead->id, 'trigger' => $trigger]);
        }
    }

    public function matches(Automation $a, Lead $lead, ?string $channel = null): bool
    {
        $c = $a->conditions ?? [];

        // «Solo registros de la web»: no se escribe a quienes el equipo ingresa a mano.
        if (! empty($c['web_only']) && $channel !== null && $channel !== 'api') {
            return false;
        }

        if (! empty($c['source_ids']) && ! in_array($lead->source_id, array_map('intval', $c['source_ids']), true)) {
            return false;
        }

        if (! empty($c['stage_ids']) && ! in_array($lead->stage_id, array_map('intval', $c['stage_ids']), true)) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $context */
    public function run(Automation $a, Lead $lead, array $context = []): void
    {
        $template = $a->template;
        if (! $template || ! $template->is_active) {
            return;
        }

        $to = $a->to_mode === 'fixed' ? $a->to_email : $lead->email;
        if (! $to || ! filter_var($to, FILTER_VALIDATE_EMAIL) || EmailSuppression::isSuppressed($to)) {
            return;
        }

        $lead->loadMissing(['source:id,name', 'stage:id,name']);

        $defaults = collect($template->variables ?? [])->filter(fn ($v) => ($v['default'] ?? '') !== '')->mapWithKeys(fn ($v) => [$v['key'] => $v['default']])->all();
        $vars = [
            ...$defaults,
            ...($a->variables ?? []),               // valores fijos de la automatización (por defecto)
            ...($lead->meta['extra'] ?? []),        // datos recibidos por la API de leads
            ...AudienceBuilder::leadVariables($lead),
            ...$context,
        ];

        $this->emails->queueTemplate($template, $to, $a->to_mode === 'fixed' ? null : $lead->full_name, $vars, [
            'subject' => $a->subject ?: null,
            'lead_id' => $lead->id,
            'automation_id' => $a->id,
            'delay_minutes' => $a->delay_minutes,
            'track_opens' => $template->category === 'marketing',
            'track_clicks' => $template->category === 'marketing',
        ]);

        $a->forceFill(['runs_count' => $a->runs_count + 1, 'last_run_at' => now()])->saveQuietly();
    }
}
