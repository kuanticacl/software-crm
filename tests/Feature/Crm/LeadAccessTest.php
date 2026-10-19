<?php

namespace Tests\Feature\Crm;

use App\Models\LeadNote;
use App\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCrmData;
use Tests\TestCase;

class LeadAccessTest extends TestCase
{
    use CreatesCrmData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCrm();
    }

    public function test_user_only_sees_leads_assigned_to_them()
    {
        $me = $this->userWithRole('comercial');
        $other = $this->userWithRole('comercial');
        $mine = $this->makeLead(['assigned_to' => $me->id]);
        $theirs = $this->makeLead(['assigned_to' => $other->id]);

        $this->actingAs($me)->get(route('leads.show', $mine))->assertOk();
        $this->actingAs($me)->get(route('leads.show', $theirs))->assertForbidden();
    }

    public function test_view_all_sees_every_lead()
    {
        $supervisor = $this->userWithRole('supervisor');
        $lead = $this->makeLead(['assigned_to' => $this->userWithRole('comercial')->id]);

        $this->actingAs($supervisor)->get(route('leads.show', $lead))->assertOk();
    }

    public function test_private_notes_are_only_visible_to_their_author()
    {
        $author = $this->userWithRole('supervisor');
        $other = $this->userWithRole('supervisor');
        $lead = $this->makeLead();

        $lead->notes()->create(['user_id' => $author->id, 'body' => 'privada', 'is_private' => true]);
        $lead->notes()->create(['user_id' => $author->id, 'body' => 'pública', 'is_private' => false]);

        $this->assertCount(2, LeadNote::visibleTo($author)->get());
        $this->assertCount(1, LeadNote::visibleTo($other)->get());
    }

    public function test_moving_a_lead_changes_stage_and_logs_the_change()
    {
        $user = $this->userWithRole('supervisor');
        $lead = $this->makeLead();
        $target = PipelineStage::where('name', 'Demo agendada')->first();

        $this->actingAs($user)->putJson(route('leads.move', $lead), ['stage_id' => $target->id])->assertOk();

        $this->assertSame($target->id, $lead->fresh()->stage_id);
        $this->assertTrue($lead->activities()->where('type', 'stage_changed')->exists());
    }

    public function test_lead_can_be_placed_after_another_in_the_same_column()
    {
        $user = $this->userWithRole('supervisor');
        $first = $this->makeLead(['email' => '1@x.cl']);
        $second = $this->makeLead(['email' => '2@x.cl', 'position' => $first->position + 1000]);
        $third = $this->makeLead(['email' => '3@x.cl', 'position' => $first->position + 2000]);

        // mover el primero justo después del segundo
        $this->actingAs($user)->putJson(route('leads.move', $first), [
            'stage_id' => $first->stage_id,
            'after_id' => $second->id,
        ])->assertOk();

        $this->assertTrue($first->fresh()->position > $second->fresh()->position);
        $this->assertTrue($first->fresh()->position < $third->fresh()->position);
    }

    public function test_deleting_a_stage_moves_its_leads()
    {
        $admin = $this->userWithRole('admin');
        $stage = PipelineStage::where('name', 'Contactado')->first();
        $target = PipelineStage::where('name', 'Ingreso')->first();
        $lead = $this->makeLead(['stage_id' => $stage->id]);

        $this->actingAs($admin)->delete(route('stages.destroy', $stage), ['move_to' => $target->id]);

        $this->assertNull(PipelineStage::find($stage->id));
        $this->assertSame($target->id, $lead->fresh()->stage_id);
    }

    public function test_assigning_and_quick_edit_answer_json_for_the_side_panel(): void
    {
        $supervisor = $this->userWithRole('supervisor');
        $other = $this->userWithRole('comercial');
        $lead = $this->makeLead();

        $this->actingAs($supervisor)->putJson(route('leads.assign', $lead), ['assigned_to' => $other->id])->assertOk()->assertJsonPath('ok', true);
        $this->assertSame($other->id, $lead->fresh()->assigned_to);

        $this->actingAs($supervisor)->patchJson(route('leads.quick', $lead), ['priority' => 'urgent', 'tags' => ['caliente', 'caliente', 'norte']])->assertOk();
        $this->assertSame('urgent', $lead->fresh()->priority);
        $this->assertSame(['caliente', 'norte'], $lead->fresh()->tags);
    }
}
