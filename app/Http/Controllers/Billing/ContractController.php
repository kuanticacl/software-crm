<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClientServiceRequest;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\Proposal;
use App\Models\Service;
use App\Services\Billing\ContractService;
use App\Services\UfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Servicios contratados por los clientes (vista interna: incluye precios, costos y gastos según permisos). */
class ContractController extends Controller
{
    public function __construct(private ContractService $contracts) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'client', 'status', 'cycle']);

        $services = ClientService::query()
            ->with(['client:id,name', 'parent:id,name'])
            ->withCount('children')
            ->when($filters['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%{$t}%")->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$t}%"))))
            ->when($filters['client'] ?? null, fn ($q, $c) => $q->where('client_id', $c))
            ->when($filters['cycle'] ?? null, fn ($q, $c) => $q->where('billing_cycle', $c))
            ->when($filters['status'] ?? null, fn ($q, $s) => match ($s) {
                // Estados deducidos de las fechas.
                'ended' => $q->whereIn('status', ['active', 'pending_payment'])->where('auto_renew', false)->whereDate('end_date', '<', today()),
                'pending' => $q->where('status', 'active')->whereDate('start_date', '>', today()),
                // Activo: marcado así y dentro de sus fechas.
                'active' => $q->where('status', 'active')->whereDate('start_date', '<=', today())->where(fn ($w) => $w->whereNull('end_date')->orWhere('auto_renew', true)->orWhereDate('end_date', '>=', today())),
                default => $q->where('status', $s),
            })
            ->orderByRaw("case when status = 'active' then 0 when status = 'pending_payment' then 1 when status = 'blocked' then 2 else 3 end")
            ->orderBy('next_charge_on')->orderByDesc('id')
            ->paginate(20)->withQueryString()
            ->through(fn (ClientService $s) => $this->row($s));

        return Inertia::render('contracts/Index', [
            'services' => $services,
            'filters' => $filters,
            'clients' => Client::orderBy('name')->get(['id', 'name', 'kind']),
            'kpis' => $this->kpis(),
            'meta' => ['cycles' => ClientService::CYCLES, 'statuses' => [...ClientService::STATUSES, ...ClientService::DERIVED]],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('contracts/Form', [
            'service' => null,
            'defaults' => ['client_id' => $request->integer('client') ?: null, 'proposal_id' => $request->integer('proposal') ?: null],
            ...$this->formData(),
        ]);
    }

    public function store(ClientServiceRequest $request): RedirectResponse
    {
        $service = $this->contracts->create($request->validated(), $request->user());

        $this->toast("Servicio «{$service->name}» registrado.");

        return to_route('contracts.show', $service);
    }

    public function show(Request $request, ClientService $clientService): Response
    {
        $clientService->load(['client:id,name,legal_name,tax_id', 'proposal:id,number,title,status', 'parent:id,name', 'children']);
        $canCosts = $request->user()->hasPermission('contracts.costs');

        $invoices = $clientService->invoices()->orderByDesc('due_date')->get()->map(fn ($i) => app(InvoiceController::class)->present($i));

        return Inertia::render('contracts/Show', [
            'service' => [
                ...$this->row($clientService),
                'description' => $clientService->description,
                'works_budget' => $clientService->works_budget,
                'internal_notes' => $clientService->internal_notes,
                'payment_link' => $clientService->payment_link,
                'reminder_offsets' => $clientService->reminder_offsets,
                'billing_day' => $clientService->billing_day,
                'proposal' => $clientService->proposal ? ['id' => $clientService->proposal->id, 'number' => $clientService->proposal->number, 'title' => $clientService->proposal->title] : null,
                'client' => $clientService->client?->only(['id', 'name', 'legal_name', 'tax_id']),
                'children' => $clientService->children->map(fn ($c) => $this->row($c))->values(),
            ],
            'invoices' => $invoices,
            'expenses' => $canCosts ? $clientService->expenses()->orderByDesc('incurred_on')->get()->map(fn ($e) => [
                'id' => $e->id, 'concept' => $e->concept, 'currency' => $e->currency, 'amount' => $e->amount, 'amount_clp' => $e->amount_clp,
                'incurred_on' => $e->incurred_on->toDateString(), 'notes' => $e->notes,
            ]) : null,
            'financials' => $canCosts ? $this->contracts->financials($clientService) : null,
            'can' => ['costs' => $canCosts],
            'taxRate' => config('portal.tax_rate'),
        ]);
    }

    public function edit(ClientService $clientService): Response
    {
        return Inertia::render('contracts/Form', [
            'service' => [
                ...$clientService->only(['id', 'client_id', 'proposal_id', 'catalog_service_id', 'parent_id', 'name', 'description', 'project_address', 'billing_cycle', 'modality', 'currency', 'price', 'works_budget', 'auto_renew', 'status', 'billing_day', 'reminder_offsets', 'payment_link', 'internal_notes']),
                'start_date' => $clientService->start_date?->toDateString(),
                'end_date' => $clientService->end_date?->toDateString(),
            ],
            'defaults' => null,
            ...$this->formData(),
        ]);
    }

    public function update(ClientServiceRequest $request, ClientService $clientService): RedirectResponse
    {
        $this->contracts->update($clientService, $request->validated());

        $this->toast('Servicio actualizado.');

        return to_route('contracts.show', $clientService);
    }

    public function destroy(ClientService $clientService): RedirectResponse
    {
        if ($clientService->children()->exists() || $clientService->invoices()->whereIn('status', ['issued', 'paid'])->exists()) {
            $this->toast('Tiene servicios asociados o facturas emitidas: ponlo en estado «Cancelado» o «Finalizado» en vez de eliminarlo.', 'error');

            return back();
        }

        $clientService->invoices()->where('status', 'scheduled')->get()->each->delete();
        $clientService->delete();
        $this->toast('Servicio eliminado.');

        return to_route('contracts.index');
    }

    /** @return array<string, mixed> */
    public function row(ClientService $s): array
    {
        return [
            'id' => $s->id, 'name' => $s->name, 'client' => $s->relationLoaded('client') ? $s->client?->only(['id', 'name']) : null,
            'billing_cycle' => $s->billing_cycle, 'modality' => $s->modality, 'project_address' => $s->project_address, 'currency' => $s->currency, 'price' => $s->price,
            'start_date' => $s->start_date?->toDateString(), 'end_date' => $s->end_date?->toDateString(), 'auto_renew' => $s->auto_renew,
            'status' => $s->effectiveStatus(), 'next_charge_on' => $s->next_charge_on?->toDateString(),
            'parent' => $s->relationLoaded('parent') ? $s->parent?->only(['id', 'name']) : null,
            'children_count' => $s->children_count ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'clients' => Client::where('is_active', true)->orderBy('name')->get(['id', 'name', 'kind']),
            'proposals' => Proposal::whereNotIn('status', ['draft'])->orWhereNotNull('client_id')->orderByDesc('id')->limit(300)->get(['id', 'number', 'title', 'client_id']),
            'parents' => ClientService::whereNull('parent_id')->orderBy('name')->get(['id', 'name', 'client_id']),
            'catalog' => Service::where('is_active', true)->orderBy('category')->orderBy('name')->get(['id', 'name', 'description', 'billing', 'unit', 'currency', 'price']),
            'meta' => ['cycles' => ClientService::CYCLES, 'statuses' => ClientService::STATUSES, 'modalities' => ClientService::MODALITIES],
            'taxRate' => config('portal.tax_rate'),
            'defaultOffsets' => \App\Services\Billing\ReminderRunner::defaultOffsets(),
        ];
    }

    /** @return array<string, mixed> */
    private function kpis(): array
    {
        $uf = app(UfService::class)->today()['value'] ?? 0;
        $mrr = 0.0;
        ClientService::whereIn('status', ['active', 'pending_payment'])->where('billing_cycle', '!=', 'one_time')->get(['billing_cycle', 'currency', 'price'])->each(function ($s) use ($uf, &$mrr) {
            $clp = $s->currency === 'UF' ? $s->price * $uf : $s->price;
            $mrr += match ($s->billing_cycle) { 'monthly' => $clp, 'quarterly' => $clp / 3, 'yearly' => $clp / 12, default => 0 };
        });

        return [
            'active' => ClientService::whereIn('status', ['active', 'pending_payment'])->count(),
            'recurring_monthly_clp' => round($mrr),
            'ending_soon' => ClientService::whereIn('status', ['active', 'pending_payment'])->where('auto_renew', false)->whereBetween('end_date', [today(), today()->addDays(30)])->count(),
        ];
    }
}
