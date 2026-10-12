<?php

namespace Tests\Feature\Billing;

use App\Models\Client;
use App\Models\ClientService;
use App\Models\Invoice;
use App\Services\Billing\ContractService;
use App\Services\Billing\InvoiceService;
use App\Support\Honorarios;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesCrmData;
use Tests\TestCase;

class HonorariosTest extends TestCase
{
    use CreatesCrmData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCrm();
        Queue::fake();
    }

    public function test_retention_schedule_follows_the_law(): void
    {
        $this->assertSame(15.25, Honorarios::rate(2026));
        $this->assertSame(16.0, Honorarios::rate(2027));
        $this->assertSame(17.0, Honorarios::rate(2030));
    }

    public function test_companies_retain_and_pay_the_net_of_retention(): void
    {
        $company = Client::create(['kind' => 'company', 'name' => 'Oritec SpA', 'is_active' => true]);
        $i = app(InvoiceService::class)->create(['client_id' => $company->id, 'concept' => 'Honorarios', 'amount_net' => 1000000, 'due_date' => today()->toDateString(), 'retention_rate' => 15.25]);

        $this->assertSame('boleta', $i->document_type);
        $this->assertEquals(0, $i->tax_rate);
        $this->assertEquals(152500, $i->retention_amount);
        $this->assertEquals(847500, $i->amount_total); // líquido
        $this->assertEquals(1000000, $i->net_clp);
    }

    public function test_people_pay_the_gross_amount(): void
    {
        $person = Client::create(['kind' => 'person', 'name' => 'María González', 'is_active' => true]);
        $i = app(InvoiceService::class)->create(['client_id' => $person->id, 'concept' => 'Planos', 'amount_net' => 400000, 'due_date' => today()->toDateString(), 'retention_rate' => 15.25]);

        $this->assertFalse($i->retention_by_client);
        $this->assertEquals(61000, $i->retention_amount); // informativa: la paga quien emite
        $this->assertEquals(400000, $i->amount_total);
    }

    public function test_factura_adds_iva_and_other_documents_do_not(): void
    {
        $c = Client::create(['kind' => 'company', 'name' => 'Retail SpA', 'is_active' => true]);
        $svc = app(InvoiceService::class);
        $f = $svc->create(['client_id' => $c->id, 'document_type' => 'factura', 'concept' => 'Obra', 'amount_net' => 100000, 'due_date' => today()->toDateString()]);
        $o = $svc->create(['client_id' => $c->id, 'document_type' => 'otro', 'kind' => 'obra', 'concept' => 'Estado de pago', 'amount_net' => 100000, 'due_date' => today()->toDateString()]);

        $this->assertEquals(119000, $f->amount_total);
        $this->assertEquals(0, $f->retention_amount);
        $this->assertEquals(100000, $o->amount_total);
        $this->assertSame('obra', $o->kind);
    }

    public function test_a_project_creates_its_payment_plan_as_scheduled_charges(): void
    {
        $c = Client::create(['kind' => 'person', 'name' => 'Pedro Soto', 'is_active' => true]);
        $s = app(ContractService::class)->create([
            'client_id' => $c->id, 'name' => 'Regularización Los Aromos 123', 'billing_cycle' => 'one_time', 'modality' => 'honorarios_obra', 'currency' => 'CLP',
            'price' => 800000, 'start_date' => today()->toDateString(), 'status' => 'active', 'project_address' => 'Los Aromos 123, Ñuñoa', 'works_budget' => 12000000,
            'payment_plan' => [
                ['concept' => 'Anticipo', 'kind' => 'honorarios', 'amount' => 400000, 'due_date' => today()->toDateString()],
                ['concept' => 'Entrega final', 'kind' => 'honorarios', 'amount' => 400000, 'due_date' => today()->addMonth()->toDateString()],
                ['concept' => 'Avance de obra 1', 'kind' => 'obra', 'amount' => 3000000, 'due_date' => today()->addMonths(2)->toDateString()],
            ],
        ]);

        $this->assertInstanceOf(ClientService::class, $s);
        $this->assertSame('honorarios_obra', $s->modality);
        $this->assertSame(3, Invoice::where('client_service_id', $s->id)->where('status', 'scheduled')->count());
        $this->assertSame(2, Invoice::where('client_service_id', $s->id)->where('document_type', 'boleta')->count());
        $this->assertSame(1, Invoice::where('client_service_id', $s->id)->where('kind', 'obra')->count());
        $this->assertFalse($s->isRecurring());
    }

    public function test_client_form_requires_a_valid_kind(): void
    {
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin)->post('/clients', ['kind' => 'otra', 'name' => 'X'])->assertSessionHasErrors('kind');
        $this->actingAs($admin)->post('/clients', ['kind' => 'person', 'name' => 'Ana Díaz', 'tax_id' => '20.046.510-5'])->assertRedirect();
        $this->assertDatabaseHas('clients', ['name' => 'Ana Díaz', 'kind' => 'person']);
    }
}
