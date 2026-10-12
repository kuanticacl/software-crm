<?php

namespace App\Support;

use App\Models\Setting;

/** Datos de la agencia que aparecen en la firma y el pie de las propuestas (editables en «Datos de la agencia»). */
class Agency
{
    public const FIELDS = [
        'legal_name' => 'Catalina Quiroga Muñoz Jusus',
        'tax_id' => '20.046.510-5',
        'address' => 'Santiago, Chile',
        'email' => 'contacto@viviendaenregla.cl',
        'phone' => '',
        'website' => 'https://www.viviendaenregla.cl',
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

    /** Logos de tecnologías que usa la agencia (archivo en public/brand/tech). @return array<int, string> */
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
