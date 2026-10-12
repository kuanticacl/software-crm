<?php

namespace App\Services\RealEstate;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Guarda fotos y plantas en el disco público re-codificándolas (quita metadatos y cualquier contenido ajeno a la imagen).
 * Las plantas conservan transparencia como PNG; las fotos se guardan como JPG.
 */
class ImageStore
{
    public const RULES = ['file', 'mimes:jpg,jpeg,png,webp', 'max:8192'];

    public static function store(UploadedFile $file, string $dir, int $maxWidth = 2000, bool $keepPng = false): string
    {
        $img = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($img === false) {
            throw ValidationException::withMessages(['image' => 'No se pudo leer la imagen. Usa JPG, PNG o WebP.']);
        }

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w > $maxWidth) {
            $img = imagescale($img, $maxWidth, (int) round($h * $maxWidth / $w)) ?: $img;
        }

        $png = $keepPng && strtolower($file->getClientOriginalExtension()) === 'png';
        $path = trim($dir, '/').'/'.bin2hex(random_bytes(8)).($png ? '.png' : '.jpg');

        ob_start();
        if ($png) {
            imagesavealpha($img, true);
            imagepng($img, null, 7);
        } else {
            $flat = imagecreatetruecolor(imagesx($img), imagesy($img));
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
            imagejpeg($flat, null, 86);
        }
        Storage::disk('public')->put($path, (string) ob_get_clean());

        return $path;
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
