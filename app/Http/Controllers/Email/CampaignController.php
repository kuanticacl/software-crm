<?php

namespace App\Http\Controllers\Email;

use App\Http\Controllers\Controller;
use App\Jobs\StartCampaign;
use App\Models\Campaign;
use App\Models\ContactList;
use App\Models\EmailEvent;
use App\Models\EmailMessage;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Email\AudienceBuilder;
use App\Services\Email\CampaignRunner;
use App\Services\Email\EmailComposer;
use App\Services\Email\MailSettings;
use App\Services\Email\OutgoingEmail;
use App\Services\Email\ProviderException;
use App\Support\CampaignStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CampaignController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'status']);

        $campaigns = Campaign::query()->with('template:id,name')
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where('name', 'like', "%{$v}%")->orWhere('subject', 'like', "%{$v}%"))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->latest()->paginate(15)->withQueryString();

        $stats = CampaignStats::forCampaigns($campaigns->pluck('id')->all());

        $campaigns->through(fn (Campaign $c) => [
            ...$c->only(['id', 'name', 'subject', 'status', 'recipients_count', 'scheduled_at', 'started_at', 'finished_at', 'created_at']),
            'template' => $c->template?->name,
            'stats' => $stats[$c->id] ?? CampaignStats::empty(),
        ]);

        return Inertia::render('email/campaigns/Index', [
            'campaigns' => $campaigns,
            'filters' => $filters,
            'statuses' => Campaign::STATUSES,
            'summary' => [
                'sent_30d' => EmailMessage::where('kind', 'campaign')->where('sent_at', '>=', now()->subDays(30))->count(),
                'campaigns_30d' => Campaign::where('created_at', '>=', now()->subDays(30))->count(),
                'suppressed' => \App\Models\EmailSuppression::count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('email/campaigns/Form', [
            'campaign' => null,
            ...$this->formData(),
        ]);
    }

    public function edit(Campaign $campaign): Response
    {
        abort_unless($campaign->isEditable(), 403, 'Este boletín ya no se puede editar.');

        return Inertia::render('email/campaigns/Form', [
            'campaign' => [
                ...$campaign->only(['id', 'name', 'subject', 'preheader', 'template_id', 'from_name', 'from_email', 'reply_to', 'audience', 'variables', 'status', 'track_opens', 'track_clicks']),
                'scheduled_at' => $campaign->scheduled_at?->format('Y-m-d\TH:i'),
            ],
            ...$this->formData(),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $campaign = Campaign::create([...$this->validated($request), 'created_by' => $request->user()->id, 'status' => 'draft']);

        return $this->saved($request, $campaign, 'Borrador guardado.');
    }

    public function update(Request $request, Campaign $campaign): JsonResponse|RedirectResponse
    {
        abort_unless($campaign->isEditable(), 403);
        $campaign->update($this->validated($request));

        return $this->saved($request, $campaign, 'Boletín guardado.');
    }

    public function show(Request $request, Campaign $campaign): Response
    {
        $campaign->load('template:id,name,slug', 'creator:id,name');
        $stats = CampaignStats::forCampaigns([$campaign->id])[$campaign->id] ?? CampaignStats::empty();

        $statusFilter = $request->query('status');
        $recipients = $campaign->messages()
            ->when($statusFilter === 'opened', fn ($q) => $q->whereNotNull('first_opened_at'))
            ->when($statusFilter === 'clicked', fn ($q) => $q->whereNotNull('first_clicked_at'))
            ->when($statusFilter === 'unsubscribed', fn ($q) => $q->whereNotNull('unsubscribed_at'))
            ->when($statusFilter && ! in_array($statusFilter, ['opened', 'clicked', 'unsubscribed'], true), fn ($q) => $q->where('status', $statusFilter))
            ->when($request->query('q'), fn ($q, $v) => $q->where('to_email', 'like', "%{$v}%"))
            ->orderBy('id')->paginate(20)->withQueryString()
            ->through(fn (EmailMessage $m) => $m->only(['id', 'to_email', 'to_name', 'status', 'sent_at', 'first_opened_at', 'first_clicked_at', 'open_count', 'click_count', 'unsubscribed_at', 'error']));

        $links = EmailEvent::query()
            ->whereIn('email_message_id', fn ($q) => $q->select('id')->from('email_messages')->where('campaign_id', $campaign->id))
            ->where('type', 'clicked')->limit(5000)->get(['data'])
            ->groupBy(fn ($e) => $e->data['url'] ?? null)->forget('')->map->count()->sortDesc()->take(8);

        return Inertia::render('email/campaigns/Show', [
            'campaign' => [
                ...$campaign->only(['id', 'name', 'subject', 'preheader', 'status', 'recipients_count', 'scheduled_at', 'started_at', 'finished_at', 'track_opens', 'track_clicks', 'created_at']),
                'template' => $campaign->template?->only(['id', 'name']),
                'creator' => $campaign->creator?->name,
                'audience' => $campaign->audience,
            ],
            'stats' => $stats,
            'recipients' => $recipients,
            'filters' => $request->only(['q', 'status']),
            'topLinks' => $links->map(fn ($count, $url) => ['url' => $url, 'count' => $count])->values(),
            'statuses' => Campaign::STATUSES,
            'messageStatuses' => EmailMessage::STATUSES,
            'provider' => MailSettings::provider(),
        ]);
    }

    public function duplicate(Request $request, Campaign $campaign): RedirectResponse
    {
        $copy = $campaign->replicate(['status', 'html', 'scheduled_at', 'started_at', 'finished_at', 'recipients_count']);
        $copy->name = $campaign->name.' (copia)';
        $copy->status = 'draft';
        $copy->recipients_count = 0;
        $copy->created_by = $request->user()->id;
        $copy->save();

        $this->toast('Boletín duplicado como borrador.');

        return to_route('campaigns.edit', $copy);
    }

    public function destroy(Campaign $campaign): RedirectResponse
    {
        abort_if($campaign->status === 'sending', 403, 'Pausa o cancela el envío antes de eliminarlo.');
        $campaign->delete();

        $this->toast('Boletín eliminado.');

        return to_route('campaigns.index');
    }

    // ------------------------------------------------------------------- envío

    /** Envía ahora o programa. */
    public function send(Request $request, Campaign $campaign): RedirectResponse
    {
        abort_unless($campaign->isEditable(), 403);

        $data = $request->validate(['when' => ['required', 'in:now,schedule'], 'scheduled_at' => ['required_if:when,schedule', 'nullable', 'date', 'after:now']]);

        if ($problem = $this->readiness($campaign)) {
            $this->toast($problem, 'error');

            return back();
        }

        if ($data['when'] === 'schedule') {
            $campaign->update(['status' => 'scheduled', 'scheduled_at' => $data['scheduled_at']]);
            $this->toast('Boletín programado para '.$campaign->scheduled_at->translatedFormat('j M Y, H:i').'.');

            return to_route('campaigns.show', $campaign);
        }

        $campaign->update(['status' => 'scheduled', 'scheduled_at' => now()]);
        StartCampaign::dispatch($campaign->id);

        $this->toast('Envío iniciado. Se procesa en segundo plano.');

        return to_route('campaigns.show', $campaign);
    }

    public function pause(Campaign $campaign): RedirectResponse
    {
        if ($campaign->status === 'sending') {
            $campaign->update(['status' => 'paused']);
            $this->toast('Envío pausado.');
        }

        return back();
    }

    public function resume(Campaign $campaign): RedirectResponse
    {
        if ($campaign->status === 'paused') {
            StartCampaign::dispatch($campaign->id);
            $this->toast('Reanudando envío.');
        }

        return back();
    }

    public function cancel(Campaign $campaign): RedirectResponse
    {
        if (in_array($campaign->status, ['scheduled', 'sending', 'paused'], true)) {
            $campaign->update(['status' => 'cancelled', 'finished_at' => now()]);
            EmailMessage::where('campaign_id', $campaign->id)->where('status', 'queued')->update(['status' => 'suppressed', 'error' => 'Envío cancelado']);
            $this->toast('Envío cancelado. Lo ya enviado no se puede revertir.');
        }

        return back();
    }

    // ------------------------------------------------------------------- utilidades (JSON)

    /** Cantidad de destinatarios únicos para una audiencia (se actualiza mientras se configura). */
    public function audienceCount(Request $request, AudienceBuilder $builder): JsonResponse
    {
        $audience = (array) $request->input('audience', []);

        return response()->json(['count' => $builder->count($audience)]);
    }

    public function preview(Request $request, EmailComposer $composer): JsonResponse
    {
        $data = $request->validate(['template_id' => ['required', 'integer', 'exists:email_templates,id'], 'subject' => ['nullable', 'string'], 'preheader' => ['nullable', 'string']]);
        $t = EmailTemplate::findOrFail($data['template_id']);

        $vars = ['first_name' => 'María', 'last_name' => 'González', 'name' => 'María González', 'email' => 'persona@ejemplo.cl', 'company' => 'Taller Ejemplo', 'unsubscribe_url' => '#baja', 'view_url' => '#', 'current_year' => date('Y'), 'company_name' => MailSettings::companyName(), 'to_email' => 'persona@ejemplo.cl', 'to_name' => 'María González'];
        foreach ($t->variables ?? [] as $v) {
            $vars[$v['key']] ??= $v['sample'] ?: ($v['default'] ?? '');
        }

        return response()->json($composer->renderContent($data['subject'] ?: $t->subject, (string) $t->html, $vars, $data['preheader'] ?: $t->preheader));
    }

    public function test(Request $request, EmailComposer $composer): JsonResponse
    {
        $data = $request->validate([
            'template_id' => ['required', 'integer', 'exists:email_templates,id'],
            'to' => ['required', 'email:rfc'],
            'subject' => ['nullable', 'string', 'max:300'],
            'preheader' => ['nullable', 'string', 'max:255'],
        ]);

        if (! MailSettings::fromEmail()) {
            return response()->json(['ok' => false, 'error' => 'Define el correo remitente en Email → Configuración.'], 422);
        }

        $t = EmailTemplate::findOrFail($data['template_id']);
        $vars = ['first_name' => 'María', 'last_name' => 'González', 'name' => 'María González', 'company' => 'Taller Ejemplo', 'email' => $data['to'], 'unsubscribe_url' => '#baja', 'view_url' => '#', 'current_year' => date('Y'), 'company_name' => MailSettings::companyName(), 'to_email' => $data['to']];
        foreach ($t->variables ?? [] as $v) {
            $vars[$v['key']] ??= $v['sample'] ?: ($v['default'] ?? '');
        }
        $out = $composer->renderContent($data['subject'] ?: $t->subject, (string) $t->html, $vars, $data['preheader'] ?: $t->preheader);

        $provider = CampaignRunner::provider();
        $message = EmailMessage::create(['template_id' => $t->id, 'kind' => 'test', 'to_email' => $data['to'], 'from_email' => MailSettings::fromEmail(), 'subject' => '[Prueba] '.$out['subject'], 'status' => 'sending', 'html' => $out['html']]);

        try {
            $id = $provider->send(new OutgoingEmail(MailSettings::fromHeader(), $data['to'], '[Prueba] '.$out['subject'], $out['html'], replyTo: MailSettings::replyTo()));
            $message->update(['status' => 'sent', 'provider' => $provider->name(), 'provider_id' => $id, 'sent_at' => now()]);

            return response()->json(['ok' => true, 'provider' => $provider->name()]);
        } catch (ProviderException $e) {
            $message->update(['status' => 'failed', 'error' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    // ------------------------------------------------------------------- privados

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'subject' => ['required', 'string', 'max:300'],
            'preheader' => ['nullable', 'string', 'max:255'],
            'template_id' => ['required', 'integer', 'exists:email_templates,id'],
            'from_name' => ['nullable', 'string', 'max:100'],
            'from_email' => ['nullable', 'email:rfc', 'max:255'],
            'reply_to' => ['nullable', 'email:rfc', 'max:255'],
            'audience' => ['required', 'array'],
            'audience.lists' => ['nullable', 'array'],
            'audience.lists.*' => ['integer'],
            'audience.leads' => ['nullable', 'array'],
            'audience.clients' => ['nullable', 'array'],
            'variables' => ['nullable', 'array'],
            'track_opens' => ['boolean'],
            'track_clicks' => ['boolean'],
            'scheduled_at' => ['nullable', 'date'],
        ]);
    }

    private function readiness(Campaign $campaign): ?string
    {
        if (! (MailSettings::fromEmail() || $campaign->from_email)) {
            return 'Define el correo remitente en Email → Configuración antes de enviar.';
        }
        if (! $campaign->template || ! trim((string) $campaign->template->html)) {
            return 'El boletín no tiene una plantilla con contenido.';
        }
        if (app(AudienceBuilder::class)->count($campaign->audience ?? []) === 0) {
            return 'La audiencia no tiene destinatarios válidos (revisa listas, filtros y bajas).';
        }

        return null;
    }

    private function saved(Request $request, Campaign $campaign, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['ok' => true, 'id' => $campaign->id, 'message' => $message]);
        }

        $this->toast($message);

        return to_route('campaigns.edit', $campaign);
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'templates' => EmailTemplate::where('is_active', true)->orderBy('name')->get(['id', 'name', 'subject', 'preheader', 'category']),
            'lists' => ContactList::withCount('entries')->orderBy('name')->get(['id', 'name']),
            'sources' => LeadSource::orderBy('sort_order')->get(['id', 'name', 'color']),
            'stages' => PipelineStage::orderBy('sort_order')->get(['id', 'name', 'color']),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'priorities' => Lead::PRIORITIES,
            'defaults' => ['from_name' => MailSettings::fromName(), 'from_email' => MailSettings::fromEmail(), 'reply_to' => MailSettings::replyTo()],
            'canSend' => request()->user()->hasPermission('campaigns.send'),
        ];
    }
}
