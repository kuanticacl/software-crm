<?php

namespace Tests\Feature\Billing;

use App\Models\ApiKey;
use App\Models\Automation;
use App\Models\Client;
use App\Models\EmailMessage;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\BillingDefaults;
use App\Services\Billing\BillingRunner;
use App\Services\Billing\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesCrmData;
use Tests\TestCase;

class SubscriptionBillingTest extends TestCase
{
    use CreatesCrmData;
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seedCrm();
        BillingDefaults::install();
        $this->client = Client::create(['name' => 'Taller Demo', 'email' => 'taller@ejemplo.cl', 'contact_name' => 'Ana Pérez', 'is_active' => true]);
    }

    private function subscribe(string $period = 'monthly', bool $autoRenew = true): Subscription
    {
        $plan = Plan::where('slug', 'pro')->first();

        return app(SubscriptionService::class)->create([
            'client_id' => $this->client->id, 'plan_id' => $plan->id, 'period' => $period, 'amount' => $plan->priceFor($period),
            'auto_renew' => $autoRenew, 'started_at' => today()->toDateString(),
        ]);
    }

    public function test_client_gets_a_public_customer_id(): void
    {
        $this->assertMatchesRegularExpression('/^cus_[a-z0-9]{16}$/', $this->client->customer_id);
    }

    public function test_creating_a_subscription_issues_the_first_invoice_with_iva(): void
    {
        $s = $this->subscribe('quarterly');

        $this->assertSame(today()->addMonthsNoOverflow(3)->toDateString(), $s->current_period_end->toDateString());
        $invoice = $s->invoices()->first();
        $this->assertSame(107990, $invoice->amount_net);
        $this->assertSame((int) round(107990 * 0.19), $invoice->amount_tax);
        $this->assertSame('pending', $invoice->status);
        $this->assertMatchesRegularExpression('/^C-\d{4}-\d{5}$/', $invoice->number);
    }

    public function test_runner_issues_the_renewal_invoice_ahead_and_renews_the_period(): void
    {
        $s = $this->subscribe('monthly');
        $end = $s->current_period_end->copy();

        app(BillingRunner::class)->run($end->copy()->subDays(7));
        $this->assertSame(2, $s->invoices()->count());
        $renewal = $s->invoices()->orderByDesc('id')->first();
        $this->assertSame($end->toDateString(), $renewal->due_date->toDateString());

        // Correr de nuevo no duplica el cobro.
        app(BillingRunner::class)->run($end->copy()->subDays(6));
        $this->assertSame(2, $s->invoices()->count());

        app(BillingRunner::class)->run($end);
        $this->assertSame($end->toDateString(), $s->fresh()->current_period_start->toDateString());
    }

    public function test_subscription_without_auto_renew_expires_and_issues_no_new_invoice(): void
    {
        $s = $this->subscribe('monthly', false);

        app(BillingRunner::class)->run($s->current_period_end->copy());

        $this->assertSame('expired', $s->fresh()->status);
        $this->assertSame(1, $s->invoices()->count());
    }

    public function test_overdue_invoice_marks_past_due_and_paying_restores_active(): void
    {
        $s = $this->subscribe();
        $invoice = $s->invoices()->first();

        app(BillingRunner::class)->run($invoice->due_date->copy()->addDays(3));
        $this->assertSame('past_due', $s->fresh()->status);
        $this->assertTrue($s->fresh()->hasAccess());

        app(\App\Services\Billing\InvoiceService::class)->markPaid($invoice, ['method' => 'transferencia']);
        $this->assertSame('active', $s->fresh()->status);
    }

    public function test_due_date_automations_send_once_and_respect_offset(): void
    {
        $s = $this->subscribe();
        $invoice = $s->invoices()->first();
        $before = EmailMessage::count();

        // Automatización «5 días antes»: el cobro vence en 5 días desde la fecha de ejecución.
        $day = $invoice->due_date->copy()->subDays(5);
        app(BillingRunner::class)->run($day);
        $afterFirst = EmailMessage::count();
        $this->assertGreaterThan($before, $afterFirst);

        app(BillingRunner::class)->run($day);
        $this->assertSame($afterFirst, EmailMessage::count());
        $this->assertSame('taller@ejemplo.cl', EmailMessage::latest('id')->first()->to_email);
    }

    public function test_cancel_stops_renewal_and_removes_access(): void
    {
        $s = $this->subscribe();
        app(SubscriptionService::class)->cancel($s, 'prueba');

        $this->assertSame('cancelled', $s->fresh()->status);
        $this->assertFalse($s->fresh()->hasAccess());
        $this->assertFalse($s->fresh()->auto_renew);
    }

    public function test_api_returns_customer_subscription_limits_and_pending_invoices(): void
    {
        $s = $this->subscribe();
        [, $plain] = ApiKey::issue('app', ['billing.read']);
        $h = ['Authorization' => 'Bearer '.$plain];

        $this->getJson("/api/v1/customers/{$this->client->customer_id}", $h)
            ->assertOk()
            ->assertJsonPath('data.access.allowed', true)
            ->assertJsonPath('data.subscription.plan.id', 'pro')
            ->assertJsonPath('data.balance.pending_count', 1);

        $limits = $this->getJson("/api/v1/customers/{$this->client->customer_id}/limits", $h)->assertOk()->json('data');
        $this->assertContains('usuarios', array_column($limits, 'key'));

        $this->getJson("/api/v1/customers/{$this->client->customer_id}/invoices?status=open", $h)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/customers/cus_inexistente', $h)->assertNotFound();
        $this->getJson("/api/v1/customers/{$this->client->customer_id}")->assertUnauthorized();
    }

    public function test_api_payment_requires_write_ability_and_is_idempotent(): void
    {
        $s = $this->subscribe();
        $number = $s->invoices()->first()->number;
        [, $read] = ApiKey::issue('lectura', ['billing.read']);
        [, $write] = ApiKey::issue('escritura', ['billing.write']);
        $url = "/api/v1/customers/{$this->client->customer_id}/invoices/{$number}/payments";

        $this->postJson($url, [], ['Authorization' => 'Bearer '.$read])->assertForbidden();
        $this->postJson($url, ['method' => 'tarjeta', 'reference' => 'TX1'], ['Authorization' => 'Bearer '.$write])->assertOk()->assertJsonPath('data.status', 'paid');
        $this->postJson($url, ['method' => 'tarjeta'], ['Authorization' => 'Bearer '.$write])->assertOk()->assertJsonPath('data.payment_reference', 'TX1');
        $this->assertSame('paid', Invoice::where('number', $number)->value('status'));
    }

    public function test_default_billing_automations_are_installed_and_inactive_templates_are_skipped(): void
    {
        $this->assertGreaterThanOrEqual(8, Automation::whereIn('trigger', Automation::BILLING_TRIGGERS)->count());
        $this->assertSame(3, Plan::count());
    }
}
