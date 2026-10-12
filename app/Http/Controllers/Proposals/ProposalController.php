<?php

namespace App\Http\Controllers\Proposals;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Service;
use App\Models\User;
use App\Services\Ai\AiGateway;
use App\Services\LeadService;
use App\Services\Proposals\ProposalBuilder;
use App\Services\UfService;
use App\Support\Honorarios;
use App\Support\ProposalText;
use App\Services\Proposals\ProposalMailer;
use App\Services\Proposals\ProposalPdf;
use App\Services\Proposals\ProposalView;
use App\Support\Rut;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProposalController extends Controller
{
    public function __construct(private ProposalBuilder $builder) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = ['q' => (string) $request->input('q', ''), 'status' => (string) $request->input('status', '')];

        $rows = Proposal::query()->visibleTo($user)->with(['client:id,name', 'lead:id,first_name,last_name', 'owner:id,name'])
            ->when($filters['q'], fn ($q, $t) => $q->where(fn ($q) => $q->where('title', 'like', "%{$t}%")->orWhere('number', 'like', "%{$t}%")->orWhere('recipient', 'like', "%{$t}%")))
            ->when($filters['status'] && $filters['status'] !== 'expired', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['status'] === 'expired', fn ($q) => $q->whereIn('status', ['sent', 'viewed'])->whereDate('valid_until', '<', now()))
            ->latest('id')->paginate(15)->withQueryString();

        $rows->getCollection()->transform(fn (Proposal $p) => $this->row($p));

        $summary = Proposal::query()->visibleTo($user)->selectRaw("status, count(*) c, sum(CASE WHEN currency = 'UF' THEN total_net * COALESCE(uf_value, 0) ELSE total_net END) net")->groupBy('status')->get()->keyBy('status');

        return Inertia::render('proposals/Index', [
            'proposals' => $rows,
            'filters' => $filters,
            'statuses' => Proposal::STATUSES,
            'summary' => collect(Proposal::STATUSES)->map(fn ($label, $k) => ['label' => $label, 'count' => (int) ($summary[$k]->c ?? 0), 'net' => (int) ($summary[$k]->net ?? 0)])->all(),
            'can' => $this->can($user),
        ]);
    }

    public function create(Request $request): Response
    {
        $lead = $request->filled('lead') ? Lead::visibleTo($request->user())->with('client')->find($request->integer('lead')) : null;
        $client = $request->filled('client') ? Client::find($request->integer('client')) : $lead?->client;

        $title = 'Propuesta comercial'.($client?->name || $lead?->company ? ' · '.($client?->name ?: $lead->company) : '');

        return Inertia::render('proposals/Builder', $this->builderProps($request, null, [
            'title' => $title,
            'currency' => 'UF',
            'client_id' => $client?->id,
            'lead_id' => $lead?->id,
            'recipient' => ProposalBuilder::recipientFor($client, $lead),
            'sections' => ProposalBuilder::defaultSections(),
            'valid_until' => now()->addDays(15)->toDateString(),
            'contract_months' => 12,
            'discount_type' => 'percent',
            'discount_value' => 0,
            'tax_mode' => 'honorarios',
            'tax_rate' => Honorarios::rate(),
            'items' => [],
            'internal_notes' => null,
        ]));
    }

    public function edit(Request $request, Proposal $proposal): Response
    {
        $this->authorizeView($request, $proposal);

        return Inertia::render('proposals/Builder', $this->builderProps($request, $proposal, [
            ...$proposal->only(['title', 'currency', 'client_id', 'lead_id', 'recipient', 'sections', 'contract_months', 'discount_type', 'discount_value', 'tax_mode', 'tax_rate', 'internal_notes']),
            'valid_until' => $proposal->valid_until?->toDateString(),
            'items' => $proposal->items->map(fn ($i) => $i->only(['service_id', 'name', 'description', 'deliverables', 'billing', 'unit', 'quantity', 'unit_price', 'discount_pct']))->all(),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->authorizeLead($request, $data['lead_id'] ?? null);

        $proposal = $this->builder->save($data, null, $request->user()->id);
        $this->touchLead($proposal, $request->user(), 'Propuesta creada: '.$proposal->number.' · '.$proposal->title);

        return response()->json(['id' => $proposal->id, 'number' => $proposal->number, 'message' => 'Propuesta creada']);
    }

    public function update(Request $request, Proposal $proposal): JsonResponse
    {
        $this->authorizeView($request, $proposal);
        abort_if($proposal->isFinal(), 422, 'Una propuesta aceptada o rechazada no se puede modificar. Crea una nueva versión.');

        $data = $this->validated($request);
        $this->authorizeLead($request, $data['lead_id'] ?? null);
        $this->builder->save($data, $proposal);

        return response()->json(['message' => 'Propuesta guardada', 'total_net' => $proposal->fresh()->total_net]);
    }

    /** Vista previa en vivo del documento (sin guardar). */
    public function preview(Request $request): \Illuminate\Http\Response
    {
        $data = $request->validate($this->rules(false));
        $p = $this->builder->fromPayload($data);
        $p->number = 'P-VISTA-PREVIA';
        $p->issued_at = now();
        $p->setRelation('owner', $request->user());

        return response(view('proposals.document', ['p' => $p, 'public' => false, 'preview' => true])->render());
    }

    public function show(Request $request, Proposal $proposal): Response
    {
        $this->authorizeView($request, $proposal);
        $proposal->load(['items', 'client:id,name', 'lead:id,first_name,last_name,email', 'owner:id,name']);

        return Inertia::render('proposals/Show', [
            'proposal' => [
                ...$this->row($proposal),
                'recipient' => $proposal->recipient,
                'public_url' => $proposal->publicUrl(),
                'sent_at' => $proposal->sent_at?->toIso8601String(),
                'viewed_at' => $proposal->viewed_at?->toIso8601String(),
                'view_count' => $proposal->view_count,
                'responded_at' => $proposal->responded_at?->toIso8601String(),
                'responded_by' => $proposal->responded_by,
                'signer_rut' => $proposal->signer_rut,
                'signature_data' => $proposal->signature_data,
                'response_note' => $proposal->response_note,
                'internal_notes' => $proposal->internal_notes,
                'internal_notes_html' => ProposalText::html($proposal->internal_notes),
                'total_one_time' => $proposal->total_one_time,
                'total_monthly' => $proposal->total_monthly,
                'total_tax' => $proposal->total_tax,
                'tax_labels' => $proposal->taxLabels(),
                'contract_months' => $proposal->contract_months,
            ],
            'statuses' => Proposal::STATUSES,
            'siblings' => Proposal::query()->visibleTo($request->user())->where('id', '!=', $proposal->id)
                ->where(fn ($q) => $proposal->lead_id ? $q->where('lead_id', $proposal->lead_id) : $q->where('client_id', $proposal->client_id)->whereNotNull('client_id'))
                ->latest('id')->limit(10)->get()->map(fn ($p) => $this->row($p)),
            'can' => $this->can($request->user()),
        ]);
    }

    /** Documento tal como lo ve el cliente (para el iframe de la ficha). */
    public function document(Request $request, Proposal $proposal): \Illuminate\Http\Response
    {
        $this->authorizeView($request, $proposal);

        return response(view('proposals.document', ['p' => $proposal->load(['items', 'owner:id,name']), 'public' => false, 'preview' => false])->render());
    }

    /** Descarga el PDF de la propuesta (servidor). */
    public function pdf(Request $request, Proposal $proposal, ProposalPdf $pdf): \Illuminate\Http\Response
    {
        $this->authorizeView($request, $proposal);

        return $this->pdfResponse($proposal, $pdf);
    }

    public function duplicate(Request $request, Proposal $proposal): RedirectResponse
    {
        $this->authorizeView($request, $proposal);
        $copy = $this->builder->duplicate($proposal->load('items'), $request->user()->id);
        $this->toast("Nueva versión creada: {$copy->number}. Edítala y envíala cuando esté lista.");

        return to_route('proposals.edit', $copy);
    }

    /** Cambia el estado manualmente (p. ej. «el cliente aceptó por teléfono»). */
    public function status(Request $request, Proposal $proposal, LeadService $leads): RedirectResponse
    {
        $this->authorizeView($request, $proposal);
        $data = $request->validate(['status' => ['required', Rule::in(['draft', 'sent', 'accepted', 'rejected'])], 'note' => ['nullable', 'string', 'max:1000']]);

        $wasDraft = $proposal->status === 'draft';
        $proposal->status = $data['status'];
        if ($data['status'] === 'sent') {
            $proposal->sent_at ??= now();
        }
        if (in_array($data['status'], ['draft', 'sent'], true)) {
            $this->clearResponse($proposal); // reabrir: se descarta la respuesta (y firma) anterior
        }
        if (in_array($data['status'], ['accepted', 'rejected'], true)) {
            $proposal->forceFill(['responded_at' => now(), 'responded_by' => $request->user()->name.' (registrado internamente)', 'response_note' => $data['note'] ?? null, 'signature_data' => null, 'signer_rut' => null]);
        }
        $proposal->save();

        // Al pasar de borrador a enviada la UF del día queda congelada, igual que al enviarla por correo.
        if ($wasDraft && $data['status'] !== 'draft') {
            $this->builder->freezeUf($proposal);
        }

        $this->touchLead($proposal, $request->user(), "Propuesta {$proposal->number}: ".mb_strtolower(Proposal::STATUSES[$data['status']]));
        $this->toast('Estado actualizado: '.Proposal::STATUSES[$data['status']].'.');

        return back();
    }

    /** Envía la propuesta por correo y la marca como enviada. */
    public function send(Request $request, Proposal $proposal, ProposalMailer $mailer): RedirectResponse
    {
        $this->authorizeView($request, $proposal);
        abort_if($proposal->items()->count() === 0, 422, 'Agrega al menos un servicio antes de enviar.');

        $data = $request->validate([
            'to' => ['required', 'email:rfc'],
            'name' => ['nullable', 'string', 'max:160'],
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:2000'],
        ], ['to.required' => 'Indica el correo del destinatario.', 'to.email' => 'El correo no es válido.']);

        $mailer->send($proposal, $data['to'], $data['name'] ?? null, $data['subject'], $data['message'], $request->user()->id);
        $this->markSent($proposal, $request->user(), "Propuesta {$proposal->number} enviada a {$data['to']}");
        $this->toast("Propuesta enviada a {$data['to']}.");

        return back();
    }

    /** Marca como enviada sin enviar correo (se compartió el enlace por WhatsApp u otro medio). */
    public function markSentManually(Request $request, Proposal $proposal): RedirectResponse
    {
        $this->authorizeView($request, $proposal);
        abort_if($proposal->items()->count() === 0, 422, 'Agrega al menos un servicio antes de enviar.');
        $this->markSent($proposal, $request->user(), "Propuesta {$proposal->number} compartida con el cliente");
        $this->toast('Propuesta marcada como enviada.');

        return back();
    }

    public function destroy(Request $request, Proposal $proposal): RedirectResponse
    {
        $this->authorizeView($request, $proposal);
        $proposal->delete();
        $this->toast('Propuesta eliminada.');

        return to_route('proposals.index');
    }

    /** Propuestas de un lead (panel del Kanban / ficha). */
    public function forLead(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        return response()->json(['proposals' => Proposal::where('lead_id', $lead->id)->latest('id')->get()->map(fn ($p) => $this->row($p))]);
    }


    /** Busca propuestas visibles para el usuario (para asociarlas a un lead). */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        $exclude = $request->integer('exclude_lead') ?: null;

        $rows = Proposal::query()->visibleTo($request->user())->with(['client:id,name', 'lead:id,first_name,last_name'])
            ->when($exclude, fn ($w) => $w->where(fn ($x) => $x->whereNull('lead_id')->orWhere('lead_id', '!=', $exclude)))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('number', 'like', "%$q%")->orWhere('title', 'like', "%$q%")
                ->orWhere('recipient', 'like', "%$q%")->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%$q%"))))
            ->latest('id')->limit(15)->get();

        return response()->json(['proposals' => $rows->map(fn (Proposal $p) => [
            'id' => $p->id, 'number' => $p->number, 'title' => $p->title,
            'company' => $p->recipient['company'] ?? $p->client?->name,
            'status' => $p->effectiveStatus(), 'status_label' => Proposal::STATUSES[$p->effectiveStatus()] ?? $p->status,
            'status_color' => Proposal::STATUS_COLORS[$p->effectiveStatus()] ?? '#8A8A8A',
            'currency' => $p->currency, 'total_net' => $p->total_net,
            'lead' => $p->lead ? ['id' => $p->lead->id, 'name' => $p->lead->full_name] : null,
        ])->values()]);
    }

    /** Asocia una propuesta ya existente a un lead (si estaba en otro, se mueve). */
    public function attach(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);
        $data = $request->validate(['proposal_id' => ['required', 'integer']]);
        $proposal = Proposal::visibleTo($request->user())->findOrFail($data['proposal_id']);
        $previous = $proposal->lead_id && $proposal->lead_id !== $lead->id ? Lead::find($proposal->lead_id) : null;

        $proposal->lead_id = $lead->id;
        if (! $proposal->client_id && $lead->client_id) {
            $proposal->client_id = $lead->client_id;
        }
        $proposal->save();

        $leads = app(LeadService::class);
        $leads->log($lead, 'proposal', $request->user(), "Propuesta {$proposal->number} asociada a este prospecto", ['proposal_id' => $proposal->id]);
        if ($previous) {
            $leads->log($previous, 'proposal', $request->user(), "Propuesta {$proposal->number} pasó a otro prospecto", ['proposal_id' => $proposal->id]);
        }

        return response()->json(['ok' => true]);
    }

    /** Quita la asociación de una propuesta con el lead (la propuesta no se elimina). */
    public function detach(Request $request, Lead $lead, Proposal $proposal): JsonResponse
    {
        $this->authorize('view', $lead);
        abort_unless($proposal->lead_id === $lead->id, 404);
        abort_unless(Proposal::visibleTo($request->user())->whereKey($proposal->id)->exists(), 403);

        $proposal->update(['lead_id' => null]);
        app(LeadService::class)->log($lead, 'proposal', $request->user(), "Propuesta {$proposal->number} desvinculada de este prospecto", ['proposal_id' => $proposal->id]);

        return response()->json(['ok' => true]);
    }

    // ------------------------------------------------------------------------------------------

    public static function pdfResponse(Proposal $proposal, ProposalPdf $pdf): \Illuminate\Http\Response
    {
        $name = ProposalView::data($proposal)['fileName'];

        return response($pdf->render($proposal), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function clearResponse(Proposal $proposal): void
    {
        $proposal->forceFill(['responded_at' => null, 'responded_by' => null, 'signer_rut' => null, 'response_note' => null, 'signature_data' => null, 'response_ip' => null, 'response_user_agent' => null]);
    }

    private function markSent(Proposal $proposal, User $user, string $log): void
    {
        $wasDraft = $proposal->status === 'draft';
        if ($proposal->status === 'changes_requested') {
            $this->clearResponse($proposal); // nueva versión enviada tras los ajustes pedidos
        }
        $proposal->forceFill(['status' => in_array($proposal->status, ['viewed'], true) ? 'viewed' : 'sent', 'sent_at' => now(), 'issued_at' => $proposal->issued_at ?? now()])->save();

        // Al emitir, la UF del día queda guardada en la propuesta (valor y fecha de referencia).
        if ($wasDraft) {
            $this->builder->freezeUf($proposal);
        }

        // Si el lead aún no tiene valor estimado, se usa el de la propuesta (en pesos).
        if ($proposal->lead && ! $proposal->lead->estimated_value && $proposal->total_net > 0) {
            app(LeadService::class)->update($proposal->lead, ['estimated_value' => $proposal->clp($proposal->total_net)], $user);
        }
        $this->touchLead($proposal, $user, $log);
    }

    private function touchLead(Proposal $proposal, User $user, string $text): void
    {
        if ($proposal->lead) {
            app(LeadService::class)->log($proposal->lead, 'proposal', $user, $text, ['proposal_id' => $proposal->id]);
        }
    }

    private function authorizeView(Request $request, Proposal $proposal): void
    {
        abort_unless(Proposal::visibleTo($request->user())->whereKey($proposal->id)->exists(), 403);
    }

    private function authorizeLead(Request $request, ?int $leadId): void
    {
        if ($leadId) {
            abort_unless(Lead::visibleTo($request->user())->whereKey($leadId)->exists(), 403, 'No tienes acceso a ese lead.');
        }
    }

    /** @return array<string, mixed> */
    private function row(Proposal $p): array
    {
        $status = $p->effectiveStatus();

        return [
            'id' => $p->id,
            'number' => $p->number,
            'title' => $p->title,
            'status' => $status,
            'status_label' => Proposal::STATUSES[$status],
            'status_color' => Proposal::STATUS_COLORS[$status],
            'company' => $p->recipient['company'] ?? $p->client?->name,
            'contact' => $p->recipient['contact_name'] ?? null,
            'client' => $p->client?->only(['id', 'name']),
            'lead' => $p->lead ? ['id' => $p->lead->id, 'name' => $p->lead->full_name] : null,
            'owner' => $p->owner?->name,
            'currency' => $p->currency,
            'uf_value' => $p->uf_value,
            'uf_date' => $p->uf_date?->toDateString(),
            'total_net' => $p->total_net,
            'total_gross' => $p->total_gross,
            'total_gross_clp' => $p->clp($p->total_gross),
            'total_monthly' => $p->total_monthly,
            'total_one_time' => $p->total_one_time,
            'valid_until' => $p->valid_until?->toDateString(),
            'issued_at' => $p->issued_at?->toDateString(),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, bool> */
    private function can(User $user): array
    {
        return [
            'create' => $user->hasPermission('proposals.create'),
            'send' => $user->hasPermission('proposals.send'),
            'delete' => $user->hasPermission('proposals.delete'),
            'services' => $user->hasPermission('services.view'),
        ];
    }

    /** @param array<string, mixed> $initial @return array<string, mixed> */
    private function builderProps(Request $request, ?Proposal $proposal, array $initial): array
    {
        $user = $request->user();

        return [
            'proposal' => $proposal ? ['id' => $proposal->id, 'number' => $proposal->number, 'status' => $proposal->effectiveStatus(), 'is_final' => $proposal->isFinal()] : null,
            'initial' => $initial,
            'uf' => app(UfService::class)->today(),
            'taxModes' => Proposal::TAX_MODES,
            'retentionRate' => Honorarios::rate(),
            'services' => Service::where('is_active', true)->orderBy('category')->orderBy('sort_order')->get()
                ->map(fn (Service $s) => $s->only(['id', 'name', 'category', 'description', 'deliverables', 'billing', 'unit', 'currency', 'price'])),
            'clients' => Client::where('is_active', true)->orderBy('name')->get()->map(fn (Client $c) => [
                'id' => $c->id, 'name' => $c->name, 'recipient' => ProposalBuilder::recipientFor($c, null),
            ]),
            'lead' => ($initial['lead_id'] ?? null) ? Lead::visibleTo($user)->find($initial['lead_id'])?->only(['id', 'first_name', 'last_name', 'company']) : null,
            'ai' => ['enabled' => $user->hasPermission('ai.use') && app(AiGateway::class)->isAvailable(), 'can_configure' => $user->hasPermission('ai.manage')],
            'can' => $this->can($user),
        ];
    }

    /** @return array<string, mixed> */
    private function rules(bool $strict = true): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'recipient' => ['nullable', 'array'],
            'recipient.company' => ['nullable', 'string', 'max:200'],
            'recipient.legal_name' => ['nullable', 'string', 'max:200'],
            'recipient.rut' => ['nullable', 'string', 'max:20', function ($a, $v, $fail) use ($strict) {
                if ($strict && $v && ! Rut::isValid($v)) {
                    $fail('El RUT del destinatario no es válido.');
                }
            }],
            'recipient.activity' => ['nullable', 'string', 'max:255'],
            'recipient.address' => ['nullable', 'string', 'max:300'],
            'recipient.contact_name' => ['nullable', 'string', 'max:200'],
            'recipient.contact_role' => ['nullable', 'string', 'max:200'],
            'recipient.email' => ['nullable', 'string', 'max:200'],
            'recipient.phone' => ['nullable', 'string', 'max:40'],
            'sections' => ['nullable', 'array', 'max:15'],
            'sections.*.title' => ['nullable', 'string', 'max:160'],
            'sections.*.body' => ['nullable', 'string', 'max:30000'],
            'valid_until' => ['nullable', 'date'],
            'contract_months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'currency' => ['required', 'in:UF,CLP'],
            'discount_type' => ['required', 'in:percent,amount'],
            'discount_value' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'tax_mode' => ['nullable', 'in:'.implode(',', array_keys(Proposal::TAX_MODES))],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:30'],
            'internal_notes' => ['nullable', 'string', 'max:20000'],
            'items' => ['nullable', 'array', 'max:40'],
            'items.*.service_id' => ['nullable', 'integer', 'exists:services,id'],
            'items.*.name' => ['required', 'string', 'max:200'],
            'items.*.description' => ['nullable', 'string', 'max:20000'],
            'items.*.deliverables' => ['nullable', 'array', 'max:20'],
            'items.*.deliverables.*' => ['nullable', 'string', 'max:200'],
            'items.*.billing' => ['required', 'in:one_time,monthly'],
            'items.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999999'],
            'items.*.discount_pct' => ['nullable', 'integer', 'min:0', 'max:100'],
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate($this->rules(), [
            'title.required' => 'Ponle un título a la propuesta.',
            'items.*.name.required' => 'Cada servicio necesita un nombre.',
        ]);
    }
}
