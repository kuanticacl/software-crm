<?php

namespace App\Http\Controllers\RealEstate;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Unit;
use App\Models\User;
use App\Services\Email\DefaultTemplates;
use App\Services\Email\EmailService;
use App\Services\LeadService;
use App\Services\RealEstate\QuoteBuilder;
use App\Services\RealEstate\QuotePdf;
use App\Services\RealEstate\QuoteView;
use App\Services\UfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Cotizaciones: unidad principal + complementos, pie en cuotas, reserva y simulación del crédito. */
class QuoteController extends Controller
{
    public function __construct(private QuoteBuilder $builder) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = ['q' => (string) $request->input('q', ''), 'status' => (string) $request->input('status', ''), 'project' => (string) $request->input('project', '')];

        $base = Quote::query()->visibleTo($user)->when($filters['project'], fn ($q, $p) => $q->where('project_id', (int) $p));
        $rows = (clone $base)->with(['project:id,name,color', 'owner:id,name', 'items', 'lead:id,first_name,last_name', 'client:id,name'])
            ->when($filters['q'], fn ($q, $t) => $q->where(fn ($q) => $q->where('number', 'like', "%{$t}%")->orWhere('buyer', 'like', "%{$t}%")->orWhereHas('items', fn ($i) => $i->where('code', 'like', "%{$t}%"))))
            ->when($filters['status'] && $filters['status'] !== 'expired', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['status'] === 'expired', fn ($q) => $q->whereIn('status', ['sent', 'viewed'])->whereDate('valid_until', '<', now()))
            ->latest('id')->paginate(20)->withQueryString();
        $rows->getCollection()->transform(fn (Quote $q) => $this->row($q));

        $summary = (clone $base)->selectRaw('status, count(*) c, sum(total_uf) uf')->groupBy('status')->get()->keyBy('status');

