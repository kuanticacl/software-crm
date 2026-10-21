<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiRun;
use App\Models\Setting;
use App\Services\Ai\AiGateway;
use App\Services\Alfred\AlfredSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Ajustes del asistente virtual del sitio web: nombre, prompt, conocimiento autorizado, saludo y activación. */
class AlfredSettingsController extends Controller
{
    public function edit(AiGateway $ai): Response
    {
        return Inertia::render('ai/Alfred', [
            'settings' => AlfredSettings::all(),
            'defaultPrompt' => AlfredSettings::DEFAULT_PROMPT,
            'defaultGreeting' => AlfredSettings::DEFAULT_GREETING,
            'safety' => AlfredSettings::render(AlfredSettings::SAFETY),
            'contact' => AlfredSettings::contactBlock(),
            'aiAvailable' => $ai->isAvailable(),
            'limits' => ['messages' => AlfredSettings::MAX_MESSAGES, 'chars' => AlfredSettings::MAX_CHARS],
            'usage' => [
                'runs' => AiRun::where('feature', 'alfred')->where('created_at', '>=', now()->subDays(30))->count(),
                'errors' => AiRun::where('feature', 'alfred')->where('status', 'error')->where('created_at', '>=', now()->subDays(30))->count(),
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

        Setting::put('alfred.enabled', $data['enabled'] ? '1' : null);
        Setting::put('alfred.name', trim($data['name']));
        // Un prompt igual al predeterminado se guarda vacío: así sigue las mejoras futuras del texto base.
        $prompt = trim((string) ($data['prompt'] ?? ''));
        Setting::put('alfred.prompt', $prompt === trim(AlfredSettings::DEFAULT_PROMPT) ? null : $prompt);
        Setting::put('alfred.knowledge', trim((string) ($data['knowledge'] ?? '')) ?: null);
        Setting::put('alfred.greeting', trim((string) ($data['greeting'] ?? '')) ?: null);

        return back()->with('success', 'Ajustes del asistente guardados.');
    }
}
