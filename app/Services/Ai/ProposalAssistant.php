<?php

namespace App\Services\Ai;

use App\Models\Lead;
use App\Models\Service;
use App\Services\Proposals\ProposalBuilder;
use App\Support\ProposalText;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Ayuda de IA para propuestas comerciales: redacta secciones, mejora textos, describe servicios y
 * sugiere servicios del catálogo. El documento final siempre usa el diseño de marca (no lo decide la IA)
 * y la IA nunca recibe nombres: trabaja con los marcadores [CLIENTE] y [CONTACTO].
 */
class ProposalAssistant
{
    private const MODES = [
        'write' => 'Redacta (o reescribe con mejor calidad) el texto de esta sección.',
        'shorter' => 'Hazlo más breve y directo, conservando las ideas clave.',
        'persuasive' => 'Hazlo más persuasivo: beneficios concretos para el cliente, sin exageraciones ni promesas irreales.',
        'formal' => 'Usa un tono más formal y corporativo.',
        'fix' => 'Corrige ortografía, gramática y puntuación sin cambiar el sentido ni el tono.',
        'custom' => 'Aplica las instrucciones del usuario.',
    ];

    public function __construct(private AiGateway $ai) {}

    /** @param array<string, mixed> $in @return array{title: string, sections: array<int, array{title: string, body: string}>} */
    public function draft(array $in): array
    {
        $result = $this->ai->structured(
            'proposal_draft',
            $this->system("Redactas el contenido de propuestas comerciales de Vivienda en Regla, un estudio de arquitectura (regularizaciones, planos y diseño de interiores), para personas y empresas que necesitan regularizar una propiedad, obtener planos o diseñar un espacio.\n\nEntrega SOLO estas 4 secciones, en este orden y con estos títulos: «Resumen del proyecto», «Objetivos», «Alcance de los servicios», «Etapas y plazos».\n- Resumen del proyecto: 2 párrafos cortos que muestren que entendemos la propiedad o el espacio y lo que el cliente necesita.\n- Objetivos: 3 a 5 viñetas (con «- ») medibles y realistas, sin cifras inventadas.\n- Alcance de los servicios: 1 párrafo que introduzca los servicios elegidos (una tabla con detalle y precios se agrega automáticamente debajo; no repitas precios).\n- Etapas y plazos: viñetas por etapa (levantamiento, anteproyecto, ingreso a la municipalidad, ejecución, entrega…), en términos relativos y marcados como referenciales; los plazos municipales dependen del organismo.\nNo escribas condiciones comerciales, formas de pago ni valores: se agregan aparte. No prometas aprobaciones municipales ni plazos exactos."),
            $this->context($in)."\nTono: ".($in['tone'] ?? 'Cercano y profesional')."\nInstrucciones adicionales: ".mb_substr((string) ($in['brief'] ?? '—'), 0, 1200),
            fn (JsonSchema $s) => [
                'title' => $s->string()->description('Título corto de la propuesta, sin nombre del cliente')->required(),
                'sections' => $s->array()->items($s->object([
                    'title' => $s->string()->required(),
                    'body' => $s->string()->description('Texto con **negrita** y viñetas «- ». Sin HTML')->required(),
                ]))->required(),
            ],
        );

        $allowed = ['Resumen del proyecto', 'Objetivos', 'Alcance de los servicios', 'Etapas y plazos'];
        $sections = collect($result['sections'] ?? [])->map(fn ($s) => ['title' => BrandedEmailDesign::clean($s['title'] ?? '', 120), 'body' => ProposalText::html($this->clean($s['body'] ?? '', 4000))])
            ->filter(fn ($s) => $s['title'] !== '' && $s['body'] !== '')->take(6)->values()->all();

        // Condiciones y aceptación son fijas (no las inventa la IA).
        $fixed = collect(ProposalBuilder::defaultSections())->filter(fn ($s) => in_array($s['title'], ['Condiciones comerciales', 'Aceptación'], true))->values()->all();

        return [
            'title' => BrandedEmailDesign::clean($result['title'] ?? ($in['title'] ?? 'Propuesta comercial'), 160) ?: ($in['title'] ?? 'Propuesta comercial'),
            'sections' => [...$sections, ...$fixed],
        ];
    }

    /** @param array<string, mixed> $in */
    public function improve(array $in): string
    {
        $mode = array_key_exists($in['mode'] ?? '', self::MODES) ? $in['mode'] : 'write';
        $instruction = $mode === 'custom' ? 'Instrucciones del usuario: '.mb_substr((string) ($in['instruction'] ?? ''), 0, 600) : self::MODES[$mode];

        $result = $this->ai->structured(
            'proposal_improve',
            $this->system('Mejoras textos de propuestas comerciales de Vivienda en Regla. Conserva los marcadores [CLIENTE] y [CONTACTO], los datos y cifras que ya estén escritos (no inventes nuevos) y el formato (**negrita**, viñetas «- »). Devuelves solo el texto resultante.'),
            "Sección: ".mb_substr((string) ($in['title'] ?? ''), 0, 120)."\nTipo de cliente / giro: ".($in['recipient']['activity'] ?? 'no informado')."\n{$instruction}\n\nTexto actual:\n".(mb_substr(ProposalText::toMarkdown($in['text'] ?? ''), 0, 5000) ?: '(vacío: redáctalo desde cero según el título de la sección)'),
            fn (JsonSchema $s) => ['text' => $s->string()->required()],
        );

        $text = $this->clean($result['text'] ?? '', 5000);
        if ($text === '') {
            throw new AiFailed('La IA no devolvió texto. Intenta de nuevo.');
        }

        return ProposalText::html($text);
    }

