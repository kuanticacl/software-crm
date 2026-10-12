<?php

namespace App\Services\Ai;

use App\Services\Email\MailSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Asistente opcional para crear mailings. La IA redacta (asunto, preheader y cuerpo por bloques);
 * el diseño final lo arma BrandedEmailDesign, por lo que siempre respeta la identidad de Vivienda en Regla.
 */
class EmailAssistant
{
    public function __construct(private AiGateway $ai) {}

    /**
     * @param  array{brief: string, kind?: string, tone?: string, length?: string, cta_label?: string, cta_url?: string, images?: array<int, string>}  $in
     * @return array{subject: string, preheader: string, design: array<string, mixed>, notes: string}
     */
    public function design(array $in): array
    {
        $kind = ($in['kind'] ?? 'marketing') === 'transactional' ? 'transactional' : 'marketing';
        $images = array_values(array_filter($in['images'] ?? []));
        $links = array_values(array_filter([$in['cta_url'] ?? null]));

        $result = $this->ai->structured(
            'email_design',
            $this->instructions($kind),
            $this->prompt($in, $kind, $images),
            fn (JsonSchema $s) => [
                'subject' => $s->string()->description('Asunto, máx. 60 caracteres')->required(),
                'preheader' => $s->string()->description('Texto de vista previa, máx. 110 caracteres')->required(),
                'notes' => $s->string()->description('Una frase con el criterio usado (para el equipo)')->required(),
                'blocks' => $s->array()->items($s->object([
                    'type' => $s->string()->enum(BrandedEmailDesign::ALLOWED)->required(),
                    'title' => $s->string()->description('Título (heading/columns). Vacío si no aplica')->required(),
                    'text' => $s->string()->description('Texto del párrafo. Admite **negrita** y variables {{ first_name }}. Vacío si no aplica')->required(),
                    'label' => $s->string()->description('Texto del botón. Vacío si no aplica')->required(),
                    'url' => $s->string()->description('Enlace del botón. Vacío si no aplica')->required(),
                    'image' => $s->string()->description('URL de una de las imágenes entregadas. Vacío si no aplica')->required(),
                ]))->required(),
            ],
        );

        $design = BrandedEmailDesign::build($result['blocks'] ?? [], [
            'transactional' => $kind === 'transactional',
            'images' => $images,
            'links' => $links,
            'address' => (string) MailSettings::footerAddress(),
        ]);

        return [
            'subject' => BrandedEmailDesign::clean($result['subject'] ?? '', 90),
            'preheader' => BrandedEmailDesign::clean($result['preheader'] ?? '', 140),
            'design' => $design,
            'notes' => BrandedEmailDesign::clean($result['notes'] ?? '', 240),
        ];
    }

    /** @return array<int, string> */
    public function subjects(string $subject, string $context, int $count = 6): array
    {
        $result = $this->ai->structured(
            'email_subjects',
            "Eres redactora de comunicaciones de Vivienda en Regla, estudio de arquitectura. Propones asuntos para correos.\n\n".BrandContext::text()."\n\nReglas: máx. 60 caracteres, sin MAYÚSCULAS sostenidas ni más de un signo de exclamación, sin promesas exageradas ni palabras spam («gratis», «urgente»). Variedad real: beneficio, curiosidad, dato concreto, pregunta, personalizado con {{ first_name }}. Español de Chile.",
            "Asunto actual: {$subject}\nContenido del correo (resumen):\n".mb_substr($context, 0, 2500)."\n\nPropón {$count} asuntos distintos.",
            fn (JsonSchema $s) => ['subjects' => $s->array()->items($s->string())->required()],
        );

        return collect($result['subjects'] ?? [])
            ->map(fn ($x) => BrandedEmailDesign::clean($x, 90))
            ->filter()->unique()->take($count)->values()->all();
    }

    private function instructions(string $kind): string
    {
        $unsub = $kind === 'transactional' ? 'Es un correo transaccional (respuesta a una acción de la persona).' : 'Es un boletín/promoción: el pie incluirá el enlace de baja automáticamente.';

        return <<<TXT
Eres la redactora senior de comunicaciones por correo de Vivienda en Regla, estudio de arquitectura. Creas correos HTML por bloques; el diseño visual (logo oficial, colores, tipografía, botones y pie) lo aplica el sistema: tú NO decides colores, fuentes, logos ni HTML.

{$this->brand()}

{$unsub}

Reglas de contenido:
- Estructura típica: heading (gancho claro) → text (contexto breve) → text/columns (beneficios concretos) → button (UN llamado a la acción principal) → text de cierre cortés.
- Máximo 8 bloques. No incluyas encabezado ni pie: se agregan solos. No uses HTML, ni emojis en exceso (máx. 1 en todo el correo).
- Párrafos de 1–3 frases. Puedes usar **negrita** para 1–2 ideas clave por párrafo.
- Personaliza con variables solo cuando sea natural: {{ first_name | default:"" }}, {{ company }}. Nunca inventes otras variables salvo que el usuario las pida.
- No inventes cifras, clientes, ofertas, fechas ni enlaces. Si faltan datos, redacta sin ellos. Usa SOLO las URLs que te entregue el usuario (o https://www.viviendaenregla.cl).
- Tipo `image` o `columns` con imagen: solo con una URL de las imágenes entregadas; si no hay, no uses esos bloques.
- Tipo `list` solo si el usuario pide mostrar proyectos/propiedades dinámicos por API.
- Español de Chile, trato de «tú».
TXT;
    }

    private function brand(): string
    {
        return "Contexto de marca:\n".BrandContext::text();
    }

    /** @param  array<string, mixed>  $in */
    private function prompt(array $in, string $kind, array $images): string
    {
        $lines = [
            'Objetivo del correo: '.mb_substr((string) $in['brief'], 0, 1500),
            'Tipo: '.($kind === 'transactional' ? 'transaccional' : 'marketing / boletín'),
        ];
        if (! empty($in['tone'])) {
            $lines[] = 'Tono: '.mb_substr((string) $in['tone'], 0, 60);
        }
        $lines[] = 'Extensión: '.match ($in['length'] ?? 'medium') {
            'short' => 'corto (3–4 bloques)',
            'long' => 'más completo (hasta 8 bloques)',
            default => 'medio (5–6 bloques)',
        };
        if (! empty($in['cta_label'])) {
            $lines[] = 'Texto del botón principal: '.mb_substr((string) $in['cta_label'], 0, 40);
        }
        if (! empty($in['cta_url'])) {
            $lines[] = 'URL del botón principal: '.$in['cta_url'];
        }
        if ($images) {
            $lines[] = "Imágenes disponibles (usa solo estas URLs):\n- ".implode("\n- ", array_slice($images, 0, 6));
        }

        return implode("\n", $lines);
    }
}
