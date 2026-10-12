<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * Identidad visual editable desde el CRM («Identidad visual»): colores, radio, tipografías e imagen hero.
 * Lo que se guarda es el resultado ya calculado (variables CSS válidas) para aplicarlo sin lógica extra en el servidor.
 */
class Theme
{
    /** Variables CSS que se pueden personalizar. */
    public const VARS = [
        '--background', '--foreground', '--card', '--card-foreground', '--popover', '--popover-foreground', '--primary', '--primary-foreground',
        '--secondary', '--secondary-foreground', '--muted', '--muted-foreground', '--accent', '--accent-foreground', '--border', '--input', '--ring',
        '--chart-1', '--sidebar', '--sidebar-background', '--sidebar-foreground', '--sidebar-primary', '--sidebar-primary-foreground',
        '--sidebar-accent', '--sidebar-accent-foreground', '--sidebar-border', '--sidebar-ring',
    ];

    public const RADII = ['0.25rem', '0.5rem', '0.75rem', '1rem'];

    /** Tipografías disponibles (Bunny Fonts: sin rastreo y con licencia abierta). @var array<string, string> nombre => slug */
    public const SANS = [
        'Jost' => 'jost', 'Inter' => 'inter', 'Open Sans' => 'open-sans', 'Poppins' => 'poppins', 'Montserrat' => 'montserrat', 'DM Sans' => 'dm-sans',
        'Lato' => 'lato', 'Nunito Sans' => 'nunito-sans', 'Work Sans' => 'work-sans', 'Manrope' => 'manrope', 'Asap' => 'asap',
    ];

    public const TITLES = [
        'Cormorant Garamond' => 'cormorant-garamond', 'Playfair Display' => 'playfair-display', 'Lora' => 'lora', 'Libre Baskerville' => 'libre-baskerville',
        'DM Serif Display' => 'dm-serif-display', 'Fraunces' => 'fraunces',
    ];

    /** @return array{picks: array<string, string>|null, light: array<string, string>, dark: array<string, string>, radius: string|null, font_sans: string|null, font_title: string|null, hero: string|null} */
    public static function settings(): array
    {
        $json = fn (string $k) => json_decode((string) Setting::get($k, ''), true);

        return [
            'picks' => is_array($p = $json('brand.picks')) ? $p : null,
            'light' => self::cleanVars(is_array($l = $json('brand.light')) ? $l : []),
            'dark' => self::cleanVars(is_array($d = $json('brand.dark')) ? $d : []),
            'radius' => in_array($r = Setting::get('brand.radius'), self::RADII, true) ? $r : null,
            'font_sans' => isset(self::SANS[$f = Setting::get('brand.font_sans')]) ? $f : null,
            'font_title' => isset(self::TITLES[$t = Setting::get('brand.font_title')]) ? $t : null,
            'hero' => Setting::get('brand.hero'),
        ];
    }

    /** Solo variables conocidas con color #RRGGBB. @param  array<string, mixed>  $vars @return array<string, string> */
    public static function cleanVars(array $vars): array
    {
        $out = [];
        foreach ($vars as $k => $v) {
            if (in_array($k, self::VARS, true) && is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
                $out[$k] = strtolower($v);
            }
        }

        return $out;
    }

    /** Bloque <style> y <link> con las personalizaciones (vacío si no hay ninguna). */
    public static function head(): string
    {
        $s = self::settings();
        $css = '';

        $decl = fn (array $v) => collect($v)->map(fn ($val, $k) => "{$k}:{$val}")->implode(';');
        if ($s['light'] || $s['radius'] || $s['font_sans']) {
            $extra = [];
            if ($s['radius']) {
                $extra[] = '--radius:'.$s['radius'];
            }
            if ($s['font_sans']) {
                $extra[] = "--font-sans:'".$s['font_sans']."',ui-sans-serif,system-ui,sans-serif";
            }
            if ($s['font_title']) {
                $extra[] = "--font-serif:'".$s['font_title']."',Georgia,serif";
            }
            $css .= ':root{'.implode(';', array_filter([$decl($s['light']), ...$extra])).'}';
        }
        if ($s['dark']) {
            $css .= '.dark{'.$decl($s['dark']).'}';
        }
        if ($s['font_title']) {
            $css .= "h1{font-family:'".$s['font_title']."',Georgia,serif}";
        }
        if ($s['font_sans']) {
            $css .= "body{font-family:'".$s['font_sans']."',ui-sans-serif,system-ui,sans-serif}";
        }

        $families = array_filter([
            $s['font_sans'] ? self::SANS[$s['font_sans']].':400,500,600,700' : null,
            $s['font_title'] ? self::TITLES[$s['font_title']].':500,600,700' : null,
        ]);
        $link = $families ? '<link rel="stylesheet" href="https://fonts.bunny.net/css?family='.implode('|', $families).'&display=swap">' : '';

        return $link.($css !== '' ? '<style id="brand-theme">'.$css.'</style>' : '');
    }

    /** Color de acción personalizado (o null) para documentos fuera de la app. */
    public static function primary(): ?string
    {
        return self::settings()['light']['--primary'] ?? null;
    }

    /** Tinte suave del acento (o null) para documentos fuera de la app. */
    public static function accent(): ?string
    {
        return self::settings()['light']['--accent'] ?? null;
    }

    public static function heroUrl(): ?string
    {
        $path = Setting::get('brand.hero');

        return $path && Storage::disk('public')->exists($path) ? '/storage/'.$path.'?v='.Storage::disk('public')->lastModified($path) : null;
    }

    /** Ruta absoluta del hero (para el PDF). */
    public static function heroPath(): ?string
    {
        $path = Setting::get('brand.hero');

        return $path && Storage::disk('public')->exists($path) ? Storage::disk('public')->path($path) : null;
    }
}