        return Inertia::render('realestate/quotes/Index', [
            'quotes' => $rows,
            'filters' => $filters,
            'projects' => Project::visibleTo($user)->orderBy('name')->get(['id', 'name']),
            'statuses' => Quote::STATUSES,
            'statusColors' => Quote::STATUS_COLORS,
            'summary' => collect(Quote::STATUSES)->map(fn ($label, $k) => ['label' => $label, 'count' => (int) ($summary[$k]->c ?? 0), 'uf' => (float) ($summary[$k]->uf ?? 0)])->all(),
            'can' => ['create' => $user->hasPermission('quotes.create')],
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        $lead = $request->integer('lead') ? Lead::visibleTo($user)->find($request->integer('lead')) : null;
        $client = $request->integer('client') ? Client::find($request->integer('client')) : null;
        $unit = $request->integer('unit') ? Unit::find($request->integer('unit')) : null;
        $project = Project::visibleTo($user)->find($unit?->project_id ?? $request->integer('project')) ?? Project::visibleTo($user)->where('is_active', true)->orderBy('sort_order')->first();

        return Inertia::render('realestate/quotes/Builder', $this->builderProps($user, null, [
            'project_id' => $project?->id,
            'lead_id' => $lead?->id,
            'client_id' => $client?->id ?? $lead?->client_id,
            'buyer' => QuoteBuilder::buyerFor($client ?? $lead?->client, $lead),
            'items' => $unit && $unit->status === 'disponible' ? [['unit_id' => $unit->id, 'discount_pct' => 0]] : [],
            'pie_pct' => $project?->default_pie_pct ?? 20,
            'pie_installments' => $project?->default_pie_installments ?? 24,
            'reservation_amount' => $project?->reservation_amount ?? 0,
            'mortgage_rate' => $project?->mortgage_rate ?? 4.5,
            'mortgage_years' => $project?->mortgage_years ?? 25,
            'valid_until' => now()->addDays($project?->quote_validity_days ?? 7)->toDateString(),
            'notes' => '',
            'internal_notes' => '',
        ], $lead));
    }

    public function edit(Request $request, Quote $quote): Response
    {
        $this->authorizeView($request, $quote);
        abort_if($quote->status === 'reserved', 422, 'La cotización ya tiene una reserva: crea una nueva si cambian las condiciones.');
        $quote->load('items');

        return Inertia::render('realestate/quotes/Builder', $this->builderProps($request->user(), $quote, [
            ...$quote->only(['project_id', 'lead_id', 'client_id', 'pie_pct', 'pie_installments', 'reservation_amount', 'mortgage_rate', 'mortgage_years', 'notes', 'internal_notes']),
            'buyer' => $quote->buyer ?? [],
            'valid_until' => $quote->valid_until?->toDateString(),
            'items' => $quote->items->map(fn ($i) => ['unit_id' => $i->unit_id, 'discount_pct' => $i->discount_pct])->filter(fn ($i) => $i['unit_id'])->values(),
        ], $quote->lead));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->authorizeProject($request, (int) $data['project_id']);
        $quote = $this->builder->save($data, null, $request->user());
        $this->toast("Cotización {$quote->number} creada.");

        return redirect("/quotes/{$quote->id}");
    }

    public function update(Request $request, Quote $quote): RedirectResponse
    {
        $this->authorizeView($request, $quote);
        abort_if($quote->status === 'reserved', 422, 'La cotización ya tiene una reserva.');
        $data = $this->validated($request);
        $this->authorizeProject($request, (int) $data['project_id']);
        $this->builder->save($data, $quote, $request->user());
        $this->toast('Cotización actualizada.');

        return redirect("/quotes/{$quote->id}");
    }

    public function show(Request $request, Quote $quote): Response
    {
        $this->authorizeView($request, $quote);
        $user = $request->user();
        $quote->load(['project', 'items.unit', 'owner:id,name,email,phone', 'lead:id,first_name,last_name,email,phone', 'client:id,name', 'reservation:id,quote_id,number,status']);
        $v = QuoteView::data($quote);

        return Inertia::render('realestate/quotes/Show', [
            'quote' => [
                ...$this->row($quote),
                ...$quote->only(['pie_pct', 'pie_uf', 'reservation_amount', 'pie_installments', 'credit_uf', 'mortgage_rate', 'mortgage_years', 'monthly_payment_uf', 'subtotal_uf', 'discount_uf', 'notes', 'internal_notes', 'view_count', 'lost_reason']),
                'buyer' => $quote->buyer ?? [],
                'public_url' => $quote->publicUrl(),
                'sent_at' => $quote->sent_at?->toIso8601String(),
                'viewed_at' => $quote->viewed_at?->toIso8601String(),
                'reservation_uf' => $v['reservationUf'],
                'installment_uf' => $v['installment'],
                'items' => $quote->items->map(fn ($i) => [
                    'id' => $i->id, 'unit_id' => $i->unit_id, 'label' => $i->label(), 'detail' => $i->detail, 'list_price_uf' => $i->list_price_uf, 'discount_pct' => $i->discount_pct, 'price_uf' => $i->price_uf,
                    'unit_status' => $i->unit?->status, 'floor_plan' => $i->unit?->floorPlanUrl(),
                ]),
                'lead' => $quote->lead ? ['id' => $quote->lead->id, 'name' => $quote->lead->full_name, 'email' => $quote->lead->email, 'phone' => $quote->lead->phone] : null,
                'reservation' => $quote->reservation?->only(['id', 'number', 'status']),
            ],
            'unitStatuses' => Unit::STATUSES,
            'statuses' => Quote::STATUSES,
            'can' => [
                'edit' => $user->hasPermission('quotes.create') && $quote->status !== 'reserved',
                'send' => $user->hasPermission('quotes.send'),
                'delete' => $user->hasPermission('quotes.delete') && $quote->status !== 'reserved',
                'reserve' => $user->hasPermission('reservations.create') && ! $quote->reservation && $quote->status !== 'lost',
            ],
        ]);
    }

    public function pdf(Request $request, Quote $quote, QuotePdf $pdf): \Illuminate\Http\Response
    {
        $this->authorizeView($request, $quote);

        return $this->pdfResponse($quote, $pdf);
    }

    public function send(Request $request, Quote $quote, EmailService $emails): RedirectResponse
    {
        $this->authorizeView($request, $quote);
        $data = $request->validate([
            'to' => ['required', 'email:rfc'],
            'name' => ['nullable', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:2000'],
        ], ['to.required' => 'Indica el correo del destinatario.', 'to.email' => 'El correo no es válido.']);

        $quote->loadMissing(['project', 'items', 'owner']);
        $this->freeze($quote);
        $template = app(DefaultTemplates::class)->ensure('cotizacion-inmobiliaria');
        $emails->queueTemplate($template, $data['to'], $data['name'] ?? null, [
            'first_name' => explode(' ', trim((string) ($data['name'] ?? '')))[0] ?? '',
            'project_name' => $quote->project?->name,
            'unit_label' => $quote->items->map(fn ($i) => $i->label())->implode(' + '),
            'quote_number' => $quote->number,
            'total_uf' => QuoteView::uf($quote->total_uf),
            'quote_url' => $quote->publicUrl(),
            'valid_until' => $quote->valid_until?->locale('es')->translatedFormat('d \d\e F \d\e Y') ?? '',
            'executive' => $quote->owner?->name ?? '',
            'message' => $data['message'],
        ], ['lead_id' => $quote->lead_id, 'track_opens' => true]);

        $this->markSent($quote, $request->user(), "Cotización {$quote->number} enviada a {$data['to']}");
        $this->toast("Cotización enviada a {$data['to']}.");

        return back();
    }

    /** Marcar como enviada sin correo (se compartió por WhatsApp). */
    private function markSent(Quote $quote, ?User $user = null, ?string $log = null): void
    {
        $this->freeze($quote);
        if (in_array($quote->status, ['draft', 'expired'], true)) {
            $quote->update(['status' => 'sent', 'sent_at' => now()]);
        }
        if ($quote->lead && $user) {
            app(LeadService::class)->log($quote->lead, 'quote', $user, $log ?? "Cotización {$quote->number} compartida");
        }
    }

    public function shared(Request $request, Quote $quote): RedirectResponse
    {
        $this->authorizeView($request, $quote);
        $this->markSent($quote, $request->user(), "Cotización {$quote->number} compartida con el cliente");
        $this->toast('Cotización marcada como enviada.');

        return back();
    }

    public function status(Request $request, Quote $quote): RedirectResponse
    {
        $this->authorizeView($request, $quote);
        $data = $request->validate(['status' => ['required', 'in:lost,sent'], 'lost_reason' => ['nullable', 'string', 'max:200']]);
        abort_if($quote->status === 'reserved', 422, 'La cotización tiene una reserva: gestiónala desde la reserva.');
        $quote->update(['status' => $data['status'], 'lost_reason' => $data['status'] === 'lost' ? ($data['lost_reason'] ?? null) : null]);
        $this->toast($data['status'] === 'lost' ? 'Cotización marcada como perdida.' : 'Cotización reabierta.');

        return back();
    }

    public function duplicate(Request $request, Quote $quote): RedirectResponse
    {
        $this->authorizeView($request, $quote);

        return redirect('/quotes/create?'.http_build_query(array_filter(['lead' => $quote->lead_id, 'client' => $quote->client_id, 'project' => $quote->project_id])));
    }

    public function destroy(Request $request, Quote $quote): RedirectResponse
    {
        $this->authorizeView($request, $quote);
        abort_if($quote->status === 'reserved', 422, 'La cotización tiene una reserva: no se puede eliminar.');
        $quote->delete();
        $this->toast("Cotización {$quote->number} eliminada.");

        return redirect('/quotes');
    }

    // ---------------------------------------------------------------- público (sin sesión)

    public function publicShow(string $token): \Illuminate\Http\Response
    {
        $quote = Quote::where('public_token', $token)->firstOrFail();
        // Las visitas solo cuentan si fue enviada y no es alguien del equipo con sesión.
        if (! in_array($quote->status, ['draft'], true) && ! auth()->check()) {
            $quote->forceFill(['view_count' => $quote->view_count + 1, 'viewed_at' => $quote->viewed_at ?? now(), 'status' => $quote->status === 'sent' ? 'viewed' : $quote->status])->saveQuietly();
            if ($quote->view_count === 1 && $quote->lead) {
                app(LeadService::class)->log($quote->lead, 'quote', null, "Vio la cotización {$quote->number}");
            }
        }

        return response()->view('realestate.quote', QuoteView::data($quote) + ['pdfUrl' => url('/c/'.$token.'/pdf')])
            ->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'private, no-store');
    }

    public function publicPdf(string $token, QuotePdf $pdf): \Illuminate\Http\Response
    {
        return $this->pdfResponse(Quote::where('public_token', $token)->firstOrFail(), $pdf);
    }

    // ------------------------------------------------------------------------------------------------

    private function pdfResponse(Quote $quote, QuotePdf $pdf): \Illuminate\Http\Response
    {
        return response($pdf->render($quote), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="cotizacion-'.$quote->number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Al enviar, la UF y la vigencia quedan fijas. */
    private function freeze(Quote $quote): void
    {
        if ($quote->status === 'draft' && ($uf = app(UfService::class)->today())) {
            $quote->forceFill(['uf_value' => $uf['value'], 'uf_date' => $uf['date']])->save();
        }
    }

    /** @return array<string, mixed> */
    private function row(Quote $q): array
    {
        $status = $q->effectiveStatus();
        $main = $q->items->first();

        return [
            'id' => $q->id,
            'number' => $q->number,
            'status' => $status,
            'status_label' => Quote::STATUSES[$status],
            'status_color' => Quote::STATUS_COLORS[$status],
            'project' => $q->project?->only(['id', 'name', 'color']),
            'buyer_name' => $q->buyerName(),
            'units' => $q->items->map(fn ($i) => $i->label())->implode(' + '),
            'main_label' => $main?->label(),
            'total_uf' => $q->total_uf,
            'uf_value' => $q->uf_value,
            'total_clp' => $q->clp($q->total_uf),
            'owner' => $q->owner?->name,
            'valid_until' => $q->valid_until?->toDateString(),
            'created_at' => $q->created_at?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $initial @return array<string, mixed> */
    private function builderProps(User $user, ?Quote $quote, array $initial, ?Lead $lead): array
    {
        $projects = Project::visibleTo($user)->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $initial['project_id'] ?? 0))->orderBy('sort_order')->orderBy('name')->get();
        $keep = $quote ? $quote->items->pluck('unit_id')->filter()->all() : [];

        return [
            'quote' => $quote?->only(['id', 'number', 'status']),
            'initial' => $initial,
            'projects' => $projects->map(fn (Project $p) => [
                ...$p->only(['id', 'name', 'color', 'reservation_amount', 'default_pie_pct', 'default_pie_installments', 'max_discount_pct', 'mortgage_rate', 'mortgage_years', 'quote_validity_days']),
            ]),
            // Inventario cotizable: lo disponible de los proyectos visibles (y lo que ya está en la cotización).
            'units' => Unit::whereIn('project_id', $projects->pluck('id'))->where(fn ($q) => $q->where('status', 'disponible')->orWhereIn('id', $keep ?: [0]))
                ->orderBy('type')->orderBy('floor')->orderBy('code')->get()
                ->map(fn (Unit $u) => [
                    'id' => $u->id, 'project_id' => $u->project_id, 'type' => $u->type, 'code' => $u->code, 'model' => $u->model, 'floor' => $u->floor, 'tower' => $u->tower,
                    'program' => $u->programLabel(), 'surface_useful' => $u->surface_useful, 'surface_total' => $u->surface_total, 'orientation' => $u->orientation,
                    'list_price' => $u->list_price, 'status' => $u->status, 'is_main' => $u->isMain(), 'floor_plan' => $u->floorPlanUrl(), 'detail' => $u->detail(),
                ]),
            'lead' => $lead ? ['id' => $lead->id, 'name' => $lead->full_name, 'email' => $lead->email, 'phone' => $lead->phone] : null,
            'clients' => Client::where('is_active', true)->orderBy('name')->limit(500)->get(['id', 'name', 'tax_id', 'email', 'phone']),
            'uf' => app(UfService::class)->today(),
            'types' => Unit::TYPES,
            'executives' => $user->hasPermission('quotes.view_all') ? User::where('is_active', true)->whereHas('role', fn ($q) => $q->staff())->orderBy('name')->get(['id', 'name']) : [],
            'can' => ['discount_any' => $user->hasPermission('quotes.discount')],
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'buyer' => ['nullable', 'array'],
            'buyer.name' => ['nullable', 'string', 'max:160'],
            'buyer.rut' => ['nullable', 'string', 'max:20', fn ($a, $v, $fail) => $v && ! \App\Support\Rut::isValid($v) ? $fail('El RUT no es válido.') : null],
            'buyer.email' => ['nullable', 'email:rfc', 'max:200'],
            'buyer.phone' => ['nullable', 'string', 'max:40'],
            'items' => ['required', 'array', 'min:1', 'max:15'],
            'items.*.unit_id' => ['required', 'integer'],
            'items.*.discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pie_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'pie_installments' => ['required', 'integer', 'min:0', 'max:120'],
            'reservation_amount' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'mortgage_rate' => ['required', 'numeric', 'min:0', 'max:30'],
            'mortgage_years' => ['required', 'integer', 'min:1', 'max:40'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'internal_notes' => ['nullable', 'string', 'max:3000'],
        ], ['items.required' => 'Agrega al menos una unidad.', 'items.min' => 'Agrega al menos una unidad.']);
    }

    private function authorizeView(Request $request, Quote $quote): void
    {
        abort_unless(Quote::visibleTo($request->user())->whereKey($quote->id)->exists(), 403, 'No tienes acceso a esta cotización.');
    }

    private function authorizeProject(Request $request, int $projectId): void
    {
        abort_unless(Project::visibleTo($request->user())->whereKey($projectId)->exists(), 403, 'No eres ejecutivo/a de ese proyecto.');
    }
}
