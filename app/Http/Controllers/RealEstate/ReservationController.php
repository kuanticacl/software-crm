<?php

namespace App\Http\Controllers\RealEstate;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Reservation;
use App\Models\ReservationPayment;
use App\Services\Email\DefaultTemplates;
use App\Services\Email\EmailService;
use App\Services\Email\MailSettings;
use App\Services\RealEstate\QuoteView;
use App\Services\RealEstate\ReservationService;
use App\Services\UfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Reservas: pago inicial, plan de pagos del pie, promesa, escritura o desistimiento. */
class ReservationController extends Controller
{
    public function __construct(private ReservationService $service) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = ['q' => (string) $request->input('q', ''), 'status' => (string) $request->input('status', ''), 'project' => (string) $request->input('project', '')];
        $base = Reservation::query()->visibleTo($user)->when($filters['project'], fn ($q, $p) => $q->where('project_id', (int) $p));

        $rows = (clone $base)->with(['project:id,name,color', 'client:id,name,tax_id', 'owner:id,name', 'units'])
            ->withSum(['payments as paid_uf' => fn ($q) => $q->where('status', 'paid')->where('currency', 'UF')], 'amount')
            ->withCount(['payments as overdue_count' => fn ($q) => $q->where('status', 'pending')->whereDate('due_date', '<', now())])
            ->when($filters['q'], fn ($q, $t) => $q->where(fn ($q) => $q->where('number', 'like', "%{$t}%")->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$t}%")->orWhere('tax_id', 'like', "%{$t}%"))->orWhereHas('units', fn ($u) => $u->where('code', 'like', "%{$t}%"))))
            ->when($filters['status'], fn ($q, $s) => $q->where('status', $s))
            ->latest('id')->paginate(20)->withQueryString();
        $rows->getCollection()->transform(fn (Reservation $r) => [
            ...$this->row($r),
            'paid_uf' => (float) ($r->paid_uf ?? 0),
            'overdue_count' => (int) $r->overdue_count,
        ]);

        $summary = (clone $base)->selectRaw('status, count(*) c, sum(total_uf) uf')->groupBy('status')->get()->keyBy('status');

        return Inertia::render('realestate/reservations/Index', [
            'reservations' => $rows,
            'filters' => $filters,
            'projects' => Project::visibleTo($user)->orderBy('name')->get(['id', 'name']),
            'statuses' => Reservation::STATUSES,
            'statusColors' => Reservation::STATUS_COLORS,
            'summary' => collect(Reservation::STATUSES)->map(fn ($label, $k) => ['label' => $label, 'count' => (int) ($summary[$k]->c ?? 0), 'uf' => (float) ($summary[$k]->uf ?? 0)])->all(),
        ]);
    }

