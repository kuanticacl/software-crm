<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeadSource;
use App\Services\Ai\AiFailed;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiNotConfigured;
use App\Services\Alfred\AlfredAgent;
use App\Services\Alfred\AlfredContext;
use App\Services\Alfred\AlfredSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * POST /api/v1/alfred/chat — conversación del asistente virtual con un visitante del sitio. Se autentica con la API key del
 * origen (X-Api-Key), igual que el ingreso de leads. La conversación NO se guarda en el servidor: el sitio envía
 * los mensajes previos en cada llamada y aquí se validan (solo roles user/assistant, largo acotado).
 */
class AlfredChatController extends Controller
{

    /** GET /api/v1/alfred/config — el sitio muestra al asistente solo si está activo y con IA disponible. */
    public function config(AiGateway $ai): JsonResponse
    {
        return response()->json(['data' => [
            'enabled' => AlfredSettings::enabled() && $ai->isAvailable(),
            'greeting' => AlfredSettings::greeting(),
        ]]);
    }

    public function chat(Request $request, AlfredAgent $alfred, AiGateway $ai): JsonResponse
    {
        if (! AlfredSettings::enabled() || ! $ai->isAvailable()) {
            return response()->json(['message' => AlfredSettings::unavailable()], 503);
        }

        /** @var LeadSource $source */
        $source = $request->attributes->get('lead_source');

        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:'.AlfredSettings::MAX_MESSAGES],
            'messages.*.role' => ['required', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:'.AlfredSettings::MAX_CHARS],
            'session' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
            'ip' => ['nullable', 'ip'],
            'user_agent' => ['nullable', 'string', 'max:1000'],
            'referrer' => ['nullable', 'string', 'max:2000'],
            'landing_url' => ['nullable', 'string', 'max:2000'],
            'privacy_notice' => ['nullable', 'string', 'max:60'],
            ...collect(['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'])->mapWithKeys(fn ($k) => [$k => ['nullable', 'string', 'max:255']])->all(),
        ]);

        $messages = collect($data['messages'])->map(fn ($m) => ['role' => $m['role'], 'content' => $this->clean($m['content'])])->all();
        $last = array_pop($messages);
        if ($last['role'] !== 'user' || $last['content'] === '') {
            return response()->json(['message' => 'El último mensaje debe ser de la persona.'], 422);
        }

        $ctx = new AlfredContext(
            $source,
            collect($data)->only(['ip', 'user_agent', 'referrer', 'landing_url', 'privacy_notice', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'])->all(),
            $data['session'],
            [...$messages, $last],
        );

        try {
            $result = $alfred->chat($ctx, $messages, $last['content']);
        } catch (AiNotConfigured|AiFailed) {
            return response()->json(['message' => AlfredSettings::unavailable()], 503);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => AlfredSettings::unavailable()], 500);
        }

        $ok = collect($result['actions'])->where('ok', true)->pluck('tool');

        return response()->json(['data' => [
            'reply' => $result['reply'],
            'contact_saved' => $ok->contains('save_contact') || $ok->contains('request_meeting'),
            'meeting_requested' => $ok->contains('request_meeting'),
        ]]);
    }

    /** Quita caracteres de control y espacios sobrantes (el texto nunca se interpreta como HTML ni instrucción). */
    private function clean(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text));
    }
}
