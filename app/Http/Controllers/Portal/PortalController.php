<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Proposal;
use App\Services\Billing\BankAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Portal de clientes. IMPORTANTE: todo se arma con campos explícitos y acotado a el cliente del usuario;
 * costos, gastos, notas internas y precios de servicios nunca se incluyen.
 */
class PortalController extends Controller
{
    public function home(Request $request): Response
    {
        $client = $this->client($request);

        $pending = Invoice::where('client_id', $client->id)->where('status', 'issued')->orderBy('due_date')->get();
        $next = $pending->first();

        return Inertia::render('portal/Home', [
            'company' => $this->company($client),
            'summary' => [
                'active_services' => $this->services($client)->get()->filter(fn (ClientService $s) => $s->isLive())->count(),
                'pending_invoices' => $pending->count(),
                'overdue_invoices' => $pending->filter(fn (Invoice $i) => $i->isOverdue())->count(),
                'pending_total_clp' => (float) $pending->sum('total_clp'),
                'proposals' => $this->proposals($client)->count(),
            ],
            'next_invoice' => $next ? $this->invoice($next) : null,
            'bank' => $pending->isNotEmpty() ? ['accounts' => BankAccounts::all(), 'note' => BankAccounts::note()] : null,
            'recent_invoices' => Invoice::where('client_id', $client->id)->visibleToClient()->orderByDesc('due_date')->limit(4)->get()->map(fn ($i) => $this->invoice($i)),
        ]);
    }

    public function proposals_index(Request $request): Response
    {
        $client = $this->client($request);

        return Inertia::render('portal/Proposals', [
            'company' => $this->company($client),
            'proposals' => $this->proposals($client)->orderByDesc('sent_at')->orderByDesc('id')->get()->map(fn (Proposal $p) => [
                'id' => $p->id, 'number' => $p->number, 'title' => $p->title,
                'status' => $p->effectiveStatus(), 'status_label' => Proposal::STATUSES[$p->effectiveStatus()] ?? $p->effectiveStatus(),
                'status_color' => Proposal::STATUS_COLORS[$p->effectiveStatus()] ?? '#8A8A8A',
                'currency' => $p->currency, 'total_net' => $p->total_net,
                'sent_at' => $p->sent_at?->toDateString(), 'valid_until' => $p->valid_until?->toDateString(),
                'url' => $p->publicUrl(),
            ]),
        ]);
    }

    public function services_index(Request $request): Response
    {
        $client = $this->client($request);

        $all = $this->services($client)->with(['proposal:id,number,title,public_token,status', 'children'])->orderBy('start_date', 'desc')->get();

        return Inertia::render('portal/Services', [
            'company' => $this->company($client),
            'services' => $all->whereNull('parent_id')->values()->map(fn (ClientService $s) => $this->service($s, $all)),
        ]);
    }

    public function invoices_index(Request $request): Response
    {
        $client = $this->client($request);

        return Inertia::render('portal/Invoices', [
            'company' => $this->company($client),
            'bank' => ['accounts' => BankAccounts::all(), 'note' => BankAccounts::note()],
            // Por pagar primero (la que vence antes arriba) y luego las pagadas, de la más reciente a la más antigua.
            'invoices' => Invoice::where('client_id', $client->id)->visibleToClient()->get()
                ->sortBy(fn (Invoice $i) => $i->status === 'issued' ? $i->due_date->timestamp : PHP_INT_MAX - $i->due_date->timestamp)
                ->values()->map(fn ($i) => $this->invoice($i)),
        ]);
    }

    public function invoicePdf(Request $request, Invoice $invoice): StreamedResponse
    {
        abort_unless($invoice->client_id === $this->client($request)->id && in_array($invoice->status, ['issued', 'paid'], true), 404);
        abort_unless($invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path), 404);

