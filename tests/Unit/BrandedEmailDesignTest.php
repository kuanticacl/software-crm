<?php

namespace Tests\Unit;

use App\Services\Ai\BrandedEmailDesign;
use Tests\TestCase;

class BrandedEmailDesignTest extends TestCase
{
    public function test_always_adds_official_header_and_footer_and_forces_brand_colors(): void
    {
        $design = BrandedEmailDesign::build([
            ['type' => 'html', 'text' => '<script>alert(1)</script>'],
            ['type' => 'button', 'label' => 'Ver', 'url' => 'https://evil.example.com'],
            ['type' => 'text', 'text' => 'Hola [x](javascript:alert(1)) **fuerte**'],
        ]);

        $blocks = $design['blocks'];
        $this->assertSame('header', $blocks[0]['type']);
        $this->assertStringEndsWith('/brand/vivienda-logo-dark.png', $blocks[0]['props']['logoUrl']);
        $this->assertSame('footer', end($blocks)['type']);
        $this->assertTrue(end($blocks)['props']['showUnsubscribe']);
        $this->assertNotContains('html', array_column($blocks, 'type'));

        $button = collect($blocks)->firstWhere('type', 'button');
        $this->assertSame('#3DBB6C', $button['props']['bg']);
        $this->assertSame(999, $button['props']['radius']);
        $this->assertSame('https://www.viviendaenregla.cl', $button['props']['href']);

        $text = collect($blocks)->firstWhere('type', 'text');
        $this->assertStringNotContainsString('javascript', $text['props']['text']);
    }

    public function test_images_are_only_accepted_when_provided_by_the_user(): void
    {
        $blocks = BrandedEmailDesign::build([
            ['type' => 'image', 'image' => 'https://cdn.example.com/a.jpg'],
            ['type' => 'image', 'image' => 'https://other.example.com/b.jpg'],
        ], ['images' => ['https://cdn.example.com/a.jpg']])['blocks'];

        $images = collect($blocks)->where('type', 'image');
        $this->assertCount(1, $images);
        $this->assertSame('https://cdn.example.com/a.jpg', $images->first()['props']['src']);
    }

    public function test_transactional_emails_have_no_unsubscribe_link(): void
    {
        $blocks = BrandedEmailDesign::build([['type' => 'text', 'text' => 'Gracias']], ['transactional' => true])['blocks'];

        $this->assertFalse(end($blocks)['props']['showUnsubscribe']);
    }
}
