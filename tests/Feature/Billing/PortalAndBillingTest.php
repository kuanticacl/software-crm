<?php

namespace Tests\Feature\Billing;

use App\Models\Client;
use App\Models\ClientService;
use App\Models\EmailMessage;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\UfValue;
use App\Models\User;
use App\Services\Billing\ChargeGenerator;
use App\Services\Billing\ContractService;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\PortalAccess;
use App\Services\Billing\ReminderRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesCrmData;
use Tests\TestCase;

class PortalAndBillingTest extends TestCase
{
    use CreatesCrmData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCrm();
        Queue::fake();
        Storage::fake('local');
        UfValue::updateOrCreate(['date' => now('America/Santiago')->toDateString()], ['value' => 40000]);
    }

    private function company(string $name = 'Oritec SpA'): Client
    {
        return Client::create(['name' => $name, 'email' => 'contacto@'.strtolower(str_replace(' ', '', $name)).'.cl', 'is_active' => true]);
    }

    private function portalUser(Client $c, string $email = 'cliente@oritec.cl'): User
    {
        return app(PortalAccess::class)->create($c, ['name' => 'Cristian Suárez', 'email' => $email])['user'];
    }

    private function service(Client $c, array $o = []): ClientService
    {
        return app(ContractService::class)->create([
            'client_id' => $c->id, 'name' => 'Mantención web', 'billing_cycle' => 'monthly', 'currency' => 'CLP', 'price' => 100000,
            'start_date' => today()->toDateString(), 'status' => 'active', 'auto_renew' => false, ...$o,
        ]);
    }

    private function issuedInvoice(Client $c, array $o = []): Invoice
    {
        $svc = app(InvoiceService::class);
        $i = $svc->create(['client_id' => $c->id, 'concept' => 'Mantención web · octubre', 'amount_net' => 100000, 'currency' => 'CLP', 'due_date' => today()->addDays(10)->toDateString(), ...$o]);
        $svc->attachPdf($i, UploadedFile::fake()->create('f.pdf', 50, 'application/pdf'));

        return $svc->issue($i, 'F-100');
    }

    public function test_creating_portal_access_generates_password_and_queues_the_welcome_email(): void
    {
        $c = $this->company();
        $user = $this->portalUser($c);

        $this->assertTrue($user->isPortal());
        $this->assertSame(Role::PORTAL_SLUG, $user->role->slug);
        $this->assertTrue($user->must_change_password);
        $this->assertSame(1, EmailMessage::where('to_email', 'cliente@oritec.cl')->count());
        $this->assertSame([], $user->permissionKeys());
    }

    public function test_portal_users_are_kept_out_of_the_internal_crm_and_staff_out_of_the_portal(): void
    {
        $c = $this->company();
        $portal = $this->portalUser($c);
        $admin = $this->userWithRole('admin');

        $this->actingAs($portal)->get('/dashboard')->assertRedirect('/portal');
        $this->actingAs($portal)->get('/clients')->assertRedirect('/portal');
        $this->actingAs($portal)->postJson('/agent/chat', ['message' => 'hola'])->assertRedirect('/portal');
        $this->actingAs($portal)->get('/portal')->assertOk();

        $this->actingAs($admin)->get('/portal')->assertRedirect('/dashboard');
    }

    public function test_the_portal_only_shows_issued_invoices_of_its_own_company_and_hides_internal_data(): void
    {
        $mine = $this->company('Oritec SpA');
        $other = $this->company('Otra Empresa');
        $portal = $this->portalUser($mine);

        $visible = $this->issuedInvoice($mine);
        $draft = app(InvoiceService::class)->create(['client_id' => $mine->id, 'concept' => 'Aún sin PDF', 'amount_net' => 1, 'due_date' => today()->toDateString()]);
        $foreign = $this->issuedInvoice($other);

        $service = $this->service($mine, ['internal_notes' => 'SECRETO-INTERNO', 'price' => 123456]);
        app(ContractService::class)->addExpense($service, ['concept' => 'GASTO-SECRETO', 'amount' => 999, 'incurred_on' => today()->toDateString()]);

        $res = $this->actingAs($portal)->get('/portal/facturas')->assertOk();
        $ids = collect($res->viewData('page')['props']['invoices'])->pluck('id')->all();
        $this->assertSame([$visible->id], $ids);

        $this->actingAs($portal)->get("/portal/facturas/{$visible->id}/pdf")->assertOk();
        $this->actingAs($portal)->get("/portal/facturas/{$foreign->id}/pdf")->assertNotFound();
        $this->actingAs($portal)->get("/portal/facturas/{$draft->id}/pdf")->assertNotFound();

        $json = json_encode($this->actingAs($portal)->get('/portal/servicios')->assertOk()->viewData('page')['props']);
        $this->assertStringNotContainsString('SECRETO', $json);
        $this->assertStringNotContainsString('GASTO', $json);
        $this->assertStringNotContainsString('123456', $json);
        $this->assertStringNotContainsString('internal_notes', $json);
    }

    public function test_recurring_services_generate_scheduled_charges_once(): void
    {
        $c = $this->company();
        $this->service($c, ['start_date' => today()->addDays(3)->toDateString()]);

        $this->assertSame(1, app(ChargeGenerator::class)->run());
        $this->assertSame(0, app(ChargeGenerator::class)->run());

        $i = Invoice::first();
        $this->assertSame('scheduled', $i->status);
        $this->assertTrue($i->auto_remind);
        // Por defecto los cobros son boletas de honorarios: sin IVA (el cliente de prueba es una persona: paga el bruto).
        $this->assertSame('boleta', $i->document_type);
        $this->assertEquals(100000, $i->amount_total);
    }

    public function test_one_time_services_do_not_schedule_charges(): void
    {
        $c = $this->company();
        $this->service($c, ['billing_cycle' => 'one_time']);

        $this->assertSame(0, app(ChargeGenerator::class)->run());
    }

    public function test_an_invoice_cannot_be_issued_without_pdf_and_uf_amounts_get_a_clp_equivalent(): void
    {
        $c = $this->company();
        $svc = app(InvoiceService::class);
        $i = $svc->create(['client_id' => $c->id, 'document_type' => 'factura', 'concept' => 'Hosting', 'currency' => 'UF', 'amount_net' => 10, 'due_date' => today()->toDateString()]);

        $this->assertEquals(11.9, $i->amount_total);
        $this->assertEquals(476000, $i->total_clp);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $svc->issue($i);
    }

    public function test_reminders_follow_the_configured_days_and_stop_when_paid(): void
    {
        $c = $this->company();
        $this->portalUser($c);
        $i = $this->issuedInvoice($c, ['auto_remind' => true, 'due_date' => today()->addDays(5)->toDateString()]); // vence en 5 días: toca el de «5 días antes»

        $this->assertSame(1, app(ReminderRunner::class)->run());
        $this->assertSame(0, app(ReminderRunner::class)->run()); // no se repite

        app(InvoiceService::class)->markPaid($i);
        $i->update(['due_date' => today()->subDays(3)]);
        $this->assertSame(0, app(ReminderRunner::class)->run());
    }

    public function test_manual_send_requires_an_issued_invoice_and_a_recipient(): void
    {
        $c = $this->company();
        $draft = app(InvoiceService::class)->create(['client_id' => $c->id, 'concept' => 'X', 'amount_net' => 10, 'due_date' => today()->toDateString()]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(InvoiceService::class)->send($draft);
    }

    public function test_costs_are_only_visible_with_the_costs_permission(): void
    {
        $c = $this->company();
        $service = $this->service($c);
        app(ContractService::class)->addExpense($service, ['concept' => 'Licencia', 'amount' => 5000, 'incurred_on' => today()->toDateString()]);

        $viewer = $this->userWithPermissions(['contracts.view']);
        $props = $this->actingAs($viewer)->get("/contracts/{$service->id}")->assertOk()->viewData('page')['props'];
        $this->assertNull($props['expenses']);
        $this->assertNull($props['financials']);

        $this->actingAs($viewer)->post("/contracts/{$service->id}/expenses", ['concept' => 'x', 'currency' => 'CLP', 'amount' => 1, 'incurred_on' => today()->toDateString()])->assertForbidden();

        $accountant = $this->userWithPermissions(['contracts.view', 'contracts.costs']);
        $props = $this->actingAs($accountant)->get("/contracts/{$service->id}")->assertOk()->viewData('page')['props'];
        $this->assertCount(1, $props['expenses']);
        $this->assertEquals(5000, $props['financials']['expenses']);
    }

    public function test_only_issued_unpaid_invoices_can_be_paid_and_paid_ones_are_not_deletable(): void
    {
        $c = $this->company();
        $i = $this->issuedInvoice($c);
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->post("/billing/invoices/{$i->id}/pay", ['payment_method' => 'Transferencia'])->assertRedirect();
        $this->assertSame('paid', $i->fresh()->status);
        $this->actingAs($admin)->delete("/billing/invoices/{$i->id}")->assertStatus(422);
    }

    public function test_deleting_a_company_deactivates_its_portal_access_and_sends_billing_to_the_trash(): void
    {
        $c = $this->company();
        $user = $this->portalUser($c);
        $this->service($c);
        $this->issuedInvoice($c);

        app(\App\Services\CascadeDelete::class)->client($c);

        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame(0, ClientService::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(1, Invoice::onlyTrashed()->count());
    }
}
