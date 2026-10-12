<?php

namespace Tests\Feature\Crm;

use App\Models\EmailMessage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\CreatesCrmData;
use Tests\TestCase;

class UserEmailsTest extends TestCase
{
    use CreatesCrmData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCrm();
        Bus::fake();
    }

    public function test_creating_a_user_queues_the_welcome_email_with_access_data()
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Ana Pérez', 'email' => 'ana@viviendaenregla.cl', 'role_id' => Role::first()->id, 'is_active' => true,
            'password' => 'Qb-Clave-Segura-1', 'password_confirmation' => 'Qb-Clave-Segura-1',
        ])->assertRedirect();

        $m = EmailMessage::where('to_email', 'ana@viviendaenregla.cl')->firstOrFail();
        $this->assertSame('Qb-Clave-Segura-1', $m->variables['password']);
        $this->assertStringEndsWith('/login', $m->variables['login_url']);
        $this->assertSame('bienvenida-usuario', $m->template->slug);
        $this->assertSame('blocks', $m->template->editor); // editable en el editor visual
        $this->assertNotEmpty($m->template->design['blocks']);
    }

    public function test_password_reset_request_queues_the_branded_email()
    {
        $user = User::factory()->create(['email' => 'beto@viviendaenregla.cl']);

        $this->post('/forgot-password', ['email' => 'beto@viviendaenregla.cl']);

        $m = EmailMessage::where('to_email', 'beto@viviendaenregla.cl')->firstOrFail();
        $this->assertSame('recuperar-password', $m->template->slug);
        $this->assertStringContainsString('/reset-password/', $m->variables['reset_url']);
        $this->assertStringContainsString('email=beto%40viviendaenregla.cl', $m->variables['reset_url']);
    }

    public function test_holding_footer_is_only_added_when_the_brand_has_related_companies()
    {
        $composer = app(\App\Services\Email\EmailComposer::class);
        $html = $composer->renderContent('Hola', '<html><body><p>Hola</p></body></html>', [])['html'];

        // Vivienda en Regla no tiene empresas relacionadas: no se agrega el pie «holding».
        $this->assertSame([], \App\Support\Agency::holding());
        $this->assertStringNotContainsString('Empresas relacionadas', $html);
        $this->assertSame($html, $composer->withHoldingFooter($html));
    }

    public function test_landing_kit_creates_the_website_origins_and_templates()
    {
        (new \App\Services\Email\LandingKit)->install();

        foreach (['formulario-contacto', 'formulario-cotizacion'] as $slug) {
            $this->assertTrue(\App\Models\LeadSource::where('slug', $slug)->exists(), $slug);
        }
        foreach (['gracias-contacto', 'gracias-cotizacion', 'bienvenida-usuario'] as $slug) {
            $this->assertTrue(\App\Models\EmailTemplate::where('slug', $slug)->exists(), $slug);
        }
    }

    public function test_credentials_are_redacted_even_when_the_recipient_is_suppressed()
    {
        Bus::fake()->except([]);
        $user = User::factory()->create(['email' => 'baja@viviendaenregla.cl']);
        \App\Models\EmailSuppression::create(['email' => 'baja@viviendaenregla.cl', 'reason' => 'bounce']);

        app(\App\Services\Email\UserMailer::class)->sendWelcome($user, 'Qb-Clave-Segura-1');
        $m = EmailMessage::where('to_email', 'baja@viviendaenregla.cl')->firstOrFail();
        (new \App\Jobs\SendEmailMessage($m->id))->handle(app(\App\Services\Email\EmailComposer::class));

        $this->assertSame('suppressed', $m->fresh()->status);
        $this->assertNotSame('Qb-Clave-Segura-1', $m->fresh()->variables['password']);
    }
}
