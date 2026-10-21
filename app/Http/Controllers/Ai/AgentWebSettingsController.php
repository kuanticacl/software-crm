<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiRun;
use App\Models\Setting;
use App\Services\Ai\AiGateway;
use App\Services\AgentWeb\AgentWebSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Ajustes del asistente virtual del sitio web: nombre, prompt, conocimiento autorizado, saludo y activación. */
class AgentWebSettingsController extends Controller
{
    public function edit(AiGateway $ai): Response
    {
        return Inertia::render('ai/AgentWeb', [
            'settings' => AgentWebSettings::all(),
            'defaultPrompt' => AgentWebSettings::DEFAULT_PROMPT,
            'defaultGreeting' => AgentWebSettings::DEFAULT_GREETING,
            'safety' => AgentWebSettings::render(AgentWebSettings::SAFETY),
            'contact' => AgentWebSettings::contactBlock(),
            'aiAvailable' => $ai->isAvailable(),
            'limits' => ['messages' => AgentWebSettings::MAX_MESSAGES, 'chars' => AgentWebSettings::MAX_CHARS],
            'usage' => [
                'runs' => AiRun::where('feature', 'agentweb')->where('created_at', '>=', now()->subDays(30))->count(),
                'errors' => AiRun::where('feature', 'agentweb')->where('status', 'error')->where('created_at', '>=', now()->subDays(30))->count(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'name' => ['required', 'string', 'min:2', 'max:40'],
            'prompt' => ['nullable', 'string', 'max:6000'],
            'knowledge' => ['nullable', 'string', 'max:20000'],
            'greeting' => ['nullable', 'string', 'max:400'],
        ]);

        Setting::put('agentweb.enabled', $data['enabled'] ? '1' : null);
        Setting::put('agentweb.name', trim($data['name']));
        // Un prompt igual al predeterminado se guarda vacío: así sigue las mejoras futuras del texto base.
        $prompt = trim((string) ($data['prompt'] ?? ''));
        Setting::put('agentweb.prompt', $prompt === trim(AgentWebSettings::DEFAULT_PROMPT) ? null : $prompt);
        Setting::put('agentweb.knowledge', trim((string) ($data['knowledge'] ?? '')) ?: null);
        Setting::put('agentweb.greeting', trim((string) ($data['greeting'] ?? '')) ?: null);

        return back()->with('success', 'Ajustes del asistente guardados.');
    }
}
