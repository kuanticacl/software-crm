<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use App\Models\AiRun;
use App\Models\Setting;
use App\Services\Ai\AiCatalog;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiTuning;
use App\Services\Ai\BrandContext;
use App\Services\Ai\ModelLister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AiSettingsController extends Controller
{
    public function index(AiGateway $ai): Response
    {
        $rows = AiProvider::all()->keyBy('slug');

        $voice = $ai->transcriptionProvider()?->slug;
        $providers = collect(AiCatalog::PROVIDERS)->map(function (array $c, string $slug) use ($rows, $voice) {
            $row = $rows->get($slug);

            return [
                'slug' => $slug,
                'name' => $c['name'],
                'color' => $c['color'],
                'description' => $c['description'],
                'keys_url' => $c['keys_url'],
                'key_hint_format' => $c['key_hint'],
                'url_editable' => $c['url_editable'],
                'url_required' => $c['url_required'] ?? false,
                'default_url' => $c['url'],
                'suggest' => $c['suggest'],
                'configured' => (bool) $row,
                'has_key' => $row?->hasKey() ?? false,
                'key_masked' => $row?->keyHint(),
                'base_url' => $row?->base_url,
                'model' => $row?->model,
                'stt' => [...AiCatalog::transcription($slug), 'model' => $row?->transcription_model, 'effective' => $row ? ($row->transcription_model ?: (\App\Models\Setting::get('ai.transcription_model') ?: (AiCatalog::transcription($slug)['default'] ?? null))) : null, 'in_use' => $voice === $slug],
                'is_enabled' => $row?->is_enabled ?? true,
                'is_default' => $row?->is_default ?? false,
                'usable' => $row?->isUsable() ?? false,
                'last_tested_at' => $row?->last_tested_at?->toIso8601String(),
                'last_test_ok' => $row?->last_test_ok,
                'last_test_message' => $row?->last_test_message,
                'last_test_ms' => $row?->last_test_ms,
            ];
        })->values();

        $since = now()->subDays(30);
        $usage = AiRun::where('created_at', '>=', $since)->selectRaw("feature, count(*) as runs, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens, sum(case when status = 'error' then 1 else 0 end) as errors")->groupBy('feature')->get();

        return Inertia::render('ai/Providers', [
            'providers' => $providers,
            'available' => $ai->isAvailable(),
            'settings' => [
                'auto_analyze' => (bool) Setting::get('ai.auto_analyze', false),
                'share_contact' => (bool) Setting::get('ai.share_contact', false),
                'brand_context' => Setting::get('ai.brand_context') ?: BrandContext::defaultText(),
                'brand_context_is_default' => ! Setting::has('ai.brand_context'),
            ],
            'tuning' => AiTuning::settings(),
            'tuningOptions' => ['temperatures' => AiTuning::TEMPERATURES, 'reasoning' => AiTuning::REASONING],
            'defaultModel' => $ai->default()?->model,
            'reasoningModel' => AiTuning::isReasoningModel((string) $ai->default()?->model),
            'usage' => $usage,
            'recentErrors' => AiRun::where('status', 'error')->latest()->limit(5)->get(['feature', 'provider', 'error', 'created_at']),
        ]);
    }

    public function update(Request $request, string $slug): RedirectResponse
    {
        $catalog = AiCatalog::get($slug) ?? abort(404);

        $data = $request->validate([
            'api_key' => ['nullable', 'string', 'max:600'],
            'base_url' => [($catalog['url_required'] ?? false) ? 'required' : 'nullable', 'url', 'max:255'],
            'model' => ['nullable', 'string', 'max:160'],
            'transcription_model' => ['nullable', 'string', 'max:120'],
            'is_enabled' => ['boolean'],
        ]);

        $this->guardUrl($data['base_url'] ?? null);

        $provider = AiProvider::firstOrNew(['slug' => $slug]);
        $provider->fill([
            'name' => $catalog['name'],
            'driver' => $catalog['driver'],
            'base_url' => $catalog['url_editable'] ? ($data['base_url'] ?? null) : null,
            'model' => $data['model'] ?? null,
            'transcription_model' => AiCatalog::transcription($slug)['supported'] ? ($data['transcription_model'] ?? null) : null,
            'is_enabled' => $data['is_enabled'] ?? true,
            'created_by' => $provider->created_by ?? $request->user()->id,
        ]);

        // Solo se reemplaza la key si se escribió una nueva; vacío = conservar la actual.
        if (filled($data['api_key'] ?? null)) {
            $provider->api_key = trim($data['api_key']);
            $provider->last_test_ok = null;
            $provider->last_test_message = null;
        }

        if (! $provider->hasKey() && $catalog['driver'] !== 'openai-compatible' && $slug !== 'ollama') {
            throw ValidationException::withMessages(['api_key' => 'Ingresa la API key.']);
        }

        $provider->save();

        // El primer proveedor utilizable queda como predeterminado.
        if (! AiProvider::where('is_default', true)->exists() && $provider->isUsable()) {
            $provider->update(['is_default' => true]);
        }

        $this->toast("«{$catalog['name']}» guardado. Pulsa «Probar conexión» para verificarlo.");

        return back();
    }

    public function destroy(string $slug): RedirectResponse
    {
        $provider = AiProvider::where('slug', $slug)->firstOrFail();
        $wasDefault = $provider->is_default;
        $provider->delete();

        if ($wasDefault) {
            AiProvider::where('is_enabled', true)->get()->first(fn ($p) => $p->isUsable())?->update(['is_default' => true]);
        }

        $this->toast('Proveedor eliminado y su API key borrada.');

        return back();
    }

    public function makeDefault(string $slug): RedirectResponse
    {
        $provider = AiProvider::where('slug', $slug)->firstOrFail();

        if (! $provider->isUsable()) {
            $this->toast('Configura y habilita el proveedor antes de usarlo por defecto.', 'error');

            return back();
        }

        AiProvider::query()->update(['is_default' => false]);
        $provider->update(['is_default' => true]);

        $this->toast("«{$provider->name}» es ahora el proveedor por defecto.");

        return back();
    }

    public function test(string $slug, AiGateway $ai): JsonResponse
    {
        $provider = AiProvider::where('slug', $slug)->firstOrFail();

        if (! $provider->isUsable()) {
            return response()->json(['ok' => false, 'message' => 'Falta la API key o el proveedor está deshabilitado.', 'ms' => 0], 422);
        }

        return response()->json($ai->test($provider));
    }

    public function testVoice(string $slug, AiGateway $ai): JsonResponse
    {
        $provider = AiProvider::where('slug', $slug)->firstOrFail();

        if (! $provider->isUsable()) {
            return response()->json(['ok' => false, 'message' => 'Falta la API key o el proveedor está deshabilitado.', 'ms' => 0], 422);
        }
        if (! AiCatalog::transcription($slug)['supported']) {
            return response()->json(['ok' => false, 'message' => 'Este proveedor no ofrece transcripción de voz. Usa OpenAI, Groq, Gemini o Mistral para el micrófono.', 'ms' => 0], 422);
        }

        return response()->json($ai->testTranscription($provider));
    }

    public function models(string $slug, ModelLister $lister): JsonResponse
    {
        $provider = AiProvider::where('slug', $slug)->firstOrFail();

        return response()->json($lister->list($provider));
    }

    /** Comportamiento de la IA: instrucciones propias y parámetros del modelo. */
    public function tuning(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'instructions' => ['nullable', 'string', 'max:4000'],
            'agent_instructions' => ['nullable', 'string', 'max:4000'],
            'temperature' => ['nullable', 'in:'.implode(',', array_filter(array_keys(AiTuning::TEMPERATURES), fn ($k) => $k !== ''))],
            'reasoning_effort' => ['nullable', 'in:low,medium,high'],
            'max_tokens' => ['nullable', 'integer', 'min:256', 'max:32000'],
            'agent_max_steps' => ['nullable', 'integer', 'min:2', 'max:30'],
            'transcription_prompt' => ['nullable', 'string', 'max:800'],
        ]);

        foreach (['instructions', 'agent_instructions', 'temperature', 'reasoning_effort', 'max_tokens', 'agent_max_steps', 'transcription_prompt'] as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            Setting::put('ai.'.$key, $value === '' ? null : $value);
        }

        $this->toast('Comportamiento de la IA guardado.');

        return back();
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'auto_analyze' => ['boolean'],
            'share_contact' => ['boolean'],
            'brand_context' => ['nullable', 'string', 'max:6000'],
        ]);

        Setting::put('ai.auto_analyze', $data['auto_analyze'] ?? false ? '1' : '0');
        Setting::put('ai.share_contact', $data['share_contact'] ?? false ? '1' : '0');

        $context = trim((string) ($data['brand_context'] ?? ''));
        Setting::put('ai.brand_context', $context === '' || $context === BrandContext::defaultText() ? null : $context);

        $this->toast('Preferencias de IA guardadas.');

        return back();
    }

    /** Evita apuntar a servicios internos sensibles (metadatos de la nube). */
    private function guardUrl(?string $url): void
    {
        if (! $url) {
            return;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (str_starts_with($host, '169.254.') || $host === 'metadata.google.internal') {
            throw ValidationException::withMessages(['base_url' => 'Esa dirección no está permitida.']);
        }
    }
}
