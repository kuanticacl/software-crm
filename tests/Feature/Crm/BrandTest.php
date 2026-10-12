<?php

namespace Tests\Feature\Crm;

use App\Support\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesCrmData;
use Tests\TestCase;

class BrandTest extends TestCase
{
    use CreatesCrmData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCrm();
    }

    public function test_only_users_with_permission_can_open_it(): void
    {
        $this->actingAs($this->userWithRole('comercial'))->get('/identidad-visual')->assertForbidden();
        $this->actingAs($this->userWithRole('admin'))->get('/identidad-visual')->assertOk();
    }

    public function test_it_saves_only_valid_colors_and_known_variables(): void
    {
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin)->put('/identidad-visual', [
            'picks' => ['primary' => '#a9606b', 'background' => '#fbf8f6', 'sidebar' => '#f6efeb', 'text' => '#3b2f2f'],
            'light' => ['--primary' => '#A9606B', '--evil' => '#ffffff', '--ring' => 'url(javascript:alert(1))'],
            'dark' => ['--primary' => '#d9a0a5'],
            'radius' => '0.75rem', 'font_sans' => 'Jost', 'font_title' => 'Lora',
        ])->assertRedirect();

        $head = Theme::head();
        $this->assertStringContainsString('--primary:#a9606b', $head);
        $this->assertStringNotContainsString('--evil', $head);
        $this->assertStringNotContainsString('javascript', $head);
        $this->assertStringContainsString('fonts.bunny.net/css?family=jost:400,500,600,700|lora:500,600,700', $head);
        $this->assertStringContainsString('--radius:0.75rem', $head);

        $this->actingAs($admin)->put('/identidad-visual', [
            'picks' => ['primary' => 'rojo', 'background' => '#fbf8f6', 'sidebar' => '#f6efeb', 'text' => '#3b2f2f'], 'light' => [], 'dark' => [],
        ])->assertSessionHasErrors('picks.primary');
    }

    public function test_reset_clears_the_customization_but_keeps_the_hero(): void
    {
        Storage::fake('public');
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin)->post('/identidad-visual/hero', ['image' => UploadedFile::fake()->image('hero.jpg', 1600, 900)])->assertRedirect();
        $this->assertNotNull(Theme::heroUrl());

        $this->actingAs($admin)->post('/identidad-visual/reset')->assertRedirect();
        $this->assertSame('', Theme::head());
        $this->assertNotNull(Theme::heroUrl());

        $this->actingAs($admin)->delete('/identidad-visual/hero')->assertRedirect();
        $this->assertNull(Theme::heroUrl());
    }

    public function test_hero_rejects_non_images(): void
    {
        Storage::fake('public');
        $this->actingAs($this->userWithRole('admin'))->post('/identidad-visual/hero', ['image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertSessionHasErrors('image');
    }
}