    /** Formulario de reserva a partir de una cotización. */
    public function create(Request $request): Response
    {
        $quote = Quote::with(['project', 'items.unit', 'client', 'lead'])->findOrFail($request->integer('quote'));
        abort_unless(Quote::visibleTo($request->user())->whereKey($quote->id)->exists(), 403);
        abort_if($quote->reservation()->exists(), 422, 'Esta cotización ya tiene una reserva.');
        $v = QuoteView::data($quote);
        $client = $quote->client ?? $quote->lead?->client;

        return Inertia::render('realestate/reservations/Create', [
            'quote' => [
                'id' => $quote->id, 'number' => $quote->number, 'project' => $quote->project->only(['id', 'name', 'promise_days']),
                'items' => $quote->items->map(fn ($i) => ['label' => $i->label(), 'price_uf' => $i->price_uf, 'unit_status' => $i->unit?->status]),
                ...$quote->only(['total_uf', 'pie_pct', 'pie_uf', 'credit_uf', 'reservation_amount', 'pie_installments']),
                'buyer' => $quote->buyer ?? [],
                'lead' => $quote->lead ? ['id' => $quote->lead->id, 'name' => $quote->lead->full_name] : null,
            ],
            'client' => $client?->only(['id', 'name', 'tax_id', 'email', 'phone', 'address', 'commune']),
            'defaults' => [
                'reserved_at' => now()->toDateString(),
                'promise_due_date' => now()->addDays($quote->project->promise_days)->toDateString(),
                'first_installment_date' => now()->addMonthNoOverflow()->toDateString(),
            ],
            'uf' => app(UfService::class)->today(),
            'financing' => Reservation::FINANCING,
            'methods' => ReservationPayment::METHODS,
            'installmentUf' => $v['installment'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'quote_id' => ['required', 'integer', 'exists:quotes,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'buyer' => ['nullable', 'array'],
            'buyer.name' => ['required_without:client_id', 'nullable', 'string', 'max:160'],
            'buyer.rut' => ['nullable', 'string', 'max:20', fn ($a, $v, $fail) => $v && ! \App\Support\Rut::isValid($v) ? $fail('El RUT no es válido.') : null],
            'buyer.email' => ['nullable', 'email:rfc', 'max:200'],
            'buyer.phone' => ['nullable', 'string', 'max:40'],
            'buyer.address' => ['nullable', 'string', 'max:300'],
            'buyer.commune' => ['nullable', 'string', 'max:120'],
            'reserved_at' => ['required', 'date'],
            'promise_due_date' => ['nullable', 'date', 'after_or_equal:reserved_at'],
            'reservation_amount' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'reservation_paid' => ['boolean'],
            'reservation_method' => ['nullable', 'string', 'max:40'],
            'reservation_reference' => ['nullable', 'string', 'max:120'],
            'pie_installments' => ['required', 'integer', 'min:0', 'max:120'],
            'first_installment_date' => ['nullable', 'date'],
            'financing' => ['required', 'in:'.implode(',', array_keys(Reservation::FINANCING))],
            'bank' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'notify' => ['boolean'],
        ], ['buyer.name.required_without' => 'Indica el nombre del comprador o elige un cliente.']);

        $quote = Quote::findOrFail($data['quote_id']);
        abort_unless(Quote::visibleTo($request->user())->whereKey($quote->id)->exists(), 403);
        $reservation = $this->service->create($data, $request->user());

        if ($request->boolean('notify')) {
            $this->notifyBuyer($reservation);
        }
        $this->toast("Reserva {$reservation->number} registrada: las unidades quedaron reservadas.");

        return redirect("/reservations/{$reservation->id}");
    }

    public function show(Request $request, Reservation $reservation): Response
    {
        $this->authorizeView($request, $reservation);
        $user = $request->user();
        $reservation->load(['project', 'client', 'owner:id,name,email,phone', 'units.unit', 'payments', 'quote:id,number', 'lead:id,first_name,last_name']);
        $uf = app(UfService::class)->today();

        $payments = $reservation->payments;
        $paidUf = $payments->where('status', 'paid')->where('currency', 'UF')->sum('amount') + ($reservation->uf_value > 0 ? $payments->where('status', 'paid')->where('currency', 'CLP')->sum('amount') / $reservation->uf_value : 0);

        return Inertia::render('realestate/reservations/Show', [
            'reservation' => [
                ...$this->row($reservation),
                ...$reservation->only(['subtotal_uf', 'discount_uf', 'pie_pct', 'pie_uf', 'credit_uf', 'financing', 'bank', 'notes', 'cancel_reason', 'uf_value']),
                'reserved_at' => $reservation->reserved_at?->toDateString(),
                'promise_due_date' => $reservation->promise_due_date?->toDateString(),
                'promised_at' => $reservation->promised_at?->toDateString(),
                'deeded_at' => $reservation->deeded_at?->toDateString(),
                'cancelled_at' => $reservation->cancelled_at?->toDateString(),
                'client' => $reservation->client?->only(['id', 'name', 'tax_id', 'email', 'phone', 'address', 'commune']),
                'executive' => $reservation->owner?->only(['id', 'name', 'email', 'phone']),
                'quote' => $reservation->quote?->only(['id', 'number']),
                'lead' => $reservation->lead ? ['id' => $reservation->lead->id, 'name' => $reservation->lead->full_name] : null,
                'unit_rows' => $reservation->units->map(fn ($u) => ['id' => $u->unit_id, 'label' => $u->label(), 'model' => $u->model, 'list_price_uf' => $u->list_price_uf, 'price_uf' => $u->price_uf, 'status' => $u->unit?->status, 'detail' => $u->unit?->detail()]),
                'payments' => $payments->map(fn (ReservationPayment $p) => [
                    ...$p->only(['id', 'kind', 'number', 'label', 'currency', 'amount', 'status', 'paid_clp', 'method', 'reference', 'notes']),
                    'due_date' => $p->due_date?->toDateString(), 'paid_at' => $p->paid_at?->toDateString(), 'overdue' => $p->isOverdue(),
                ]),
                'progress' => [
                    'paid_uf' => round($paidUf, 2),
                    'pie_paid_uf' => round($payments->whereIn('kind', ['reserva', 'pie'])->where('status', 'paid')->sum(fn ($p) => $p->currency === 'UF' ? $p->amount : ($reservation->uf_value > 0 ? $p->amount / $reservation->uf_value : 0)), 2),
                    'overdue' => $payments->filter->isOverdue()->count(),
                    'next' => ($n = $payments->where('status', 'pending')->sortBy('due_date')->first()) ? ['label' => $n->label, 'due_date' => $n->due_date?->toDateString(), 'amount' => $n->amount, 'currency' => $n->currency] : null,
                ],
            ],
            'uf' => $uf,
            'kinds' => ReservationPayment::KINDS,
            'methods' => ReservationPayment::METHODS,
            'paymentStatuses' => ReservationPayment::STATUSES,
            'financing' => Reservation::FINANCING,
            'can' => [
                'manage' => $user->hasPermission('reservations.manage'),
                'payments' => $user->hasPermission('reservations.payments'),
            ],
        ]);
    }

    public function update(Request $request, Reservation $reservation): RedirectResponse
    {
        $this->authorizeView($request, $reservation);
        $data = $request->validate([
            'promise_due_date' => ['nullable', 'date'],
            'financing' => ['required', 'in:'.implode(',', array_keys(Reservation::FINANCING))],
            'bank' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        $reservation->update($data);
        $this->toast('Reserva actualizada.');

        return back();
    }

    public function transition(Request $request, Reservation $reservation): RedirectResponse
    {
        $this->authorizeView($request, $reservation);
        $data = $request->validate([
            'status' => ['required', 'in:promised,deeded,cancelled'],
            'date' => ['nullable', 'date'],
            'reason' => ['required_if:status,cancelled', 'nullable', 'string', 'max:300'],
        ], ['reason.required_if' => 'Indica el motivo del desistimiento.']);
        $this->service->transition($reservation, $data['status'], $request->user(), $data['date'] ?? null, $data['reason'] ?? null);
        $this->toast(['promised' => 'Promesa registrada: las unidades quedaron promesadas.', 'deeded' => 'Escritura registrada: unidades vendidas.', 'cancelled' => 'Reserva desistida: las unidades volvieron a estar disponibles.'][$data['status']]);

        return back();
    }

    // ---------------------------------------------------------------- plan de pagos

    public function storePayment(Request $request, Reservation $reservation): RedirectResponse
    {
        $this->authorizeView($request, $reservation);
        abort_if($reservation->status === 'cancelled', 422, 'La reserva fue desistida.');
        $reservation->payments()->create($this->paymentData($request));
        $this->toast('Pago agregado al plan.');

        return back();
    }

    public function updatePayment(Request $request, Reservation $reservation, ReservationPayment $payment): RedirectResponse
    {
        $this->authorizeView($request, $reservation);
        abort_unless($payment->reservation_id === $reservation->id, 404);
        $payment->update($this->paymentData($request));
        $this->toast('Pago actualizado.');

        return back();
    }

    public function pay(Request $request, Reservation $reservation, ReservationPayment $payment): RedirectResponse
    {
        $this->authorizeView($request, $reservation);
        abort_unless($payment->reservation_id === $reservation->id, 404);
        $data = $request->validate([
            'paid_at' => ['required', 'date'],
            'paid_clp' => ['nullable', 'numeric', 'min:0', 'max:100000000000'],
            'method' => ['nullable', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);
        $this->service->pay($payment, $data);
        $this->toast("«{$payment->label}» registrado como pagado.");

        return back();
    }

    public function unpay(Request $request, Reservation $reservation, ReservationPayment $payment): RedirectResponse
    {
        $this->authorizeView($request, $reservation);
        abort_unless($payment->reservation_id === $reservation->id, 404);
        $payment->update(['status' => 'pending', 'paid_at' => null, 'paid_clp' => null, 'method' => null, 'reference' => null]);
        $this->toast('Pago marcado como pendiente.');

        return back();
    }

    public function destroyPayment(Request $request, Reservation $reservation, ReservationPayment $payment): RedirectResponse
    {
        $this->authorizeView($request, $reservation);
        abort_unless($payment->reservation_id === $reservation->id, 404);
        abort_if($payment->status === 'paid', 422, 'No se puede eliminar un pago registrado: márcalo como pendiente primero.');
        $payment->delete();
        $this->toast('Pago eliminado del plan.');

        return back();
    }

    // ------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function paymentData(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', 'in:'.implode(',', array_keys(ReservationPayment::KINDS))],
            'number' => ['nullable', 'integer', 'min:0', 'max:500'],
            'label' => ['required', 'string', 'max:120'],
            'due_date' => ['nullable', 'date'],
            'currency' => ['required', 'in:UF,CLP'],
            'amount' => ['required', 'numeric', 'min:0', 'max:100000000000'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]) + ['number' => 0];
    }

    private function notifyBuyer(Reservation $r): void
    {
        $r->loadMissing(['client', 'project', 'units', 'payments', 'owner']);
        if (! $r->client?->email || ! MailSettings::apiKey()) {
            return;
        }
        $pie = $r->payments->where('kind', 'pie');
        $plan = $pie->count() > 1
            ? 'Pie: '.$pie->count().' cuotas de '.QuoteView::uf($pie->first()->amount).' desde el '.$pie->first()->due_date?->format('d-m-Y')
            : ($pie->count() === 1 ? 'Pie: '.QuoteView::uf($pie->first()->amount) : '');

        app(EmailService::class)->queueTemplate(app(DefaultTemplates::class)->ensure('reserva-confirmada'), $r->client->email, $r->client->name, [
            'first_name' => explode(' ', trim($r->client->name))[0] ?? '',
            'project_name' => $r->project?->name,
            'unit_label' => $r->units->map(fn ($u) => $u->label())->implode(' + '),
            'reservation_number' => $r->number,
            'total_uf' => QuoteView::uf($r->total_uf),
            'plan' => $plan,
            'promise_date' => $r->promise_due_date?->locale('es')->translatedFormat('d \d\e F \d\e Y') ?? '',
            'executive' => $r->owner?->name ?? '',
        ], ['lead_id' => $r->lead_id, 'client_id' => $r->client_id]);
    }

    /** @return array<string, mixed> */
    private function row(Reservation $r): array
    {
        return [
            'id' => $r->id,
            'number' => $r->number,
            'status' => $r->status,
            'status_label' => Reservation::STATUSES[$r->status],
            'status_color' => Reservation::STATUS_COLORS[$r->status],
            'project' => $r->project?->only(['id', 'name', 'color']),
            'client_name' => $r->client?->name,
            'client_rut' => $r->client?->tax_id,
            'units' => $r->units->map(fn ($u) => $u->label())->implode(' + '),
            'total_uf' => $r->total_uf,
            'owner' => $r->owner?->name,
            'reserved_at' => $r->reserved_at?->toDateString(),
            'promise_due_date' => $r->promise_due_date?->toDateString(),
        ];
    }

    private function authorizeView(Request $request, Reservation $reservation): void
    {
        abort_unless(Reservation::visibleTo($request->user())->whereKey($reservation->id)->exists(), 403, 'No tienes acceso a esta reserva.');
    }
}
