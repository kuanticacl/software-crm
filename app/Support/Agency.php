<?php

namespace App\Support;

use App\Models\Setting;

/** Datos de mi empresa que aparecen en la firma y el pie de las propuestas (editables en «Datos de mi empresa»). */
class Agency
{
    public const FIELDS = [
        'legal_name' => 'MecaniQ',
        'tax_id' => '',
        'address' => 'Chile',
        'email' => 'contacto@mecaniq.cl',
        'phone' => '',
        'website' => 'https://www.mecaniq.cl',
        'instagram' => '',
        'linkedin' => '',
        'facebook' => '',
        'youtube' => '',
        'tiktok' => '',
        'about_enabled' => '1',
        'about_title' => 'MecaniQ',
        'about_text' => 'La app móvil para talleres mecánicos: órdenes de trabajo, cotizaciones, clientes y cobros desde el celular.',
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
        return [];
    }

    /** Empresas relacionadas con logo en public/brand/partners (vacío = no se muestra el pie «holding»). @return array<int, array{name: string, url: string, logo: string}> */
    public static function holding(): array
    {
        return [];
    }
}
