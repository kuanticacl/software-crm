<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientRequest;
use App\Models\Client;
use App\Services\CascadeDelete;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'status']);

        $clients = Client::query()
            ->withCount(['leads', 'proposals', 'services', 'invoices'])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhere('legal_name', 'like', "%{$term}%")
                ->orWhere('tax_id', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")))
            ->when(($filters['status'] ?? '') !== '', fn ($q) => $q->where('is_active', ($filters['status'] ?? '') === 'active'))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('clients/Index', [
            'clients' => $clients,
            'filters' => $filters,
            'transferTargets' => Client::orderBy('name')->limit(500)->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('clients/Form', ['client' => null]);
    }

    public function store(ClientRequest $request): RedirectResponse
    {
        $client = Client::create([...$request->validated(), 'created_by' => $request->user()->id]);

        $this->toast("Cliente «{$client->name}» creada.");

        return to_route('clients.show', $client);
    }

    public function show(Request $request, Client $client): Response
    {
        $leads = $client->leads()
            ->visibleTo($request->user())
            ->with(['stage:id,name,color', 'source:id,name,color', 'assignee:id,name'])
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn ($lead) => [
                'id' => $lead->id,
                'full_name' => $lead->full_name,
                'email' => $lead->email,
                'stage' => $lead->stage,
                'source' => $lead->source,
                'assignee' => $lead->assignee?->name,
                'created_at' => $lead->created_at->toIso8601String(),
            ]);

        $user = $request->user();
        $contracts = app(\App\Http\Controllers\Billing\ContractController::class);
        $invoices = app(\App\Http\Controllers\Billing\InvoiceController::class);

        return Inertia::render('clients/Show', [
            'client' => [...$client->load('creator:id,name')->toArray(), 'notes_html' => \App\Support\ProposalText::html($client->notes)],
            'leads' => $leads,
            'services' => $user->hasPermission('contracts.view')
                ? $client->services()->with(['parent:id,name'])->withCount('children')->orderByDesc('id')->get()->map(fn ($s) => $contracts->row($s))
                : null,
            'invoices' => $user->hasPermission('billing.view')
                ? $client->invoices()->with('service:id,name')->orderByDesc('due_date')->limit(30)->get()->map(fn ($i) => $invoices->present($i))
                : null,
            'portal' => $user->hasPermission('portal.manage') ? [
                'url' => \App\Support\PortalUrl::login(),
                'users' => $client->portalUsers()->orderBy('name')->get()->map(fn ($u) => [
                    'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'is_active' => $u->is_active,
                    'must_change_password' => $u->must_change_password, 'last_login_at' => $u->last_login_at?->toIso8601String(), 'invited_at' => $u->portal_invited_at?->toIso8601String(),
                ]),
            ] : null,
            'billingLookups' => [
                'clients' => [['id' => $client->id, 'name' => $client->name, 'kind' => $client->kind]],
                'services' => $client->services()->get(['id', 'name', 'client_id', 'currency', 'price']),
                'taxRate' => config('portal.tax_rate'),
            ],
        ]);
    }

    public function edit(Client $client): Response
    {
        return Inertia::render('clients/Form', ['client' => $client]);
    }

    public function update(ClientRequest $request, Client $client): RedirectResponse
    {
        $client->update($request->validated());

        $this->toast("Cliente «{$client->name}» actualizada.");

        return to_route('clients.show', $client);
    }

    public function destroy(Request $request, Client $client, CascadeDelete $cascade): RedirectResponse
    {
        $leads = $client->leads()->count();
        $proposals = $client->proposals()->count();
        $billing = $client->services()->count() + $client->invoices()->count();

        if (($leads || $proposals || $billing) && ! $request->boolean('confirm_related')) {
            $this->toast('El cliente tiene datos relacionados: confirma la eliminación para enviarlos a la papelera.', 'error');

            return back();
        }

        $target = $request->filled('transfer_to') && ($leads || $proposals || $billing)
            ? Client::findOrFail($request->validate([
                'transfer_to' => ['integer', Rule::exists('clients', 'id')->whereNull('deleted_at'), Rule::notIn([$client->id])],
            ])['transfer_to'])
            : null;

        $cascade->client($client, $target);

        if ($target) {
            $this->toast("Cliente eliminado. {$leads} cliente(s) y {$proposals} propuesta(s) pasaron a «{$target->name}».");

            return to_route('clients.index');
        }

        $this->toast($leads || $proposals
            ? "Cliente eliminado junto con {$leads} cliente(s) y {$proposals} propuesta(s) relacionados."
            : 'Cliente eliminado.');

        return to_route('clients.index');
    }
}
