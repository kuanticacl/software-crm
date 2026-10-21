<?php

namespace App\Services\Alfred;

use App\Models\Lead;
use App\Services\LeadService;
use Illuminate\Support\Facades\Cache;

/**
 * Crea (o completa) el cliente que Alfred captura en una conversación. Una conversación = un cliente:
 * si la persona primero deja sus datos y luego pide una reunión, se reutiliza el mismo registro.
 */
class AlfredLeads
{
    private const TTL_MINUTES = 180;

    public function __construct(private LeadService $leads) {}

    /** @param  array<string, mixed>  $d first_name, last_name, email, phone, company, job_title, interest */
    public function upsert(AlfredContext $ctx, array $d): Lead
    {
        $lead = $this->existing($ctx);
        $fields = collect($d)->only(['first_name', 'last_name', 'email', 'phone', 'company', 'job_title'])->filter(fn ($v) => filled($v))->all();

        if ($lead) {
            $changes = collect($fields)->filter(fn ($v, $k) => $v !== $lead->{$k})->all();
            if ($changes) {
                $this->leads->update($lead, $changes, null);
            }

            $lead->refresh();

            return $ctx->lead = $lead;
        }

        $version = (string) ($ctx->visitor['privacy_notice'] ?? 'sin versión');
        $now = now()->toIso8601String();
        $interest = trim((string) ($d['interest'] ?? ''));
        [$custom, $unknown] = $this->leads->normalizeCustom(array_filter([
            'origen' => 'alfred',
            'interes' => $interest !== '' ? $interest : null,
            'aviso_privacidad' => $version,
            'privacidad_aceptada' => $now,
        ]));

        $lead = $this->leads->create([
            ...$fields,
            'message' => 'Contacto captado por ' . AlfredSettings::name() . ', el asistente virtual del sitio web.'.($interest !== '' ? " Interés: {$interest}." : '')." [Consentimiento de privacidad aceptado · aviso {$version} · {$now}]",
            ...collect($ctx->visitor)->only(['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'user_agent', 'referrer', 'landing_url'])->filter(fn ($v) => filled($v))->all(),
            'ip_address' => $ctx->visitor['ip'] ?? null,
            'source_id' => $ctx->source->id,
            'custom' => $custom ?: null,
            'meta' => $unknown ? ['extra' => $unknown] : null,
        ], null, ['channel' => 'alfred', 'source' => $ctx->source->slug]);

        Cache::put($this->key($ctx), $lead->id, now()->addMinutes(self::TTL_MINUTES));
        $this->noteTranscript($lead, $ctx);

        return $ctx->lead = $lead;
    }

    /** Deja la conversación en una nota del cliente para que el equipo tenga el contexto (texto escapado). */
    public function noteTranscript(Lead $lead, AlfredContext $ctx): void
    {
        $lines = collect($ctx->transcript)->take(-12)->map(fn ($m) => ($m['role'] === 'assistant' ? AlfredSettings::name() : 'Visitante').': '.mb_substr((string) $m['content'], 0, 400));

        if ($lines->isNotEmpty()) {
            $lead->notes()->create(['body' => e("Conversación con ".AlfredSettings::name()." (últimos mensajes):\n".$lines->implode("\n")), 'is_private' => false, 'user_id' => null]);
        }
    }

    /** ¿Ya se solicitó una reunión en esta conversación? Evita solicitudes repetidas. */
    public function meetingRequested(AlfredContext $ctx): bool
    {
        return Cache::has($this->key($ctx).':meeting');
    }

    public function markMeeting(AlfredContext $ctx): void
    {
        Cache::put($this->key($ctx).':meeting', true, now()->addMinutes(self::TTL_MINUTES));
    }

    private function existing(AlfredContext $ctx): ?Lead
    {
        if ($ctx->lead) {
            return $ctx->lead;
        }
        $id = Cache::get($this->key($ctx));

        return $id ? Lead::where('id', $id)->where('source_id', $ctx->source->id)->first() : null;
    }

    private function key(AlfredContext $ctx): string
    {
        return "alfred:lead:{$ctx->source->id}:".sha1($ctx->session);
    }
}
