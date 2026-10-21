<?php

namespace App\Services\AgentWeb;

use App\Services\Ai\AiGateway;
use App\Services\AgentWeb\Tools\RequestMeeting;
use App\Services\AgentWeb\Tools\SaveContact;
use Laravel\Ai\Messages\Message;

use function Laravel\Ai\agent;

/**
 * Agente web (el nombre lo define cada empresa). Es un modelo con DOS herramientas de escritura (guardar contacto y
 * solicitar reunión) y sin ninguna de lectura: no puede consultar clientes, propuestas ni datos internos del CRM.
 * Lo único que sabe es el prompt, el conocimiento autorizado y los datos públicos de contacto.
 */
class AgentWebAgent
{

    public function __construct(private AiGateway $ai) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $history  mensajes previos (sin el último)
     * @return array{reply: string, actions: array<int, array{tool: string, ok: bool}>}
     */
    public function chat(AgentWebContext $ctx, array $history, string $message): array
    {
        $messages = collect($history)->map(fn ($m) => new Message($m['role'] === 'assistant' ? 'assistant' : 'user', (string) $m['content']))->all();
        $agent = agent(instructions: AgentWebSettings::instructions(), messages: $messages, tools: self::tools($ctx));

        $response = $this->ai->run('agentweb', $agent, $message, [], 60);

        return ['reply' => trim((string) $response) ?: 'Perdona, tuve un problema para responder.'.(($m = (string) (\App\Support\Agency::profile()['email'] ?? '')) !== '' ? " Puedes escribirnos a {$m} y el equipo te ayudará." : ''), 'actions' => $ctx->actions];
    }

    /** Las únicas herramientas de AgentWeb. Un test verifica que no se agreguen herramientas de lectura. */
    public static function tools(AgentWebContext $ctx): array
    {
        return [new SaveContact($ctx), new RequestMeeting($ctx)];
    }
}
