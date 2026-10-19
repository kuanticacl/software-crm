<?php

namespace Tests\Feature\Crm;

use App\Models\Automation;
use App\Models\EmailMessage;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Setting;
use App\Services\LeadService;
use App\Services\Leads\LeadAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesCrmData;
use Tests\TestCase;

class LeadAssignmentTest extends TestCase
{
    use CreatesCrmData;
    use RefreshDatabase;

    private LeadSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCrm();
        Queue::fake();
        $this->source = LeadSource::where('slug', 'meta-ads')->first();
    }

    private function ingest(array $over = [])
    {
        return $this->postJson('/api/v1/leads', ['first_name' => 'Camila', 'last_name' => 'Soto', 'email' => 'camila'.uniqid().'@inmobiliaria.cl', 'company' => 'Inmobiliaria Sur', 'message' => 'Quiero cotizar', ...$over], ['X-Api-Key' => $this->source->api_key])->assertCreated();
    }

    private function person(string $email, array $over = [])
    {
        return $this->userWithRole('comercial', ['email' => $email, 'name' => $over['name'] ?? ucfirst(strtok($email, '@')), ...$over]);
    }

    private function leadOf($response): Lead
    {
        return Lead::findOrFail($response->json('data.id'));
    }

    public function test_without_defaults_or_owners_the_lead_stays_unassigned_as_before(): void
    {
        $this->assertNull($this->leadOf($this->ingest())->assigned_to);
    }

    public function test_lead_goes_to_the_default_assignee_when_the_source_has_no_owners(): void
    {
        $angelo = $this->person('angelo@mecaniq.test', ['name' => 'Angelo Rossi']);
        Setting::put(LeadAssignment::DEFAULT_ASSIGNEE, (string) $angelo->id);

        $lead = $this->leadOf($this->ingest());

        $this->assertSame($angelo->id, $lead->assigned_to);
        $this->assertTrue($lead->activities()->where('type', 'assigned')->exists());
    }

    public function test_manually_created_leads_are_also_assigned_by_default(): void
    {
        $angelo = $this->person('angelo@mecaniq.test');
        Setting::put(LeadAssignment::DEFAULT_ASSIGNEE, (string) $angelo->id);

        $lead = app(LeadService::class)->create(['first_name' => 'Manual', 'email' => 'manual@x.cl', 'source_id' => $this->source->id]);

        $this->assertSame($angelo->id, $lead->assigned_to);
    }

    public function test_an_explicit_assignee_is_never_overridden(): void
    {
        $angelo = $this->person('angelo@mecaniq.test');
        $other = $this->person('otra@mecaniq.test');
        Setting::put(LeadAssignment::DEFAULT_ASSIGNEE, (string) $angelo->id);

        $lead = app(LeadService::class)->create(['first_name' => 'Manual', 'email' => 'manual@x.cl', 'source_id' => $this->source->id, 'assigned_to' => $other->id]);

        $this->assertSame($other->id, $lead->assigned_to);
    }

    public function test_source_owner_takes_precedence_over_the_default(): void
    {
        $angelo = $this->person('angelo@mecaniq.test');
        $owner = $this->person('ana@mecaniq.test');
        Setting::put(LeadAssignment::DEFAULT_ASSIGNEE, (string) $angelo->id);
        $this->source->owners()->sync([$owner->id]);

        $this->assertSame($owner->id, $this->leadOf($this->ingest())->assigned_to);
    }

    public function test_several_owners_share_the_leads_evenly(): void
    {
        $a = $this->person('a@mecaniq.test');
        $b = $this->person('b@mecaniq.test');
        $this->source->owners()->sync([$a->id, $b->id]);

        $assigned = collect(range(1, 4))->map(fn () => $this->leadOf($this->ingest())->assigned_to);

        $this->assertSame(2, $assigned->filter(fn ($id) => $id === $a->id)->count());
        $this->assertSame(2, $assigned->filter(fn ($id) => $id === $b->id)->count());
    }

    public function test_inactive_owners_are_ignored(): void
    {
        $angelo = $this->person('angelo@mecaniq.test');
        $gone = $this->person('baja@mecaniq.test', ['is_active' => false]);
        Setting::put(LeadAssignment::DEFAULT_ASSIGNEE, (string) $angelo->id);
        $this->source->owners()->sync([$gone->id]);

        $this->assertSame($angelo->id, $this->leadOf($this->ingest())->assigned_to);
    }

    public function test_default_alert_automation_and_settings_are_created_by_the_migration(): void
    {
        $automation = Automation::where('name', 'Alerta de cliente nuevo al equipo')->first();

        $this->assertNotNull($automation);
        $this->assertSame('lead.created', $automation->trigger);
        $this->assertSame('team', $automation->to_mode);
        $this->assertTrue($automation->is_active);
        $this->assertSame('alerta-nuevo-cliente', $automation->template->slug);
        $this->assertSame(['arossi@mecaniq.cl'], app(LeadAssignment::class)->notifyEmails());
    }

    public function test_new_lead_alerts_assignee_owners_and_default_emails_without_duplicates(): void
    {
        $a = $this->person('a@mecaniq.test', ['name' => 'Ana Pérez']);
        $b = $this->person('b@mecaniq.test', ['name' => 'Beto Soto']);
        $this->source->owners()->sync([$a->id, $b->id]);
        Setting::put(LeadAssignment::NOTIFY_EMAILS, 'arossi@mecaniq.cl, A@mecaniq.test');

        $lead = $this->leadOf($this->ingest(['message' => '<b>hola</b> quiero una demo']));

        $automation = Automation::where('name', 'Alerta de cliente nuevo al equipo')->first();
        $messages = EmailMessage::where('automation_id', $automation->id)->get();
        $emails = $messages->pluck('to_email')->sort()->values()->all();

        $this->assertSame(['a@mecaniq.test', 'arossi@mecaniq.cl', 'b@mecaniq.test'], $emails, 'cada persona recibe un solo correo');
        $first = $messages->firstWhere('to_email', 'a@mecaniq.test');
        $this->assertSame('Camila Soto', $first->variables['lead_name']);
        $this->assertSame($lead->assignee->name, $first->variables['assignee_name']);
        $this->assertStringContainsString('/leads/'.$lead->id, $first->variables['lead_url']);
        $this->assertFalse((bool) $first->track_opens);
        $this->assertSame(1, $automation->fresh()->runs_count);
    }

    public function test_alert_goes_to_the_default_emails_even_when_nobody_is_assigned(): void
    {
        $this->ingest();

        $automation = Automation::where('name', 'Alerta de cliente nuevo al equipo')->first();
        $this->assertSame(['arossi@mecaniq.cl'], EmailMessage::where('automation_id', $automation->id)->pluck('to_email')->all());
    }

    public function test_admin_manages_owners_and_defaults_from_sources(): void
    {
        $admin = $this->userWithRole('admin');
        $a = $this->person('a@mecaniq.test');
        $b = $this->person('b@mecaniq.test');
        $base = ['name' => $this->source->name, 'color' => $this->source->color ?: '#1AA0E4', 'icon' => $this->source->icon ?: 'globe', 'is_active' => true];

        $this->actingAs($admin)->put(route('sources.update', $this->source), [...$base, 'owner_ids' => [$a->id, $b->id]])->assertRedirect();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->source->owners()->pluck('users.id')->all());

        $this->actingAs($admin)->put(route('sources.update', $this->source), [...$base, 'owner_ids' => [$b->id]])->assertRedirect();
        $this->assertSame([$b->id], $this->source->owners()->pluck('users.id')->all());

        $this->actingAs($admin)->put(route('sources.defaults'), ['assignee_id' => $a->id, 'notify_emails' => "arossi@mecaniq.cl\nventas@mecaniq.cl"])->assertRedirect();
        $this->assertSame($a->id, (int) Setting::get(LeadAssignment::DEFAULT_ASSIGNEE));
        $this->assertSame(['arossi@mecaniq.cl', 'ventas@mecaniq.cl'], app(LeadAssignment::class)->notifyEmails());
    }

    public function test_defaults_reject_invalid_emails_and_inactive_users(): void
    {
        $admin = $this->userWithRole('admin');
        $gone = $this->person('baja@mecaniq.test', ['is_active' => false]);

        $this->actingAs($admin)->put(route('sources.defaults'), ['assignee_id' => null, 'notify_emails' => 'no-es-un-correo'])->assertSessionHasErrors('notify_emails');
        $this->actingAs($admin)->put(route('sources.defaults'), ['assignee_id' => $gone->id, 'notify_emails' => ''])->assertSessionHasErrors('assignee_id');
        $this->assertSame(['arossi@mecaniq.cl'], app(LeadAssignment::class)->notifyEmails(), 'no se pisó la configuración anterior');
    }

    public function test_users_without_permission_cannot_change_defaults(): void
    {
        $user = $this->userWithPermissions(['leads.view']);

        $this->actingAs($user)->put(route('sources.defaults'), ['notify_emails' => 'x@y.cl'])->assertForbidden();
    }
}