        return Storage::disk('local')->download($invoice->pdf_path, 'Factura-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $invoice->number ?: (string) $invoice->id).'.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function account(Request $request): Response
    {
        $preview = $this->preview($request);
        // En la vista previa se muestra el acceso elegido (o un contacto de ejemplo), nunca los datos del usuario del equipo.
        $user = $preview ? (\App\Models\User::where('client_id', $preview['client_id'])->find($preview['user_id']) ?? new \App\Models\User(['name' => 'Contacto del cliente', 'email' => 'contacto@cliente.cl'])) : $request->user();
        $client = $this->client($request);
        $lead = $user->lead_id ? Lead::find($user->lead_id) : null;

        return Inertia::render('portal/Account', [
            'company' => $this->company($client, true),
            'me' => [
                'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone,
                'job_title' => $lead?->job_title,
            ],
            'must_change_password' => $preview ? false : (bool) $user->must_change_password,
            'preview' => $preview !== null,
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $this->denyInPreview($request);

        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:40']]);
        $request->user()->update($data);
        $this->toast('Tus datos fueron actualizados.');

        return back();
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $this->denyInPreview($request);

        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ], ['current_password.current_password' => 'La contraseña actual no es correcta.']);

        abort_if(Hash::check($data['password'], $request->user()->password), 422, 'La nueva contraseña debe ser distinta de la actual.');

        $request->user()->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();
        $request->session()->regenerate();
        $this->toast('Contraseña actualizada.');

        return back();
    }

    /** Cliente que se muestra: la del acceso, o la elegida en la vista previa del equipo. */
    private function client(Request $request): Client
    {
        if ($preview = $this->preview($request)) {
            return Client::findOrFail($preview['client_id']);
        }

        return $request->user()->client()->firstOrFail();
    }

    /** @return array{client_id: int, user_id: int|null}|null */
    private function preview(Request $request): ?array
    {
        return $request->user()->isPortal() ? null : $request->session()->get('portal_preview');
    }

    /** En la vista previa no se puede modificar nada (las acciones de cuenta actuarían sobre el usuario del equipo). */
    private function denyInPreview(Request $request): void
    {
        abort_if($this->preview($request) !== null, 403, 'Estás en la vista previa del portal: no se puede modificar nada desde aquí.');
    }

    /** @return array<string, mixed> */
    private function company(Client $c, bool $full = false): array
    {
        $base = ['id' => $c->id, 'name' => $c->name];

        return $full ? [...$base, 'legal_name' => $c->legal_name, 'tax_id' => $c->tax_id, 'activity' => $c->activity, 'email' => $c->email, 'phone' => $c->phone, 'website' => $c->website,
            'address' => collect([$c->address, $c->commune, $c->city])->filter()->implode(', '), 'contact_name' => $c->contact_name, 'contact_role' => $c->contact_role] : $base;
    }

    private function proposals(Client $client)
    {
        return Proposal::query()->where('status', '!=', 'draft')
            ->where(fn ($q) => $q->where('client_id', $client->id)->orWhereHas('lead', fn ($l) => $l->where('client_id', $client->id)));
    }

    private function services(Client $client)
    {
        // Todo lo contratado salvo lo cancelado (los eliminados quedan fuera por soft delete).
        return ClientService::where('client_id', $client->id)->where('status', '!=', 'cancelled');
    }

    /** @param  \Illuminate\Support\Collection<int, ClientService>  $all */
    private function service(ClientService $s, $all): array
    {
        return [
            'id' => $s->id, 'name' => $s->name, 'description' => $s->description,
            'billing_cycle' => $s->billing_cycle, 'start_date' => $s->start_date?->toDateString(), 'end_date' => $s->end_date?->toDateString(),
            'auto_renew' => $s->auto_renew, 'status' => $s->effectiveStatus(),
            'next_charge_on' => $s->isRecurring() && $s->isLive() ? $s->next_charge_on?->toDateString() : null,
            'proposal' => $s->proposal && $s->proposal->status !== 'draft' ? ['number' => $s->proposal->number, 'title' => $s->proposal->title, 'url' => $s->proposal->publicUrl()] : null,
            'related' => $all->where('parent_id', $s->id)->values()->map(fn (ClientService $c) => [
                'id' => $c->id, 'name' => $c->name, 'description' => $c->description, 'billing_cycle' => $c->billing_cycle,
                'start_date' => $c->start_date?->toDateString(), 'end_date' => $c->end_date?->toDateString(), 'auto_renew' => $c->auto_renew, 'status' => $c->effectiveStatus(),
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private function invoice(Invoice $i): array
    {
        return [
            'id' => $i->id, 'number' => $i->number, 'concept' => $i->concept,
            'period_start' => $i->period_start?->toDateString(), 'period_end' => $i->period_end?->toDateString(),
            'issue_date' => $i->issue_date?->toDateString(), 'due_date' => $i->due_date?->toDateString(),
            'currency' => $i->currency, 'amount_total' => $i->amount_total, 'total_clp' => $i->total_clp,
            'document_label' => $i->documentLabel(), 'is_boleta' => $i->document_type === 'boleta', 'amount_net' => $i->amount_net, 'tax_rate' => $i->tax_rate,
            'retention_rate' => $i->retention_rate, 'retention_amount' => $i->retention_amount, 'retention_by_client' => $i->retention_by_client,
            'status' => $i->displayStatus(), 'paid_at' => $i->paid_at?->toDateString(),
            'payment_link' => $i->status === 'issued' ? ($i->payment_link ?: null) : null,
            'has_pdf' => $i->hasPdf(),
        ];
    }
}
