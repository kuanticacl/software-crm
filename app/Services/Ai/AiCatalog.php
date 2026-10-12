<?php

namespace App\Services\Ai;

/**
 * Proveedores de IA que se pueden configurar desde el CRM. Cada uno se conecta con un "driver" del
 * SDK oficial (laravel/ai); MiniMax y cualquier gateway compatible usan el driver «openai-compatible».
 */
class AiCatalog
{
    /** @var array<string, array<string, mixed>> */
    public const PROVIDERS = [
        'anthropic' => [
            'name' => 'Claude (Anthropic)', 'driver' => 'anthropic', 'url' => 'https://api.anthropic.com/v1', 'url_editable' => false,
            'keys_url' => 'https://console.anthropic.com/settings/keys', 'key_hint' => 'sk-ant-…', 'color' => '#D97757',
            'description' => 'Excelente redacción en español y seguimiento de instrucciones.',
            'suggest' => ['claude-sonnet-5-5', 'claude-opus-5-5', 'claude-haiku-5-5'],
        ],
        'openai' => [
            'name' => 'OpenAI', 'driver' => 'openai', 'url' => 'https://api.openai.com/v1', 'url_editable' => false,
            'keys_url' => 'https://platform.openai.com/api-keys', 'key_hint' => 'sk-…', 'color' => '#10A37F',
            'description' => 'Modelos GPT. Usa «Cargar modelos» para ver los disponibles en tu cuenta.', 'suggest' => [],
        ],
        'openrouter' => [
            'name' => 'OpenRouter', 'driver' => 'openrouter', 'url' => 'https://openrouter.ai/api/v1', 'url_editable' => false,
            'keys_url' => 'https://openrouter.ai/keys', 'key_hint' => 'sk-or-…', 'color' => '#6467F2',
            'description' => 'Una sola key para cientos de modelos (Claude, GPT, Gemini, Llama, MiniMax…). Usa el id completo, p. ej. proveedor/modelo.', 'suggest' => [],
        ],
        'minimax' => [
            'name' => 'MiniMax', 'driver' => 'openai-compatible', 'url' => 'https://api.minimax.io/v1', 'url_editable' => true,
            'keys_url' => 'https://platform.minimax.io/user-center/basic-information/interface-key', 'key_hint' => 'eyJ… / sk-…', 'color' => '#F23F5D',
            'description' => 'Conexión compatible con OpenAI. Si tu cuenta es de China continental usa https://api.minimaxi.com/v1.', 'suggest' => ['MiniMax-M2'],
        ],
        'gemini' => [
            'name' => 'Google Gemini', 'driver' => 'gemini', 'url' => 'https://generativelanguage.googleapis.com/v1beta/', 'url_editable' => false,
            'keys_url' => 'https://aistudio.google.com/apikey', 'key_hint' => 'AIza…', 'color' => '#4285F4',
            'description' => 'Modelos Gemini de Google.', 'suggest' => [],
        ],
        'deepseek' => [
            'name' => 'DeepSeek', 'driver' => 'deepseek', 'url' => 'https://api.deepseek.com', 'url_editable' => false,
            'keys_url' => 'https://platform.deepseek.com/api_keys', 'key_hint' => 'sk-…', 'color' => '#4D6BFE', 'description' => 'Económico y potente para texto.', 'suggest' => [],
        ],
        'groq' => [
            'name' => 'Groq', 'driver' => 'groq', 'url' => 'https://api.groq.com/openai/v1', 'url_editable' => false,
            'keys_url' => 'https://console.groq.com/keys', 'key_hint' => 'gsk_…', 'color' => '#F55036', 'description' => 'Inferencia muy rápida.', 'suggest' => [],
        ],
        'mistral' => [
            'name' => 'Mistral', 'driver' => 'mistral', 'url' => 'https://api.mistral.ai/v1', 'url_editable' => false,
            'keys_url' => 'https://console.mistral.ai/api-keys', 'key_hint' => '…', 'color' => '#FA520F', 'description' => 'Modelos europeos.', 'suggest' => [],
        ],
        'xai' => [
            'name' => 'xAI (Grok)', 'driver' => 'xai', 'url' => 'https://api.x.ai/v1', 'url_editable' => false,
            'keys_url' => 'https://console.x.ai', 'key_hint' => 'xai-…', 'color' => '#111111', 'description' => 'Modelos Grok.', 'suggest' => [],
        ],
        'custom' => [
            'name' => 'Otro (compatible con OpenAI)', 'driver' => 'openai-compatible', 'url' => '', 'url_editable' => true, 'url_required' => true,
            'keys_url' => null, 'key_hint' => 'opcional', 'color' => '#707070',
            'description' => 'LM Studio, vLLM, Together, Fireworks, un gateway propio… Indica la URL base (termina en /v1).', 'suggest' => [],
        ],
    ];

    /** @return array<string, mixed>|null */
    /**
     * Transcripción de voz por driver: si el SDK la soporta y qué modelos sugerir (el primero es el recomendado).
     *
     * @return array{supported: bool, suggest: array<int, string>, default: string|null}
     */
    public static function transcription(string $slug): array
    {
        $driver = self::PROVIDERS[$slug]['driver'] ?? $slug;

        return match ($driver) {
            'openai' => ['supported' => true, 'suggest' => ['gpt-4o-transcribe', 'gpt-4o-mini-transcribe', 'whisper-1'], 'default' => 'gpt-4o-transcribe'],
            'groq' => ['supported' => true, 'suggest' => ['whisper-large-v3-turbo', 'whisper-large-v3'], 'default' => null],
            'mistral' => ['supported' => true, 'suggest' => ['voxtral-mini-latest'], 'default' => null],
            'gemini', 'openrouter' => ['supported' => true, 'suggest' => [], 'default' => null],
            'openai-compatible' => ['supported' => true, 'suggest' => ['whisper-1'], 'default' => 'whisper-1'],
            default => ['supported' => false, 'suggest' => [], 'default' => null], // Claude, DeepSeek, xAI…
        };
    }

    public static function get(string $slug): ?array
    {
        return self::PROVIDERS[$slug] ?? null;
    }
}
