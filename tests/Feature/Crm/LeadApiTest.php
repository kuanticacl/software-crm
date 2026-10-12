<?php

namespace Tests\Feature\Crm;

use App\Models\Lead;
use App\Models\LeadField;
use App\Models\LeadSource;
use App\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCrmData;
use Tests\TestCase;

class LeadApiTest extends TestCase
{
    use CreatesCrmData;
    use RefreshDatabase;

    private LeadSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCrm();
        $this->source = LeadSource::where('slug', 'meta-ads')->first();
    }

    private function post(array $data, ?string $key = 'default')
    {
        $key = $key === 'default' ? $this->source->api_key : $key;

        return $this->postJson('/api/v1/leads', $data, array_filter(['X-Api-Key' => $key]));
    }

    public function test_requires_a_valid_api_key()
    {
        $this->post(['first_name' => 'Ana', 'email' => 'a@b.cl'], null)->assertUnauthorized();
        $this->post(['first_name' => 'Ana', 'email' => 'a@b.cl'], 'invalida')->assertUnauthorized();
    }

    public function test_inactive_source_is_rejected()
    {
        $this->source->update(['is_active' => false]);

        $this->post(['first_name' => 'Ana', 'email' => 'a@b.cl'])->assertForbidden();
    }

    public function test_creates_lead_with_source_from_key_and_first_stage()
    {
        $this->post([
            'first_name' => 'María',
            'last_name' => 'González',
            'email' => 'maria@ejemplo.cl',
            'utm_source' => 'google',
            'utm_campaign' => 'otono',
            'ip' => '200.1.2.3',
            'city' => 'Santiago',
        ])->assertCreated()->assertJsonPath('data.source', 'meta-ads');

        $lead = Lead::first();
        $this->assertSame($this->source->id, $lead->source_id);
        $this->assertSame(PipelineStage::initial()->id, $lead->stage_id);
        $this->assertSame('google', $lead->utm_source);
        $this->assertSame('200.1.2.3', $lead->ip_address);
        $this->assertSame('Santiago', $lead->city);
        $this->assertSame('created', $lead->activities()->first()->type);
    }

    public function test_requires_name_and_a_contact_method()
    {
        $this->post(['email' => 'a@b.cl'])->assertUnprocessable();
        $this->post(['first_name' => 'Solo nombre'])->assertUnprocessable();
    }

    public function test_full_name_is_split()
    {
        $this->post(['name' => 'Ana María Pérez', 'phone' => '+56911112222'])->assertCreated();

        $lead = Lead::first();
        $this->assertSame('Ana', $lead->first_name);
        $this->assertSame('María Pérez', $lead->last_name);
    }

    public function test_custom_fields_are_stored_and_unknown_data_goes_to_meta()
    {
        LeadField::create(['label' => 'Proyecto', 'key' => 'proyecto', 'type' => 'text']);

        $this->post([
            'first_name' => 'Ana',
            'email' => 'a@b.cl',
            'proyecto' => 'Torre Norte',
            'campo_raro' => 'xyz',
        ])->assertCreated();

        $lead = Lead::first();
        $this->assertSame('Torre Norte', $lead->custom['proyecto']);
        $this->assertSame('xyz', $lead->meta['extra']['campo_raro']);
    }

    public function test_audience_subscribes_the_lead_email_without_duplicates()
    {
        $r = $this->post(['first_name' => 'Ana', 'email' => 'Ana@b.cl', 'audience' => 'Boletín Vivienda en Regla']);
        $r->assertCreated()->assertJsonPath('data.audience.status', 'subscribed');

        $this->post(['first_name' => 'Ana', 'email' => 'ana@b.cl', 'audience' => 'boletín vivienda en regla'])
            ->assertJsonPath('data.audience.status', 'already_subscribed');

        $this->assertSame(1, \App\Models\ContactList::count());
        $this->assertSame(1, \App\Models\ContactListEntry::count());
    }

    public function test_landing_kit_is_idempotent_and_template_renders()
    {
        $kit = new \App\Services\Email\LandingKit;
        $this->assertNotEmpty($kit->install());
        $this->assertEmpty($kit->install());

        $this->assertSame(2, LeadSource::where('slug', 'like', 'formulario-%')->count());
        $this->assertTrue(\App\Models\EmailTemplate::where('slug', 'confirmacion-asesoria')->exists());
        $this->assertTrue(\App\Models\ContactList::where('name', 'Boletín Vivienda en Regla')->exists());
    }
}
