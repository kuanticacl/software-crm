<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Automation;
use App\Models\Invoice;
use App\Services\Billing\BillingMailer;
use App\Services\Billing\InvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoices) {}

    public function index(Request $request): Response
    {
        $filters = ['q' => (string) $request->input('q', ''), 'status' => (string) $request->input('status', '')];

        $rows = Invoice::query()->with(['client:id,name,customer_id', 'subscription:id,uid'])
            ->when($filters['status'] === 'overdue', fn ($q) => $q->overdue())
            ->when(in_array($filters['status'], ['pending', 'paid', 'void'], true), fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['q'], fn ($q, $t) => $q->where(fn ($q) => $q->where('number', 'like', "%{$t}%")->orWhere('concept', 'like', "%{$t}%")->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$t}%")->orWhere('customer_id', 'like', "%{$t}%"))))
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'paid' THEN 1 ELSE 2 END")->orderBy('due_date')->paginate(20)->withQueryString();

        $rows->getCollection()->transform(fn (Invoice $i) => ['id' => $i->id, ...$i->toApi(), 'client' => $i->client?->only(['id', 'name', 'customer_id'])]);

        return Inertia::render('billing/Invoices', [
            'invoices' => $rows,
            'filters' => $filters,
            'methods' => Invoice::METHODS,
            'summary' => [
                'pending' => ['count' => Invoice::where('status', 'pending')->count(), 'amount' => (int) Invoice::where('status', 'pending')->sum('amount_total')],
                'overdue' => ['count' => Invoice::overdue()->count(), 'amount' => (int) Invoice::overdue()->sum('amount_total')],
                'paid_month' => ['count' => Invoice::where('status', 'paid')->where('paid_at', '>=', now()->startOfMonth())->count(), 'amount' => (int) Invoice::where('status', 'paid')->where('paid_at', '>=', now()->startOfMonth())->sum('amount_total')],
            ],
            'can' => ['manage' => $request->user()->hasPermission('billing.manage')],
        ]);
    }

    public function pay(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'method' => ['nullable', Rule::in(array_keys(Invoice::METHODS))],
            'reference' => ['nullable', 'string', 'max:120'],
            'paid_at' => ['nullable', 'date'],
            'document_number' => ['nullable', 'string', 'max:40'],
        ]);
        abort_if($invoice->status === 'void', 422, 'El cobro está anulado.');

        $this->invoices->markPaid($invoice, $data);
        $this->toast("Pago registrado: {$invoice->number}.");

        return back();
    }

    public function void(Invoice $invoice): RedirectResponse
    {
        $this->invoices->void($invoice);
        $this->toast("Cobro {$invoice->number} anulado.");

        return back();
    }

    public function link(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate(['payment_link' => ['nullable', 'url', 'max:500']]);
        $invoice->update(['payment_link' => $data['payment_link'] ?? null]);
        $this->toast('Enlace de pago guardado.');

        return back();
    }

    /** Reenvía ahora el aviso de cobro al cliente usando la primera automatización activa de «Cobro emitido». */
    public function resend(Invoice $invoice, BillingMailer $mailer): RedirectResponse
    {
        abort_if($invoice->status !== 'pending', 422, 'Solo se pueden reenviar cobros pendientes.');
        $a = Automation::whereIn('trigger', ['invoice.issued', 'invoice.due'])->where('is_active', true)->with('template')->orderBy('id')->first();
        if (! $a) {
            $this->toast('No hay una automatización activa de cobros. Créala en Email → Automatizaciones.', 'error');

            return back();
        }
        $n = $mailer->send($a, $invoice->load('client', 'subscription.plan'));
        $this->toast($n ? "Aviso de cobro enviado a {$invoice->client->email}." : 'La empresa no tiene un correo válido para el aviso.', $n ? 'success' : 'error');

        return back();
    }
}