    /** @param array<string, mixed> $in @return array{description: string, deliverables: array<int, string>} */
    public function service(array $in): array
    {
        $result = $this->ai->structured(
            'service_describe',
            $this->system('Redactas la descripción de servicios de una software factory y agencia de growth marketing (web, apps, automatización, IA, performance, SEO y email marketing) para el catálogo y las propuestas. Descripción: 2 a 3 frases claras, orientadas al beneficio. Entregables: 3 a 6 ítems concretos y verificables, sin inventar cantidades que el usuario no haya indicado.'),
            'Servicio: '.mb_substr((string) $in['name'], 0, 160)."\nCategoría: ".($in['category'] ?? '—')."\nModalidad: ".(($in['billing'] ?? '') === 'monthly' ? 'mensual recurrente' : 'pago único')."\nNotas del usuario: ".mb_substr((string) ($in['hint'] ?? '—'), 0, 800),
            fn (JsonSchema $s) => [
                'description' => $s->string()->required(),
                'deliverables' => $s->array()->items($s->string())->required(),
            ],
        );

        return [
            'description' => ProposalText::html($this->clean($result['description'] ?? '', 1200)),
            'deliverables' => collect($result['deliverables'] ?? [])->map(fn ($d) => BrandedEmailDesign::clean($d, 160))->filter()->take(8)->values()->all(),
        ];
    }

    /** @param array<string, mixed> $in @return array<int, array{service_id: int, reason: string}> */
    public function suggest(array $in): array
    {
        $catalog = Service::where('is_active', true)->orderBy('category')->get(['id', 'name', 'category', 'description', 'billing', 'price']);
        $ids = $catalog->pluck('id')->all();

        $result = $this->ai->structured(
            'service_suggest',
            $this->system('Eres la asistente comercial de Vivienda en Regla, estudio de arquitectura. Eliges, del catálogo entregado, los servicios que mejor responden a lo que necesita el cliente. Elige entre 2 y 5, prioriza lo que resuelve el problema del cliente y justifica cada uno en una frase. Usa SOLO ids del catálogo.'),
            $this->context($in)."\nNecesidad: ".mb_substr((string) ($in['brief'] ?? '—'), 0, 1000)."\n\nCatálogo:\n".$catalog->map(fn ($s) => "#{$s->id} [{$s->category}] {$s->name} ({$s->billing}) — ".mb_substr((string) $s->description, 0, 140))->implode("\n"),
            fn (JsonSchema $s) => ['suggestions' => $s->array()->items($s->object([
                'service_id' => $s->integer()->required(),
                'reason' => $s->string()->required(),
            ]))->required()],
        );

        return collect($result['suggestions'] ?? [])->filter(fn ($x) => in_array((int) ($x['service_id'] ?? 0), $ids, true))
            ->unique('service_id')->take(5)
            ->map(fn ($x) => ['service_id' => (int) $x['service_id'], 'reason' => BrandedEmailDesign::clean($x['reason'] ?? '', 220)])->values()->all();
    }

    // ------------------------------------------------------------------------------------------

    private function system(string $task): string
    {
        return $task."\n\nContexto de la agencia:\n".BrandContext::text()."\n\nReglas generales: español de Chile, trato profesional y cercano; no inventes clientes, cifras, plazos contractuales ni garantías de resultados; refiérete al cliente solo con el marcador [CLIENTE] y a la persona de contacto con [CONTACTO]; sin HTML.";
    }

    /** @param array<string, mixed> $in */
    private function context(array $in): string
    {
        $r = $in['recipient'] ?? [];
        $lines = ['Tipo de cliente / giro: '.($r['activity'] ?? 'no informado')];

        if (! empty($in['lead_id']) && ($lead = Lead::with(['source', 'stage'])->find($in['lead_id']))) {
            $lines[] = 'Cargo del contacto: '.($lead->job_title ?: 'no informado');
            $lines[] = 'Mensaje original del prospecto: '.($lead->message ? mb_substr($lead->message, 0, 800) : 'sin mensaje');
            $lines[] = 'Origen del prospecto: '.($lead->source?->name ?? '—').($lead->utm_campaign ? " · campaña {$lead->utm_campaign}" : '');
            $lines[] = 'Ubicación: '.collect([$lead->city, $lead->country])->filter()->implode(', ');
        }

        $items = collect($in['items'] ?? []);
        $lines[] = $items->isEmpty() ? 'Servicios elegidos: aún ninguno' : "Servicios elegidos:\n".$items->map(fn ($i) => '- '.mb_substr((string) ($i['name'] ?? ''), 0, 120).' ('.(($i['billing'] ?? '') === 'monthly' ? 'mensual' : 'pago único').')')->implode("\n");

        return implode("\n", array_filter($lines));
    }

    /** Texto plano que conserva **negrita**, viñetas y marcadores. */
    private function clean(mixed $value, int $max): string
    {
        $s = strip_tags((string) $value);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
        $s = preg_replace('/\[([^\]]*)\]\((?!https:\/\/)[^)]*\)/i', '$1', $s) ?? $s;

        return mb_substr(trim(str_replace("\r", '', $s)), 0, $max);
    }
}
