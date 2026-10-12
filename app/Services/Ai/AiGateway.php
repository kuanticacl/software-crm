<?php

namespace App\Services\Ai;

use App\Models\AiProvider;
use App\Models\AiRun;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Transcription;
use Throwable;

use function Laravel\Ai\agent;

/**
 * Punto único de acceso al SDK oficial (laravel/ai). Resuelve el proveedor configurado en el CRM
 * (claves cifradas en la base de datos), ejecuta el agente, registra el uso y devuelve errores
 * legibles sin filtrar nunca la API key.
 */
class AiGateway
{
    public function default(): ?AiProvider
    {
        return AiProvider::where('is_default', true)->where('is_enabled', true)->first()
            ?? AiProvider::where('is_enabled', true)->get()->first(fn (AiProvider $p) => $p->isUsable());
    }

    public function isAvailable(): bool
    {
        $p = $this->default();

        return $p !== null && $p->isUsable();
    }

    public function resolve(?string $slug = null): AiProvider
    {
        $provider = $slug ? AiProvider::where('slug', $slug)->first() : $this->default();

        if (! $provider || ! $provider->isUsable()) {
            throw new AiNotConfigured;
        }

        return $provider;
    }

    /** Proveedor del SDK construido en caliente con la key guardada (no se toca config/ai.php ni el .env). */
    public function build(AiProvider $p, ?string $transcriptionModel = null): Provider
    {
        $catalog = AiCatalog::get($p->slug);

        return Ai::build(array_filter([
            'driver' => $p->driver,
            'key' => $p->api_key,
            'url' => $p->base_url ?: ($catalog['url'] ?? null),
            'models' => array_filter([
                'text' => $p->model ? ['default' => $p->model] : null,
                'transcription' => $transcriptionModel ? ['default' => $transcriptionModel] : null,
            ]) ?: null,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Ejecuta un agente y devuelve su respuesta. Registra uso y errores en ai_runs.
     *
     * @param  array{lead_id?: int|null, provider?: string|null}  $ctx
     */
    public function run(string $feature, Agent $agent, string $prompt, array $ctx = [], int $timeout = 90): mixed
    {
        $provider = $this->resolve($ctx['provider'] ?? null);
        $start = microtime(true);

        try {
            $agent = app(AiTuning::class)->tune($agent, $feature, $provider);
            $response = $agent->prompt($prompt, provider: [$this->build($provider)], model: $provider->model ?: null, timeout: $timeout);

            $this->log($feature, $provider, 'ok', $start, $response->usage->inputTokens ?? 0, $response->usage->outputTokens ?? 0, null, $ctx);

            return $response;
        } catch (Throwable $e) {
            $message = $this->friendly($e, $provider);
            $this->log($feature, $provider, 'error', $start, 0, 0, $message, $ctx);

            throw new AiFailed($message, previous: $e);
        }
    }

    /**
     * Respuesta estructurada (JSON). Usa la salida estructurada del SDK; si el proveedor no la soporta
     * (p. ej. algunos gateways compatibles con OpenAI) reintenta pidiendo JSON en texto y lo parsea.
     *
     * @param  Closure(JsonSchema): array<string, mixed>  $schema
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    public function structured(string $feature, string $instructions, string $prompt, Closure $schema, array $ctx = []): array
    {
        $agent = agent(instructions: $instructions, schema: $schema);

        try {
            $response = $this->run($feature, $agent, $prompt, $ctx);

            return $response->toArray();
        } catch (AiFailed $e) {
            if ($this->isFatal($e->getPrevious())) {
                throw $e;
            }
        }

        // Respaldo: texto con JSON.
        $plain = agent(instructions: $instructions."\n\nRESPONDE ÚNICAMENTE con un objeto JSON válido (sin texto antes o después ni bloques de código).");
        $text = (string) $this->run($feature.'_fallback', $plain, $prompt, $ctx);

        $json = json_decode(self::extractJson($text), true);
        if (! is_array($json)) {
            throw new AiFailed('El modelo no devolvió un JSON válido. Prueba con otro modelo.');
        }

        return $json;
    }

    /** Drivers del SDK que ofrecen transcripción de audio. */
    private const TRANSCRIPTION_DRIVERS = ['openai', 'gemini', 'groq', 'mistral', 'openrouter', 'openai-compatible'];

    /** Proveedor para transcribir voz: el predeterminado si sirve; si no, cualquier otro habilitado que sí transcriba. */
    public function transcriptionProvider(): ?AiProvider
    {
        $default = $this->default();
        if ($default && in_array($default->driver, self::TRANSCRIPTION_DRIVERS, true)) {
            return $default;
        }

        return AiProvider::where('is_enabled', true)->whereIn('driver', self::TRANSCRIPTION_DRIVERS)->get()->first(fn (AiProvider $p) => $p->isUsable());
    }

    /** Modelo de transcripción: el definido en Setting «ai.transcription_model»; los gateways compatibles con OpenAI exigen uno explícito. */
    private function transcriptionModel(AiProvider $p): ?string
    {
        return $p->transcription_model ?: \App\Models\Setting::get('ai.transcription_model') ?: (AiCatalog::transcription($p->slug)['default'] ?? null);
    }

    /**
     * Contexto para el modelo de voz (Whisper y gpt-4o-transcribe aceptan «prompt»): un texto de ejemplo
     * en español de Chile con el vocabulario del estudio mejora mucho el idioma, los acentos y los términos.
     * @return array<string, string>
     */
    public static function transcriptionHints(string $driver): array
    {
        if (! in_array($driver, ['openai', 'groq', 'openai-compatible'], true)) {
            return [];
        }
        $custom = trim((string) \App\Models\Setting::get('ai.transcription_prompt', ''));

        return ['prompt' => $custom !== '' ? mb_substr($custom, 0, 800) : 'Nota de voz en español de Chile para el CRM de Vivienda en Regla. '
            .'Habla de prospectos, clientes, propuestas, regularizaciones, permisos de edificación, recepción final, '
            .'la DOM, planos, ampliaciones, honorarios, boleta de honorarios, UF, RUT, comunas y seguimientos. '
            .'Transcribe con puntuación y tildes, tal como se dice.'];
    }

    /** Prueba la voz con un segundo de silencio: valida credenciales, URL y modelo de transcripción. @return array{ok: bool, message: string, ms: int} */
    public function testTranscription(AiProvider $p): array
    {
        $start = microtime(true);
        $path = tempnam(sys_get_temp_dir(), 'stt').'.wav';

        try {
            $rate = 16000;
            $data = str_repeat("\0\0", $rate); // 1 s de silencio, 16-bit mono
            file_put_contents($path, 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16).'data'.pack('V', strlen($data)).$data);
            $file = new \Illuminate\Http\UploadedFile($path, 'prueba.wav', 'audio/wav', null, true);

            Transcription::fromUpload($file)->language('es')->timeout(60)->generate([$this->build($p, $this->transcriptionModel($p))]);
            $model = $this->transcriptionModel($p) ?? 'modelo predeterminado';
            $this->log('transcribe_test', $p, 'ok', $start, 0, 0, null, []);

            return ['ok' => true, 'message' => "Voz funcionando con «{$model}»", 'ms' => (int) round((microtime(true) - $start) * 1000)];
        } catch (Throwable $e) {
            $message = $this->friendly($e, $p);
            $this->log('transcribe_test', $p, 'error', $start, 0, 0, $message, []);

            return ['ok' => false, 'message' => $message, 'ms' => (int) round((microtime(true) - $start) * 1000)];
        } finally {
            @unlink($path);
        }
    }

    /** Transcribe un audio a texto (español). Registra uso y errores en ai_runs. */
    public function transcribe(\Illuminate\Http\UploadedFile $audio): string
    {
        $provider = $this->transcriptionProvider() ?? throw new AiNotConfigured;
        $start = microtime(true);

        try {
            $text = trim((string) Transcription::fromUpload($audio)->language('es')->withProviderOptions(fn ($p) => self::transcriptionHints($p->driver()))->timeout(90)->generate([$this->build($provider, $this->transcriptionModel($provider))]));
            $this->log('transcribe', $provider, 'ok', $start, 0, 0, null, []);

            return $text;
        } catch (Throwable $e) {
            $message = $this->friendly($e, $provider);
            $this->log('transcribe', $provider, 'error', $start, 0, 0, $message, []);

            throw new AiFailed($message, previous: $e);
        }
    }

    /** Prueba de conexión: pide una respuesta mínima y mide la latencia. @return array{ok: bool, message: string, ms: int} */
    public function test(AiProvider $p): array
    {
        $start = microtime(true);

        try {
            $agent = agent(instructions: 'Eres un asistente. Responde con una sola palabra.');
            $response = $agent->prompt('Responde exactamente: OK', provider: [$this->build($p)], model: $p->model ?: null, timeout: 40);
            $ms = (int) round((microtime(true) - $start) * 1000);
            $text = trim((string) $response);

            $this->log('test', $p, 'ok', $start, $response->usage->inputTokens ?? 0, $response->usage->outputTokens ?? 0, null, []);
            $result = ['ok' => true, 'message' => 'Conexión exitosa'.($text !== '' ? ' · respuesta: «'.mb_substr($text, 0, 60).'»' : ''), 'ms' => $ms];
        } catch (Throwable $e) {
            $ms = (int) round((microtime(true) - $start) * 1000);
            $message = $this->friendly($e, $p);
            $this->log('test', $p, 'error', $start, 0, 0, $message, []);
            $result = ['ok' => false, 'message' => $message, 'ms' => $ms];
        }

        $p->forceFill(['last_tested_at' => now(), 'last_test_ok' => $result['ok'], 'last_test_message' => mb_substr($result['message'], 0, 480), 'last_test_ms' => $result['ms']])->save();

        return $result;
    }

    // ------------------------------------------------------------------------------------------------

    /** Errores que no se arreglan reintentando en modo texto (credenciales, cuota, red, límites). */
    private function isFatal(?Throwable $e): bool
    {
        $m = strtolower($e?->getMessage() ?? '');

        return $e instanceof \Laravel\Ai\Exceptions\RateLimitedException
            || $e instanceof \Laravel\Ai\Exceptions\InsufficientCreditsException
            || $e instanceof \Laravel\Ai\Exceptions\ProviderConnectionException
            || $e instanceof \Laravel\Ai\Exceptions\ProviderOverloadedException
            || str_contains($m, '401') || str_contains($m, '403') || str_contains($m, 'api key') || str_contains($m, 'authentic') || str_contains($m, 'unauthorized');
    }

    public function friendly(Throwable $e, AiProvider $p): string
    {
        $raw = $e->getMessage();
        $m = strtolower($raw);

        $text = match (true) {
            $e instanceof \Laravel\Ai\Exceptions\RateLimitedException => 'El proveedor limitó las solicitudes (rate limit). Intenta en unos segundos.',
            $e instanceof \Laravel\Ai\Exceptions\InsufficientCreditsException => 'La cuenta del proveedor no tiene saldo o cuota disponible.',
            $e instanceof \Laravel\Ai\Exceptions\ProviderOverloadedException => 'El proveedor está sobrecargado. Reintenta en un momento.',
            $e instanceof \Laravel\Ai\Exceptions\ProviderConnectionException || str_contains($m, 'could not resolve') || str_contains($m, 'timed out') || str_contains($m, 'connection') => 'No se pudo conectar con el proveedor. Revisa la URL base y tu conexión.',
            str_contains($m, '401') || str_contains($m, 'invalid api key') || str_contains($m, 'incorrect api key') || str_contains($m, 'authentication') || str_contains($m, 'unauthorized') || str_contains($m, 'login fail') => 'La API key fue rechazada por el proveedor. Revísala o genera una nueva.',
            str_contains($m, '403') || str_contains($m, 'permission') => 'La API key no tiene permiso para usar este modelo o recurso.',
            str_contains($m, '404') || str_contains($m, 'model') && str_contains($m, 'not') => 'El modelo indicado no existe para esta cuenta. Usa «Cargar modelos» y elige uno.',
            default => 'El proveedor devolvió un error: '.mb_substr(preg_replace('/\s+/', ' ', $raw), 0, 220),
        };

        return $this->redact($text, $p);
    }

    /** Nunca devolver ni registrar la API key. */
    private function redact(string $text, AiProvider $p): string
    {
        return $p->hasKey() ? str_replace($p->api_key, '***', $text) : $text;
    }

    private function log(string $feature, AiProvider $p, string $status, float $start, int $in, int $out, ?string $error, array $ctx): void
    {
        AiRun::create([
            'feature' => $feature,
            'provider' => $p->slug,
            'model' => $p->model,
            'status' => $status,
            'input_tokens' => $in,
            'output_tokens' => $out,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'user_id' => Auth::id(),
            'lead_id' => $ctx['lead_id'] ?? null,
            'error' => $error ? mb_substr($error, 0, 1000) : null,
        ]);
    }

    public static function extractJson(string $text): string
    {
        $text = trim($text);
        if (preg_match('/```(?:json)?\s*(.*?)```/is', $text, $m)) {
            $text = trim($m[1]);
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        return $start !== false && $end !== false && $end > $start ? substr($text, $start, $end - $start + 1) : $text;
    }
}
