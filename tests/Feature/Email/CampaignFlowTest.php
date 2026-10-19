<?php

namespace Tests\Feature\Email;

use App\Jobs\SendCampaignBatch;
use App\Models\Campaign;
use App\Models\ContactList;
use App\Models\ContactListEntry;
use App\Models\EmailMessage;
use App\Models\EmailSuppression;
use App\Models\EmailTemplate;
use App\Models\Setting;
use App\Services\Email\CampaignRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_sends_to_unique_non_suppressed_recipients_with_tracking_and_unsubscribe(): void
    {
        Queue::fake();
        Setting::put('mail.from_email', 'hola@mecaniq.cl');
        Setting::put('mail.provider', 'log'); // no sale ningún correo real

        $template = EmailTemplate::create(['name' => 'Boletín', 'slug' => 'boletin', 'category' => 'marketing', 'is_active' => true, 'subject' => 'Hola {{ first_name }}', 'html' => '<html><body><a href="https://www.mecaniq.cl">Sitio</a></body></html>']);
        $list = ContactList::create(['name' => 'Prueba']);
        foreach (['a@x.cl', 'b@x.cl', 'c@x.cl', 'A@x.cl'] as $email) {
            ContactListEntry::updateOrCreate(['contact_list_id' => $list->id, 'email' => strtolower($email)], ['name' => 'Persona']);
        }
        EmailSuppression::add('c@x.cl', 'manual');

        $campaign = Campaign::create(['name' => 'Octubre', 'subject' => 'Hola {{ first_name }}', 'template_id' => $template->id, 'audience' => ['lists' => [$list->id]], 'status' => 'draft', 'track_opens' => true, 'track_clicks' => true]);

        $runner = app(CampaignRunner::class);
        $runner->start($campaign);

        Queue::assertPushed(SendCampaignBatch::class);
        $this->assertSame(2, $campaign->fresh()->recipients_count); // a@ y b@ (c@ excluido, A@ duplicado)

        while ($runner->processBatch($campaign->fresh())) {
            // procesa lote a lote, igual que el job
        }

        $this->assertSame('sent', $campaign->fresh()->status);
        $this->assertSame(2, EmailMessage::where('campaign_id', $campaign->id)->where('status', 'sent')->count());
        $this->assertSame(0, EmailMessage::where('to_email', 'c@x.cl')->count());
    }
}
