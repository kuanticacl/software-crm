<?php

namespace App\Services\Ai;

/**
 * Convierte lo que propone la IA en un diseño de bloques que SIEMPRE respeta la marca Vivienda en Regla.
 * La IA solo aporta textos y la elección/orden de bloques; el diseño (colores, tipografía, logo,
 * botones en píldora, pie con baja) se impone aquí y no puede ser alterado por el modelo.
 */
class BrandedEmailDesign
{
    public const ORANGE = '#A9606B';

    public const TEXT = '#3B2F2F';

    /** Bloques que la IA puede elegir. El encabezado y el pie los pone el sistema. */
    public const ALLOWED = ['heading', 'text', 'button', 'image', 'divider', 'spacer', 'columns', 'list'];

    public static function logoUrl(): string
    {
        return url('/brand/vivienda-logo-dark.png');
    }

    /** @return array<string, mixed> */
    public static function settings(): array
    {
        return [
            'bg' => '#F6EFEB',
            'contentBg' => '#FFFFFF',
            'width' => 600,
            'font' => 'Arial, Helvetica, sans-serif',
            'textColor' => self::TEXT,
            'linkColor' => self::ORANGE,
            'radius' => 16,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks  bloques crudos de la IA
     * @param  array{transactional?: bool, images?: array<int, string>, links?: array<int, string>, company?: string, address?: string}  $opts
     * @return array{settings: array<string, mixed>, blocks: array<int, array<string, mixed>>}
     */
    public static function build(array $blocks, array $opts = []): array
    {
        $images = array_values(array_filter($opts['images'] ?? [], fn ($u) => self::safeUrl((string) $u)));
        $links = array_values(array_unique([...$images, ...array_filter($opts['links'] ?? [], fn ($u) => self::safeUrl((string) $u))]));
        $out = [self::header()];

        foreach (array_slice($blocks, 0, 14) as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $block = self::block($raw, $images, $links);
            if ($block) {
                $out[] = $block;
            }
        }

        if (count($out) === 1) {
            $out[] = self::make('text', ['text' => 'Escribe aquí tu mensaje.']);
        }

        $out[] = self::footer($opts);

        return ['settings' => self::settings(), 'blocks' => $out];
    }

    /** @return array<string, mixed>|null */
    private static function block(array $raw, array $images, array $links): ?array
    {
        $type = (string) ($raw['type'] ?? '');
        if (! in_array($type, self::ALLOWED, true)) {
            return null;
        }

        $text = self::clean($raw['text'] ?? '', 1200);
        $title = self::clean($raw['title'] ?? '', 140);
        $label = self::clean($raw['label'] ?? '', 40);
        $url = self::safeUrl((string) ($raw['url'] ?? ''), $links);
        $img = self::imageUrl((string) ($raw['image'] ?? $raw['url'] ?? ''), $images);

        return match ($type) {
            'heading' => $text !== '' || $title !== '' ? self::make('heading', ['text' => $title ?: $text, 'size' => 28]) : null,
            'text' => $text !== '' ? self::make('text', ['text' => $text]) : null,
            'button' => $label !== '' ? self::make('button', ['label' => $label, 'href' => $url ?: 'https://www.viviendaenregla.cl']) : null,
            'image' => $img ? self::make('image', ['src' => $img, 'alt' => self::clean($raw['alt'] ?? $title, 120), 'href' => '']) : null,
            'columns' => $title !== '' || $text !== ''
                ? self::make('columns', ['imageUrl' => $img ?: '', 'title' => $title, 'text' => $text, 'buttonLabel' => $label, 'buttonUrl' => $url ?: 'https://www.viviendaenregla.cl'])
                : null,
            'list' => self::make('list', ['collection' => preg_replace('/[^a-z0-9_]/i', '', (string) ($raw['collection'] ?? 'proyectos')) ?: 'proyectos']),
            'divider' => self::make('divider'),
            'spacer' => self::make('spacer'),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private static function header(): array
    {
        return self::make('header', ['logoUrl' => self::logoUrl(), 'logoWidth' => 150, 'brandText' => 'Vivienda en Regla', 'align' => 'center', 'padY' => 28]);
    }

    /** @return array<string, mixed> */
    private static function footer(array $opts): array
    {
        return self::make('footer', [
            'company' => '{{ company_name }}',
            'address' => (string) ($opts['address'] ?? ''),
            'legal' => ! empty($opts['transactional']) ? 'Recibes este correo porque completaste un formulario en nuestro sitio.' : 'Recibes este correo porque estás en nuestra lista de contactos.',
            'showUnsubscribe' => empty($opts['transactional']),
            'showView' => false,
        ]);
    }

    /** @return array<string, mixed> */
    public static function make(string $type, array $props = []): array
    {
        return ['id' => bin2hex(random_bytes(4)), 'type' => $type, 'props' => array_merge(self::defaults($type), $props)];
    }

    /** Valores por defecto de cada bloque (espejo de BLOCKS en emailBuilder.ts, con la marca aplicada). */
    private static function defaults(string $type): array
    {
        return match ($type) {
            'header' => ['logoUrl' => '', 'logoWidth' => 140, 'brandText' => 'Vivienda en Regla', 'brandColor' => self::ORANGE, 'align' => 'center', 'padY' => 24, 'padX' => 32, 'bg' => ''],
            'heading' => ['text' => '', 'size' => 28, 'color' => '', 'weight' => '700', 'align' => 'left', 'padY' => 12, 'padX' => 32, 'bg' => ''],
            'text' => ['text' => '', 'size' => 16, 'color' => '', 'lineHeight' => 1.6, 'align' => 'left', 'padY' => 8, 'padX' => 32, 'bg' => ''],
            'image' => ['src' => '', 'alt' => '', 'href' => '', 'width' => 100, 'radius' => 8, 'align' => 'center', 'padY' => 12, 'padX' => 32, 'bg' => ''],
            'button' => ['label' => '', 'href' => 'https://www.viviendaenregla.cl', 'bg' => self::ORANGE, 'color' => '#FFFFFF', 'radius' => 999, 'size' => 16, 'align' => 'center', 'fullWidth' => false, 'padY' => 16, 'padX' => 32, 'rowBg' => ''],
            'columns' => ['imageUrl' => '', 'imageSide' => 'left', 'title' => '', 'text' => '', 'buttonLabel' => '', 'buttonUrl' => 'https://www.viviendaenregla.cl', 'buttonBg' => self::ORANGE, 'padY' => 16, 'padX' => 32, 'bg' => ''],
            'list' => ['collection' => 'proyectos', 'imageKey' => 'imagen', 'titleKey' => 'nombre', 'textKey' => 'descripcion', 'priceKey' => 'precio', 'urlKey' => 'url', 'buttonLabel' => 'Ver detalle', 'buttonBg' => self::ORANGE, 'emptyText' => '', 'padY' => 12, 'padX' => 32, 'bg' => ''],
            'divider' => ['color' => '#E6D8D1', 'thickness' => 1, 'padY' => 12, 'padX' => 32, 'bg' => ''],
            'spacer' => ['height' => 16, 'bg' => ''],
            'footer' => ['company' => '{{ company_name }}', 'address' => '', 'legal' => '', 'showUnsubscribe' => true, 'unsubscribeText' => 'Darme de baja', 'showView' => false, 'color' => '#9A8C88', 'align' => 'center', 'padY' => 24, 'padX' => 32, 'bg' => '#FBF8F6'],
            default => [],
        };
    }

    /** Texto plano: sin HTML ni caracteres de control; conserva **negrita**, [enlaces](url) y {{ variables }}. */
    public static function clean(mixed $value, int $max): string
    {
        $s = strip_tags((string) $value);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
        $s = preg_replace('/\[([^\]]*)\]\((?!https?:\/\/|\{\{)(?:[^()\s]|\([^()]*\))*\)/i', '$1', $s) ?? $s; // enlaces markdown no seguros

        return mb_substr(trim($s), 0, $max);
    }

    /**
     * Solo https de viviendaenregla.cl, URLs entregadas por el usuario (`$allowed`) o variables {{ }}.
     * Los enlaces que la IA invente en otros dominios se descartan.
     */
    private static function safeUrl(string $url, ?array $allowed = null): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('/^\{\{\s*[a-z0-9_.]+\s*(\|[^}]*)?\}\}$/i', $url)) {
            return $url;
        }

        if (! preg_match('/^https:\/\/[^\s"\'<>]+$/i', $url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }
        if ($allowed === null || in_array($url, $allowed, true)) {
            return $url;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === 'viviendaenregla.cl' || str_ends_with($host, '.viviendaenregla.cl') ? $url : '';
    }

    private static function imageUrl(string $url, array $images): string
    {
        $url = trim($url);

        return $url !== '' && in_array($url, $images, true) ? $url : '';
    }
}
