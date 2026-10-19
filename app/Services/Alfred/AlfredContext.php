<?php

namespace App\Services\Alfred;

use App\Models\Lead;
use App\Models\LeadSource;

/** Contexto de una conversación de Scarlett: origen autenticado, datos del visitante y acciones ejecutadas. */
class AlfredContext
{
    public ?Lead $lead = null;

    /** @var array<int, array{tool: string, ok: bool}> */
    public array $actions = [];

    /**
     * @param  array<string, mixed>  $visitor  ip, user_agent, referrer, landing_url, utm_*, privacy_notice
     * @param  array<int, array{role: string, content: string}>  $transcript  mensajes de la conversación (incluye el último)
     */
    public function __construct(
        public readonly LeadSource $source,
        public readonly array $visitor,
        public readonly string $session,
        public readonly array $transcript,
    ) {}

    public function record(string $tool, bool $ok = true): void
    {
        $this->actions[] = ['tool' => $tool, 'ok' => $ok];
    }
}
