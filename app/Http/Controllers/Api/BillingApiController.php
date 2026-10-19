<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\BankAccounts;
use App\Services\Billing\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Planes, suscripción, límites, cobros y saldo de un cliente (tenant) para la app móvil y el sitio web. */
class BillingApiController extends Controller
{
    public function plans(): JsonResponse
    {
        $plans = Plan::where('is_active', true)->where('is_public', true)->with(['prices', 'limits'])->orderBy('sort_order')->get();

        return response()->json(['data' => $plans->map->toApi()->values(), 'currency' => 'CLP', 'tax_note' => 'Valores netos; se agrega IVA.']);
    }

    public function customer(string $customer): JsonResponse
    {
        $client = $this->client($customer);
        $sub = $this->current($client);

        return response()->json(['data' => [
            'customer_id' => $client->customer_id,
            'name' => $client->name,
            'legal_name' => $client->legal_name,
            'tax_id' => $client->tax_id,
            'email' => $client->email,
            'is_active' => (bool) $client->is_active,
            'subscription' => $sub?->toApi(),
            'access' => $this->access($sub),
            'balance' => $this->balanceOf($client),
        ]]);
    }

    public function subscription(string $customer): JsonResponse
    {
        $client = $this->client($customer);
        $sub = $this->current($client);

        return response()->json(['data' => $sub?->toApi(), 'access' => $this->access($sub)]);
    }

    public function limits(string $customer): JsonResponse
    {
        $sub = $this->current($this->client($customer));

        return response()->json(['data' => $sub ? $sub->toApi()['limits'] : [], 'access' => $this->access($sub)]);
    }

    public function balance(string $customer): JsonResponse
    {
        return response()->json(['data' => $this->balanceOf($this->client($customer))]);
    }

    public function invoices(Request $request, string $customer): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(['pending', 'overdue', 'paid', 'void', 'open', 'all'])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $client = $this->client($customer);
        $status = (string) $request->input('status', 'all');

        $page = $client->invoices()->with('subscription:id,uid')
            ->when($status === 'overdue', fn ($q) => $q->overdue())
            ->when($status === 'open', fn ($q) => $q->where('status', 'pending'))
            ->when(in_array($status, ['pending', 'paid', 'void'], true), fn ($q) => $q->where('status', $status))
            ->orderByDesc('issued_at')->orderByDesc('id')->paginate((int) $request->input('per_page', 20));

        return response()->json([
            'data' => $page->getCollection()->map->toApi()->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'payment_instructions' => $this->instructions(),
        ]);
    }

    public function invoice(string $customer, string $number): JsonResponse
    {
        $invoice = $this->client($customer)->invoices()->with('subscription:id,uid')->where('number', $number)->firstOrFail();

        return response()->json(['data' => $invoice->toApi(), 'payment_instructions' => $this->instructions()]);
    }

    /** Registra un pago (app, pasarela o conciliación). Idempotente: pagar un cobro ya pagado devuelve el mismo resultado. */
    public function pay(Request $request, string $customer, string $number, InvoiceService $invoices): JsonResponse
    {
        $data = $request->validate([
            'method' => ['nullable', Rule::in(array_keys(Invoice::METHODS))],
            'reference' => ['nullable', 'string', 'max:120'],
            'paid_at' => ['nullable', 'date'],
        ]);
        $invoice = $this->client($customer)->invoices()->where('number', $number)->firstOrFail();
        abort_if($invoice->status === 'void', 422, 'El cobro está anulado.');

        $invoices->markPaid($invoice, $data);

        return response()->json(['data' => $invoice->fresh('subscription:id,uid')->toApi()]);
    }

    private function client(string $customer): Client
    {
        return Client::where('customer_id', $customer)->firstOrFail();
    }

    /** La suscripción vigente (la más reciente que no esté finalizada o cancelada) o, si no hay, la última. */
    private function current(Client $client): ?Subscription
    {
        $subs = $client->subscriptions()->with(['plan.limits', 'plan.prices'])->orderByDesc('id')->get();

        return $subs->first(fn ($s) => in_array($s->status, ['active', 'past_due', 'suspended'], true)) ?? $subs->first();
    }

    /** @return array{allowed: bool, status: string, reason: string|null, payment_overdue: bool} */
    private function access(?Subscription $s): array
    {
        if (! $s) {
            return ['allowed' => false, 'status' => 'none', 'reason' => 'El cliente no tiene una suscripción.', 'payment_overdue' => false];
        }

        return [
            'allowed' => $s->hasAccess(),
            'status' => $s->status,
            'reason' => $s->hasAccess() ? ($s->status === 'past_due' ? 'Tiene un pago vencido.' : null) : (Subscription::STATUSES[$s->status] ?? $s->status),
            'payment_overdue' => $s->status === 'past_due',
        ];
    }

    /** @return array<string, mixed> */
    private function balanceOf(Client $client): array
    {
        $pending = $client->invoices()->where('status', 'pending');
        $overdue = $client->invoices()->overdue();
        $next = $client->invoices()->where('status', 'pending')->orderBy('due_date')->first();

        return [
            'currency' => 'CLP',
            'pending_count' => (clone $pending)->count(),
            'pending_total' => (int) (clone $pending)->sum('amount_total'),
            'overdue_count' => $overdue->count(),
            'overdue_total' => (int) $client->invoices()->overdue()->sum('amount_total'),
            'next_due' => $next ? ['number' => $next->number, 'due_date' => $next->due_date->toDateString(), 'amount_total' => $next->amount_total] : null,
        ];
    }

    /** Cómo pagar (cuentas de transferencia). @return array<string, mixed> */
    private function instructions(): array
    {
        return ['bank_accounts' => BankAccounts::all(), 'note' => BankAccounts::note() ?: null];
    }
}
