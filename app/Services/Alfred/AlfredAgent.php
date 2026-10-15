<?php

namespace App\Services\Alfred;

use App\Services\Ai\AiGateway;
use App\Services\Alfred\Tools\RequestMeeting;
use App\Services\Alfred\Tools\SaveContact;
use Laravel\Ai\Messages\Message;

use function Laravel\Ai\agent;

/**
 * Alfred: ejecutivo virtual del sitio web. Es un modelo con DOS herramientas de escritura (guardar contacto y
 * solicitar reunión) y sin ninguna de lectura: no puede consultar clientes, propuestas ni datos internos del CRM.
 * Lo único que sabe es el prompt, el conocimiento autorizado y los datos públicos de contacto.
 */
class AlfredAgent
{
    public const FALLBACK = 'Perdona, tuve un problema para responder. Puedes escribirnos a contacto@quiebre.cl y el equipo te ayudará.';

    public function __construct(private AiGateway $ai) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $history  mensajes previos (sin el último)
     * @return array{reply: string, actions: array<int, array{tool: string, ok: bool}>}
     */
    public function chat(AlfredContext $ctx, array $history, string $message): array
    {
        $messages = collect($history)->map(fn ($m) => new Message($m['role'] === 'assistant' ? 'assistant' : 'user', (string) $m['content']))->all();
        $agent = agent(instructions: AlfredSettings::instructions(), messages: $messages, tools: self::tools($ctx));

        $response = $this->ai->run('alfred', $agent, $message, [], 60);

        return ['reply' => trim((string) $response) ?: self::FALLBACK, 'actions' => $ctx->actions];
    }

    /** Las únicas herramientas de Alfred. Un test verifica que no se agreguen herramientas de lectura. */
    public static function tools(AlfredContext $ctx): array
    {
        return [new SaveContact($ctx), new RequestMeeting($ctx)];
    }
}
