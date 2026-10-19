<?php

namespace App\Http\Controllers\Email;

use App\Http\Controllers\Controller;
use App\Models\EmailMessage;
use App\Models\EmailTemplate;
use App\Services\Ai\AiGateway;
use App\Services\Ai\BrandedEmailDesign;
use App\Services\Email\CampaignRunner;
use App\Services\Email\EmailComposer;
use App\Services\Email\MailSettings;
use App\Services\Email\OutgoingEmail;
use App\Services\Email\ProviderException;
use App\Services\Email\TemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EmailTemplateController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'category']);

        $templates = EmailTemplate::query()
            ->withCount(['campaigns'])
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('name', 'like', "%{$v}%")->orWhere('slug', 'like', "%{$v}%")->orWhere('subject', 'like', "%{$v}%")))
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->where('category', $v))
            ->orderByDesc('updated_at')
            ->paginate(12)
            ->withQueryString()
            ->through(fn (EmailTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'description' => $t->description,
                'category' => $t->category,
                'subject' => $t->subject,
                'editor' => $t->editor,
                'is_active' => $t->is_active,
                'variables' => collect($t->variables ?? [])->pluck('key')->all(),
                'campaigns_count' => $t->campaigns_count,
                'sent_count' => EmailMessage::where('template_id', $t->id)->whereNotIn('status', ['queued'])->count(),
                'updated_at' => $t->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('email/templates/Index', [
            'templates' => $templates,
            'filters' => $filters,
            'categories' => EmailTemplate::CATEGORIES,
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('email/templates/Editor', [
            'template' => null,
            'categories' => EmailTemplate::CATEGORIES,
            'systemVariables' => $this->systemVariableDocs(),
            'brand' => $this->brand(),
            'ai' => $this->aiState($request),
        ]);
    }

    public function edit(Request $request, EmailTemplate $template): Response
    {
        return Inertia::render('email/templates/Editor', [
            'template' => $template->only(['id', 'name', 'slug', 'description', 'category', 'subject', 'preheader', 'editor', 'html', 'text', 'design', 'variables', 'is_active']),
            'categories' => EmailTemplate::CATEGORIES,
            'systemVariables' => $this->systemVariableDocs(),
            'brand' => $this->brand(),
            'ai' => $this->aiState($request),
        ]);
    }

    public function store(Request $request, TemplateRenderer $renderer): JsonResponse|RedirectResponse
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['name']);
        $data['variables'] = $this->mergeVariables($data, $renderer);
        $data['created_by'] = $data['updated_by'] = $request->user()->id;

        $template = EmailTemplate::create($data);

        return $this->saved($request, $template, 'Plantilla creada.');
    }

    public function update(Request $request, EmailTemplate $template, TemplateRenderer $renderer): JsonResponse|RedirectResponse
    {
        $data = $this->validated($request, $template);
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? $template->slug, $template->id);
        $data['variables'] = $this->mergeVariables($data, $renderer);
        $data['updated_by'] = $request->user()->id;

        $template->update($data);

        return $this->saved($request, $template, 'Plantilla guardada.');
    }

    public function duplicate(Request $request, EmailTemplate $template): RedirectResponse
    {
        $copy = $template->replicate(['slug']);
        $copy->name = $template->name.' (copia)';
        $copy->slug = $this->uniqueSlug($template->slug.'-copia');
        $copy->created_by = $copy->updated_by = $request->user()->id;
        $copy->save();

        $this->toast('Plantilla duplicada.');

        return to_route('templates.edit', $copy);
    }

    public function destroy(EmailTemplate $template): RedirectResponse
    {
        $template->delete();

        $this->toast('Plantilla eliminada.');

        return to_route('templates.index');
    }

    /** Vista previa con variables de ejemplo (el HTML puede no estar guardado aún). */
    public function preview(Request $request, EmailComposer $composer, TemplateRenderer $renderer): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:300'],
            'html' => ['nullable', 'string', 'max:600000'],
            'preheader' => ['nullable', 'string', 'max:255'],
            'variables' => ['nullable', 'array'],
        ]);

        $vars = [...$this->sampleSystemVariables(), ...($data['variables'] ?? [])];
        $out = $composer->renderContent($data['subject'] ?? '', $data['html'] ?? '', $vars, $data['preheader'] ?? null);

        return response()->json([
            ...$out,
            'detected' => $renderer->variables(($data['subject'] ?? '')."\n".($data['html'] ?? '')),
        ]);
    }

    /** Envío de prueba inmediato (muestra el error real del proveedor si falla). */
    public function test(Request $request, EmailComposer $composer): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'email:rfc'],
            'subject' => ['required', 'string', 'max:300'],
            'html' => ['required', 'string', 'max:600000'],
            'preheader' => ['nullable', 'string', 'max:255'],
            'variables' => ['nullable', 'array'],
            'template_id' => ['nullable', 'integer'],
        ]);

        if (! MailSettings::fromEmail()) {
            return response()->json(['ok' => false, 'error' => 'Define el correo remitente en Email → Configuración.'], 422);
        }

        $vars = [...$this->sampleSystemVariables(), 'to_email' => $data['to'], ...($data['variables'] ?? [])];
        $out = $composer->renderContent($data['subject'], $data['html'], $vars, $data['preheader'] ?? null);

        $provider = CampaignRunner::provider();
        $email = new OutgoingEmail(from: MailSettings::fromHeader(), to: $data['to'], subject: '[Prueba] '.$out['subject'], html: $out['html'], replyTo: MailSettings::replyTo());

        $message = EmailMessage::create([
            'template_id' => $data['template_id'] ?? null, 'kind' => 'test', 'to_email' => $data['to'], 'from_email' => MailSettings::fromEmail(),
            'subject' => $email->subject, 'status' => 'sending', 'html' => $out['html'], 'variables' => $data['variables'] ?? null,
        ]);

        try {
            $id = $provider->send($email);
            $message->update(['status' => 'sent', 'provider' => $provider->name(), 'provider_id' => $id, 'sent_at' => now()]);

            return response()->json(['ok' => true, 'provider' => $provider->name(), 'missing' => $out['missing']]);
        } catch (ProviderException $e) {
            $message->update(['status' => 'failed', 'error' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Sube una imagen para usarla en plantillas (se sirve desde /storage; requiere `php artisan storage:link`). */
    public function upload(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:3072']]);

        $path = $request->file('file')->store('email-media/'.date('Y/m'), 'public');

        return response()->json(['url' => url(Storage::disk('public')->url($path))]);
    }

    // -------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function validated(Request $request, ?EmailTemplate $template = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'category' => ['required', Rule::in(array_keys(EmailTemplate::CATEGORIES))],
            'subject' => ['required', 'string', 'max:300'],
            'preheader' => ['nullable', 'string', 'max:255'],
            'editor' => ['required', Rule::in(['blocks', 'html'])],
            'html' => ['required', 'string', 'max:600000'],
            'text' => ['nullable', 'string', 'max:100000'],
            'design' => ['nullable', 'array'],
            'variables' => ['nullable', 'array', 'max:60'],
            'variables.*.key' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'variables.*.label' => ['nullable', 'string', 'max:80'],
            'variables.*.default' => ['nullable', 'string', 'max:255'],
            'variables.*.sample' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);
    }

    /**
     * Une las variables declaradas por la persona con las detectadas en el asunto y el HTML.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function mergeVariables(array $data, TemplateRenderer $renderer): array
    {
        $declared = collect($data['variables'] ?? [])->keyBy('key');
        $found = $renderer->variables($data['subject']."\n".$data['html']);

        return collect($found)->map(fn (string $key) => [
            'key' => $key,
            'label' => $declared[$key]['label'] ?? Str::headline($key),
            'default' => $declared[$key]['default'] ?? null,
            'sample' => $declared[$key]['sample'] ?? null,
        ])->values()->all();
    }

    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source, '-') ?: 'plantilla';
        $slug = $base;
        $i = 2;

        while (EmailTemplate::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private function saved(Request $request, EmailTemplate $template, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['ok' => true, 'id' => $template->id, 'slug' => $template->slug, 'variables' => $template->variables, 'message' => $message]);
        }

        $this->toast($message);

        return to_route('templates.edit', $template);
    }

    /** @return array<string, string> */
    private function sampleSystemVariables(): array
    {
        return [
            'unsubscribe_url' => '#baja', 'view_url' => '#ver-en-navegador', 'current_year' => date('Y'),
            'company_name' => MailSettings::companyName(), 'to_email' => 'persona@ejemplo.cl', 'to_name' => 'Persona de Ejemplo',
            'first_name' => 'María', 'last_name' => 'González', 'name' => 'María González', 'email' => 'persona@ejemplo.cl', 'company' => 'Taller Ejemplo',
        ];
    }

    /** @return array<int, array{key: string, description: string}> */
    private function systemVariableDocs(): array
    {
        return [
            ['key' => 'unsubscribe_url', 'description' => 'Enlace de baja (obligatorio en boletines)'],
            ['key' => 'view_url', 'description' => 'Ver este correo en el navegador'],
            ['key' => 'to_name', 'description' => 'Nombre del destinatario'],
            ['key' => 'to_email', 'description' => 'Correo del destinatario'],
            ['key' => 'first_name', 'description' => 'Nombre (clientes, listas y API)'],
            ['key' => 'last_name', 'description' => 'Apellido'],
            ['key' => 'company', 'description' => 'Empresa'],
            ['key' => 'current_year', 'description' => 'Año actual'],
            ['key' => 'company_name', 'description' => 'Nombre de tu empresa (Configuración)'],
        ];
    }

    /** @return array{enabled: bool, can_configure: bool} */
    private function aiState(Request $request): array
    {
        $user = $request->user();

        return [
            'enabled' => (bool) $user?->can('ai.use') && app(AiGateway::class)->isAvailable(),
            'can_configure' => (bool) $user?->can('ai.manage'),
        ];
    }

    /** @return array<string, string> */
    private function brand(): array
    {
        return ['name' => MailSettings::companyName(), 'address' => (string) MailSettings::footerAddress(), 'logo' => BrandedEmailDesign::logoUrl()];
    }
}
