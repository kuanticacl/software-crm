<?php

namespace App\Services\Email;

use App\Models\Automation;
use App\Models\EmailSuppression;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Services\Leads\LeadAssignment;
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

    /**
     * Aviso interno: un correo a cada integrante del equipo vinculado al cliente (responsable asignado, responsables
     * del origen y correos por defecto de «Orígenes»), sin repetir direcciones y sin seguimiento de aperturas.
     *
     * @param  array<string, mixed>  $vars
     */
    private function runForTeam(Automation $a, Lead $lead, EmailTemplate $template, array $vars): void
    {
        $recipients = app(LeadAssignment::class)->recipients($lead);
        $vars = [...$vars, ...$this->teamVariables($lead)];
        $queued = 0;

        foreach ($recipients as $email => $name) {
            if (EmailSuppression::isSuppressed($email)) {
                continue;
            }

            $this->emails->queueTemplate($template, $email, $name, [...$vars, 'recipient_name' => $name ? (explode(' ', trim($name))[0] ?: $name) : 'equipo'], [
                'subject' => $a->subject ?: null,
                'lead_id' => $lead->id,
                'automation_id' => $a->id,
                'delay_minutes' => $a->delay_minutes,
                'track_opens' => false,
                'track_clicks' => false,
            ]);
            $queued++;
        }

        if ($queued > 0) {
            $a->forceFill(['runs_count' => $a->runs_count + 1, 'last_run_at' => now()])->saveQuietly();
        }
    }

    /** Variables de la alerta: datos del cliente con prefijo «lead_» para no chocar con las del destinatario. @return array<string, string> */
    private function teamVariables(Lead $lead): array
    {
        return [
            'lead_name' => (string) ($lead->full_name ?: 'Sin nombre'),
            'lead_email' => (string) $lead->email,
            'lead_phone' => (string) $lead->phone,
            'lead_company' => (string) $lead->company,
            'lead_job_title' => (string) $lead->job_title,
            'lead_message' => mb_substr((string) $lead->message, 0, 600),
            'lead_source' => (string) ($lead->source?->name ?? ''),
            'lead_stage' => (string) ($lead->stage?->name ?? ''),
            'lead_url' => url('/leads/'.$lead->id),
            'assignee_name' => (string) ($lead->assignee?->name ?? 'Sin asignar'),
            'lead_created_at' => ($lead->created_at ?? now())->timezone('America/Santiago')->format('d-m-Y H:i'),
        ];
    }

    /** @param array<string, mixed> $context */
    public function run(Automation $a, Lead $lead, array $context = []): void
    {
        $template = $a->template;
        if (! $template || ! $template->is_active) {
            return;
        }

        $lead->loadMissing(['source:id,name', 'stage:id,name', 'assignee:id,name,email']);

        $defaults = collect($template->variables ?? [])->filter(fn ($v) => ($v['default'] ?? '') !== '')->mapWithKeys(fn ($v) => [$v['key'] => $v['default']])->all();
        $vars = [
            ...$defaults,
            ...($a->variables ?? []),               // valores fijos de la automatización (por defecto)
            ...($lead->meta['extra'] ?? []),        // datos recibidos por la API de leads
            ...AudienceBuilder::leadVariables($lead),
            ...$context,
        ];

        if ($a->to_mode === 'team') {
            $this->runForTeam($a, $lead, $template, $vars);

            return;
        }

        $to = $a->to_mode === 'fixed' ? $a->to_email : $lead->email;
        if (! $to || ! filter_var($to, FILTER_VALIDATE_EMAIL) || EmailSuppression::isSuppressed($to)) {
            return;
        }

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
