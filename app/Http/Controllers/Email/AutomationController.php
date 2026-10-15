<?php

namespace App\Http\Controllers\Email;

use App\Http\Controllers\Controller;
use App\Models\Automation;
use App\Models\EmailMessage;
use App\Models\EmailTemplate;
use App\Models\LeadSource;
use App\Models\PipelineStage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AutomationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('email/automations/Index', [
            'automations' => Automation::with('template:id,name,slug')->orderByDesc('id')->get()->map(fn (Automation $a) => [
                ...$a->only(['id', 'name', 'trigger', 'conditions', 'subject', 'to_mode', 'to_email', 'delay_minutes', 'variables', 'is_active', 'runs_count', 'template_id']),
                'last_run_at' => $a->last_run_at?->toIso8601String(),
                'template' => $a->template?->only(['id', 'name', 'slug']),
                'sent' => EmailMessage::where('automation_id', $a->id)->whereIn('status', ['sent', 'delivered'])->count(),
                'failed' => EmailMessage::where('automation_id', $a->id)->whereIn('status', ['failed', 'bounced'])->count(),
            ]),
            'triggers' => Automation::TRIGGERS,
            'templates' => EmailTemplate::where('is_active', true)->orderBy('name')->get(['id', 'name', 'slug', 'category', 'variables']),
            'sources' => LeadSource::orderBy('sort_order')->get(['id', 'name', 'color']),
            'stages' => PipelineStage::orderBy('sort_order')->get(['id', 'name', 'color']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $automation = Automation::create([...$this->validated($request), 'created_by' => $request->user()->id]);

        $this->toast("Automatización «{$automation->name}» creada.");

        return back();
    }

    public function update(Request $request, Automation $automation): RedirectResponse
    {
        $automation->update($this->validated($request));

        $this->toast('Automatización actualizada.');

        return back();
    }

    public function destroy(Automation $automation): RedirectResponse
    {
        $automation->delete();

        $this->toast('Automatización eliminada.');

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'trigger' => ['required', Rule::in(array_keys(Automation::TRIGGERS))],
            'conditions' => ['nullable', 'array'],
            'conditions.source_ids' => ['nullable', 'array'],
            'conditions.source_ids.*' => ['integer'],
            'conditions.stage_ids' => ['nullable', 'array'],
            'conditions.stage_ids.*' => ['integer'],
            'conditions.web_only' => ['nullable', 'boolean'],
            'template_id' => ['required', 'integer', Rule::exists('email_templates', 'id')],
            'subject' => ['nullable', 'string', 'max:300'],
            'to_mode' => ['required', Rule::in(['lead', 'fixed', 'team'])],
            'to_email' => ['required_if:to_mode,fixed', 'nullable', 'email:rfc'],
            'delay_minutes' => ['nullable', 'integer', 'min:0', 'max:43200'],
            'variables' => ['nullable', 'array'],
            'variables.*.key' => ['required_with:variables', 'string', 'max:60', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'variables.*.value' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ]);

        // El editor envía [{key, value}]; se guarda como mapa clave => valor.
        $data['variables'] = collect($data['variables'] ?? [])->mapWithKeys(fn ($v) => [$v['key'] => $v['value'] ?? ''])->all() ?: null;
        $data['delay_minutes'] = (int) ($data['delay_minutes'] ?? 0);

        return $data;
    }
}
