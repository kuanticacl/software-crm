<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\InvoiceRequest;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\Billing\BankAccounts;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\ReminderRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Cobranza interna: cobros y facturas (PDF externo), pagos, envíos y recordatorios. */
class InvoiceController extends Controller
{
    /** Campos del formulario que no son columnas del cobro. */
    private const NON_COLUMNS = ['pdf', 'issue', 'paid', 'paid_at', 'payment_method', 'payment_reference'];

    public function __construct(private InvoiceService $invoices) {}

    public function index(Request $request): Response
    {
        $filters = ['status' => $request->input('status', 'pending'), 'client' => $request->input('client'), 'q' => $request->input('q')];

        $list = Invoice::query()->with(['client:id,name', 'service:id,name'])
            ->when($filters['client'], fn ($q, $c) => $q->where('client_id', $c))
            ->when($filters['q'], fn ($q, $t) => $q->where(fn ($w) => $w->where('number', 'like', "%{$t}%")->orWhere('concept', 'like', "%{$t}%")->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$t}%"))))
            ->when($filters['status'], fn ($q, $s) => match ($s) {
                'scheduled' => $q->where('status', 'scheduled'),
                'pending' => $q->where('status', 'issued'),
                'overdue' => $q->overdue(),
                'paid' => $q->where('status', 'paid'),
                'cancelled' => $q->where('status', 'cancelled'),
                default => $q,
            })
            ->orderByRaw("case status when 'scheduled' then 1 when 'issued' then 0 else 2 end")
            ->orderBy('due_date', $filters['status'] === 'paid' ? 'desc' : 'asc')
            ->paginate(20)->withQueryString()
            ->through(fn (Invoice $i) => $this->present($i));

        return Inertia::render('billing/Index', [
            'invoices' => $list,
            'filters' => $filters,
            'counts' => [
                'scheduled' => Invoice::where('status', 'scheduled')->count(),
                'pending' => Invoice::where('status', 'issued')->count(),
                'overdue' => Invoice::overdue()->count(),
                'paid' => Invoice::where('status', 'paid')->count(),
            ],
            'kpis' => [
                'receivable_clp' => (float) Invoice::where('status', 'issued')->sum('total_clp'),
                'overdue_clp' => (float) Invoice::overdue()->sum('total_clp'),
                'paid_month_clp' => (float) Invoice::where('status', 'paid')->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('total_clp'),
            ],
            'clients' => Client::orderBy('name')->get(['id', 'name', 'kind']),
            'services' => ClientService::orderBy('name')->get(['id', 'name', 'client_id', 'currency', 'price']),
            'taxRate' => config('portal.tax_rate'),
            'reminderOffsets' => ReminderRunner::defaultOffsets(),
            'aiAvailable' => app(\App\Services\Ai\AiGateway::class)->isAvailable(),
            'bank' => ['accounts' => BankAccounts::all(), 'note' => BankAccounts::note(), 'types' => BankAccounts::TYPES],
        ]);
    }

    public function store(InvoiceRequest $request): RedirectResponse
    {
        $data = $request->safe()->except(self::NON_COLUMNS);
        $paid = $request->boolean('paid') && $request->user()->hasPermission('billing.mark_paid');
        // Una factura antigua ya pagada no genera recordatorios ni avisos al cliente.
        $invoice = $this->invoices->create($paid ? [...$data, 'auto_remind' => false] : $data, $request->user());

        if ($request->hasFile('pdf')) {
            $this->invoices->attachPdf($invoice, $request->file('pdf'));
            if ($request->boolean('issue') || $paid) {
                $this->invoices->issue($invoice);
            }
        }
        if ($paid) {
            $this->invoices->markPaid($invoice, $request->input('payment_method'), $request->input('payment_reference'), $request->filled('paid_at') ? \Illuminate\Support\Carbon::parse($request->input('paid_at')) : null);
        }

        $this->toast($paid ? 'Factura pagada registrada (queda en el historial, sin enviar avisos).' : 'Cobro registrado.');

        return back();
    }

    public function update(InvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        abort_if($invoice->status === 'cancelled', 422, 'Una factura anulada no se edita: reábrela primero.');

        $this->invoices->update($invoice, $request->safe()->except(self::NON_COLUMNS));
        if ($invoice->status === 'paid' && $request->user()->hasPermission('billing.mark_paid')) {
            $invoice->forceFill([
                'payment_method' => $request->input('payment_method'), 'payment_reference' => $request->input('payment_reference'),
                ...($request->filled('paid_at') ? ['paid_at' => \Illuminate\Support\Carbon::parse($request->input('paid_at'))] : []),
            ])->save();
        }
        if ($request->hasFile('pdf')) {
            $this->invoices->attachPdf($invoice, $request->file('pdf'));
        }
        if ($request->boolean('issue') && $invoice->status === 'scheduled') {
            $this->invoices->issue($invoice);
        }

        $this->toast('Cobro actualizado.');

        return back();
    }

    /** Lee el PDF de una factura con IA y devuelve los datos para precargar el formulario (no guarda nada). */
    public function extract(Request $request, \App\Services\Billing\InvoiceExtractor $extractor): \Illuminate\Http\JsonResponse
    {
        $request->validate(['pdf' => ['required', 'file', 'mimes:pdf', 'max:10240']]);

        try {
            return response()->json($extractor->extract($request->file('pdf')));
        } catch (\App\Services\Ai\AiFailed|\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function pdf(Request $request, Invoice $invoice): RedirectResponse
    {
        $request->validate(['pdf' => ['required', 'file', 'mimes:pdf', 'max:10240'], 'issue' => ['boolean'], 'number' => ['nullable', 'string', 'max:60']]);

        $this->invoices->attachPdf($invoice, $request->file('pdf'));
        if ($request->boolean('issue')) {
            $this->invoices->issue($invoice, $request->input('number'));
        } elseif ($request->filled('number')) {
            $invoice->update(['number' => $request->input('number')]);
        }

        $this->toast($request->boolean('issue') ? 'Factura adjuntada y emitida: ya la ve el cliente.' : 'PDF adjuntado.');

        return back();
    }

    public function issue(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->invoices->issue($invoice, $request->validate(['number' => ['nullable', 'string', 'max:60']])['number'] ?? null);
        $this->toast('Factura emitida: el cliente ya puede verla en su portal.');

        return back();
    }

    public function pay(Request $request, Invoice $invoice): RedirectResponse
    {
        $d = $request->validate([
            'payment_method' => ['nullable', 'string', 'max:60'], 'payment_reference' => ['nullable', 'string', 'max:120'], 'paid_at' => ['nullable', 'date'],
        ]);
        $this->invoices->markPaid($invoice, $d['payment_method'] ?? null, $d['payment_reference'] ?? null, isset($d['paid_at']) ? \Illuminate\Support\Carbon::parse($d['paid_at']) : null);

        $this->toast('Factura marcada como pagada.');

        return back();
    }

    public function reopen(Invoice $invoice): RedirectResponse
    {
        $this->invoices->reopen($invoice);
        $this->toast('Factura reabierta.');

        return back();
    }

    public function cancel(Invoice $invoice): RedirectResponse
    {
        $this->invoices->cancel($invoice);
        $this->toast('Cobro anulado.');

        return back();
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        abort_if($invoice->status === 'paid', 422, 'Una factura pagada no se elimina: anúlala o reábrela.');
        $invoice->delete();
        $this->toast('Cobro eliminado.');

        return back();
    }

    /** Botón «Enviar cobro» (o recordatorio manual): avisa por correo a los contactos con acceso al portal. */
    public function send(Invoice $invoice): RedirectResponse
    {
        $n = $this->invoices->send($invoice, $invoice->reminders()->where('kind', 'issued')->exists() ? 'manual' : 'issued');
        $this->toast("Cobro enviado a {$n} contacto(s).");

        return back();
    }

    public function download(Invoice $invoice): StreamedResponse
    {
        abort_unless($invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path), 404);

        return Storage::disk('local')->download($invoice->pdf_path, $this->fileName($invoice), ['Content-Type' => 'application/pdf']);
    }

    public function settings(Request $request): RedirectResponse
    {
        $d = $request->validate(['reminder_offsets' => ['present', 'array', 'max:8'], 'reminder_offsets.*' => ['integer', 'between:-60,90']]);
        Setting::put('billing.reminder_offsets', implode(',', ReminderRunner::clean($d['reminder_offsets'])));

        $this->toast('Recordatorios de pago actualizados.');

        return back();
    }

    /** Datos bancarios para recibir transferencias (portal y correos de cobro). */
    public function bankAccounts(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'accounts' => ['present', 'array', 'max:5'],
            'accounts.*.bank' => ['required', 'string', 'max:80'],
            'accounts.*.account_type' => ['required', 'string', 'max:40'],
            'accounts.*.number' => ['required', 'string', 'max:40'],
            'accounts.*.holder' => ['nullable', 'string', 'max:150'],
            'accounts.*.tax_id' => ['nullable', 'string', 'max:20', fn ($a, $v, $fail) => $v && ! \App\Support\Rut::isValid($v) ? $fail('El RUT no es válido.') : null],
            'accounts.*.email' => ['nullable', 'email:rfc', 'max:150'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $accounts = collect($d['accounts'])->map(fn ($a) => [...$a, 'tax_id' => \App\Support\Rut::format($a['tax_id'] ?? null)])->all();
        BankAccounts::save($accounts, $d['note'] ?? null);

        $this->toast('Datos bancarios guardados: se muestran en el portal y en los correos de cobro.');

        return back();
    }

    public function fileName(Invoice $i): string
    {
        $prefix = ['boleta' => 'Boleta', 'factura' => 'Factura'][$i->document_type] ?? 'Documento';

        return $prefix.'-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $i->number ?: (string) $i->id).'.pdf';
    }

    /** @return array<string, mixed> */
    public function present(Invoice $i): array
    {
        return [
            'id' => $i->id, 'number' => $i->number, 'concept' => $i->concept,
            'client' => $i->relationLoaded('client') ? $i->client?->only(['id', 'name']) : null,
            'service' => $i->relationLoaded('service') ? $i->service?->only(['id', 'name']) : null,
            'client_id' => $i->client_id, 'client_service_id' => $i->client_service_id,
            'period_start' => $i->period_start?->toDateString(), 'period_end' => $i->period_end?->toDateString(),
            'issue_date' => $i->issue_date?->toDateString(), 'due_date' => $i->due_date?->toDateString(),
            'currency' => $i->currency, 'amount_net' => $i->amount_net, 'tax_rate' => $i->tax_rate, 'amount_total' => $i->amount_total,
            'document_type' => $i->document_type, 'kind' => $i->kind, 'retention_rate' => $i->retention_rate, 'retention_amount' => $i->retention_amount, 'retention_by_client' => $i->retention_by_client,
            'total_clp' => $i->total_clp, 'uf_value' => $i->uf_value,
            'status' => $i->status, 'display_status' => $i->displayStatus(),
            'has_pdf' => $i->hasPdf(), 'pdf_name' => $i->pdf_name, 'auto_remind' => $i->auto_remind, 'payment_link' => $i->payment_link,
            'sent_at' => $i->sent_at?->toIso8601String(), 'paid_at' => $i->paid_at?->toIso8601String(),
            'payment_method' => $i->payment_method, 'payment_reference' => $i->payment_reference, 'notes' => $i->notes,
        ];
    }
}
