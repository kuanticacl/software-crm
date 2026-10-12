<?php

namespace App\Support;

use App\Models\Setting;

/** Datos de mi empresa que aparecen en la firma y el pie de las propuestas (editables en «Datos de mi empresa»). */
class Agency
{
    public const FIELDS = [
        'legal_name' => 'Asesorías e Inversiones RH SpA',
        'tax_id' => '76.302.966-2',
        'address' => 'Av. Apoquindo 7935, Santiago, Chile',
        'email' => 'contacto@quiebre.cl',
        'phone' => '',
        'website' => 'https://www.quiebre.cl',
        'instagram' => '',
        'linkedin' => '',
        'facebook' => '',
        'youtube' => '',
        'tiktok' => '',
    ];

    public const SOCIALS = ['instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'facebook' => 'Facebook', 'youtube' => 'YouTube', 'tiktok' => 'TikTok'];

    /** @return array<string, string> */
    public static function profile(): array
    {
        return collect(self::FIELDS)->map(fn ($default, $key) => (string) (Setting::get("agency.{$key}", $default) ?? $default))->all();
    }

    /** Redes con URL configurada. @return array<string, array{label: string, url: string}> */
    public static function socials(): array
    {
        $p = self::profile();

        return collect(self::SOCIALS)->filter(fn ($l, $k) => $p[$k] !== '')->map(fn ($l, $k) => ['label' => $l, 'url' => $p[$k]])->all();
    }

    /** Logos de tecnologías que usa la empresa (archivo en public/brand/tech). @return array<int, string> */
    public static function tech(): array
    {
        return ['google', 'laravel', 'vuejs', 'nuxtjs', 'node', 'vercel', 'notion', 'trello', 'metricool', 'semrush', 'mailchimp', 'brevo'];
    }

    /** Empresas del holding (logo en public/brand/partners). @return array<int, array{name: string, url: string, logo: string}> */
    public static function holding(): array
    {
        return [
            ['name' => 'Be Modular', 'url' => 'https://www.bemodular.cl', 'logo' => 'bemodular'],
            ['name' => 'Kuántica', 'url' => 'https://www.kuantica.cl', 'logo' => 'kuantica'],
            ['name' => 'integraleads', 'url' => 'https://www.integraleads.cl', 'logo' => 'integraleads'],
        ];
    }
}
