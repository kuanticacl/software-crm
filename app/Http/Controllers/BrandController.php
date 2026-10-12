<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\Theme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Identidad visual: colores, radio, tipografías e imagen hero (fondo del inicio de sesión y portada de propuestas). */
class BrandController extends Controller
{
    public function index(): Response
    {
        $s = Theme::settings();

        return Inertia::render('crm/Brand', [
            'saved' => ['picks' => $s['picks'], 'radius' => $s['radius'], 'font_sans' => $s['font_sans'], 'font_title' => $s['font_title']],
            'customized' => (bool) ($s['light'] || $s['dark'] || $s['radius'] || $s['font_sans'] || $s['font_title']),
            'hero' => Theme::heroUrl(),
            'options' => ['radii' => Theme::RADII, 'sans' => array_keys(Theme::SANS), 'titles' => array_keys(Theme::TITLES)],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $hex = ['required', 'regex:/^#[0-9a-fA-F]{6}$/'];
        $data = $request->validate([
            'picks' => ['required', 'array'],
            'picks.primary' => $hex, 'picks.background' => $hex, 'picks.sidebar' => $hex, 'picks.text' => $hex,
            'light' => ['required', 'array', 'max:40'], 'dark' => ['required', 'array', 'max:40'],
            'radius' => ['nullable', Rule::in(Theme::RADII)],
            'font_sans' => ['nullable', Rule::in(array_keys(Theme::SANS))],
            'font_title' => ['nullable', Rule::in(array_keys(Theme::TITLES))],
        ]);

        Setting::put('brand.picks', json_encode(array_map('strtolower', $data['picks'])));
        Setting::put('brand.light', json_encode(Theme::cleanVars($data['light'])));
        Setting::put('brand.dark', json_encode(Theme::cleanVars($data['dark'])));
        Setting::put('brand.radius', $data['radius'] ?? null);
        Setting::put('brand.font_sans', $data['font_sans'] ?? null);
        Setting::put('brand.font_title', $data['font_title'] ?? null);

        $this->toast('Identidad visual guardada.');

        return back();
    }

    /** Vuelve a los colores y tipografías originales (no toca la imagen hero). */
    public function reset(): RedirectResponse
    {
        foreach (['picks', 'light', 'dark', 'radius', 'font_sans', 'font_title'] as $k) {
            Setting::put("brand.{$k}", null);
        }
        $this->toast('Se restauró el diseño original.');

        return back();
    }

    public function hero(Request $request): RedirectResponse
    {
        $request->validate(['image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:6144', 'dimensions:min_width=800,min_height=400']], [
            'image.dimensions' => 'La imagen es muy pequeña: usa al menos 800 × 400 px (ideal 1920 px de ancho).',
            'image.max' => 'La imagen pesa más de 6 MB.',
            'image.mimes' => 'Sube una imagen JPG, PNG o WebP.',
        ]);

        $file = $request->file('image');
        $img = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        abort_if($img === false, 422, 'No se pudo leer la imagen.');

        // Se re-codifica como JPG (máx. 2400 px de ancho): quita metadatos y contenido ajeno a la imagen.
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w > 2400) {
            $img = imagescale($img, 2400, (int) round($h * 2400 / $w)) ?: $img;
        }
        $flat = imagecreatetruecolor(imagesx($img), imagesy($img));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));

        $path = 'brand/hero-'.bin2hex(random_bytes(5)).'.jpg';
        ob_start();
        imagejpeg($flat, null, 84);
        Storage::disk('public')->put($path, (string) ob_get_clean());

        $old = Setting::get('brand.hero');
        Setting::put('brand.hero', $path);
        if ($old && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        $this->toast('Imagen hero actualizada.');

        return back();
    }

    public function removeHero(): RedirectResponse
    {
        if ($old = Setting::get('brand.hero')) {
            Storage::disk('public')->delete($old);
        }
        Setting::put('brand.hero', null);
        $this->toast('Imagen hero eliminada: se usa la imagen original.');

        return back();
    }
}
