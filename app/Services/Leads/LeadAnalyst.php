<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\PipelineStage;
use App\Models\Setting;
use App\Services\Ai\AiGateway;
use App\Services\Ai\BrandContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Análisis opcional con IA de un lead: resumen, señales, próximos pasos, mensaje sugerido y datos
 * que conviene completar. Se apoya en el puntaje determinista (LeadScorer) y solo puede ajustarlo ±10.
 * Por privacidad, nombre/correo/teléfono/empresa/notas solo se envían si «ai.share_contact» está activo.
 */
class LeadAnalyst
{
    /** Campos que la IA puede sugerir completar (y que una persona aplica con un clic). */
    public const APPLICABLE = ['priority', 'tags', 'company', 'job_title'];

    public function __construct(private AiGateway $ai, private LeadScorer $scorer) {}

    /** Datos que se envían al modelo (ya filtrados por privacidad). @return array<string, mixed> */
    public function context(Lead $lead): array
    {
        $lead->loadMissing(['source', 'stage']);
        $share = (bool) Setting::get('ai.share_contact', false);
        $scored = $lead->score !== null ? $lead : $this->scorer->score($lead);

        $activities = LeadActivity::where('lead_id', $lead->id)->whereNotIn('type', LeadActivity::AUTOMATIC)
            ->latest('occurred_at')->limit(8)->get(['type', 'description', 'occurred_at']);

        $ctx = [
            'cargo' => $lead->job_title,
            'mensaje_del_lead' => $lead->message ? mb_substr($lead->message, 0, 1200) : null,
            'origen' => $lead->source?->name,
            'campaña' => collect([$lead->utm_source, $lead->utm_medium, $lead->utm_campaign, $lead->utm_term])->filter()->implode(' / ') ?: null,
            'etapa_actual' => $lead->stage?->name,
            'etapas_del_embudo' => PipelineStage::orderBy('sort_order')->pluck('name')->all(),
            'prioridad' => $lead->priority,
            'valor_estimado_clp' => $lead->estimated_value ?: null,
            'etiquetas' => $lead->tags ?: null,
            'ciudad' => $lead->city,
            'pais' => $lead->country,
            'dias_desde_ingreso' => $lead->created_at ? (int) $lead->created_at->diffInDays(now()) : null,
            'proximo_seguimiento' => $lead->next_follow_up_at?->toDateString(),
            'tipo_de_correo' => collect($scored->profile['signals'] ?? [])->firstWhere('key', 'email_type')['value'] ?? null,
            'campos_personalizados' => $this->custom($lead),
            'seguimientos' => $activities->map(fn ($a) => ['tipo' => $a->type, 'fecha' => $a->occurred_at?->toDateString(), 'detalle' => $share ? $a->description : null])->all(),
            'puntaje_automatico' => $scored->score_breakdown['base'] ?? $scored->score,
            'completitud_perfil_pct' => $scored->profile_completeness,
            'datos_faltantes' => collect($scored->profile['missing'] ?? [])->pluck('label')->take(8)->all(),
        ];

        if ($share) {
            $ctx['nombre'] = $lead->full_name;
            $ctx['empresa'] = $lead->company;
            $ctx['correo'] = $lead->email;
            $ctx['telefono'] = $lead->phone;
            $ctx['notas'] = $lead->notes()->where('is_private', false)->limit(5)->pluck('body')->map(fn ($b) => mb_substr(\App\Support\ProposalText::plain($b), 0, 400))->all();
        } else {
            $ctx['tiene_empresa'] = filled($lead->company);
            $ctx['tiene_telefono'] = filled($lead->phone);
        }

        return array_filter($ctx, fn ($v) => $v !== null && $v !== [] && $v !== '');
    }

    /** Huella de los datos relevantes: cambia solo si el lead cambió de verdad (no por el paso de los días). */
    public function hash(Lead $lead, ?array $ctx = null): string
    {
        return sha1(json_encode(array_diff_key($ctx ?? $this->context($lead), array_flip(['dias_desde_ingreso', 'puntaje_automatico']))));
    }

    /** Analiza y guarda el resultado en el lead. @return array<string, mixed> */
    public function analyze(Lead $lead): array
    {
        $ctx = $this->context($lead);
        $hash = $this->hash($lead, $ctx);
        $provider = $this->ai->resolve();

        $result = $this->ai->structured(
            'lead_analysis',
            $this->instructions(),
            "Analiza este prospecto (persona o empresa) que nos contactó por una regularización, planos o un proyecto de diseño de interiores:\n".json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            fn (JsonSchema $s) => [
                'summary' => $s->string()->description('Resumen de 1–2 frases: quién es y qué busca')->required(),
                'temperature' => $s->string()->enum(['hot', 'warm', 'cold'])->description('Qué tan cerca está de comprar')->required(),
                'score_adjustment' => $s->integer()->description('Entre -10 y 10: ajuste al puntaje determinista, solo con evidencia clara')->required(),
                'adjustment_reason' => $s->string()->description('Por qué ese ajuste (vacío si 0)')->required(),
                'buying_signals' => $s->array()->items($s->string())->description('Señales positivas concretas (máx. 4)')->required(),
                'risks' => $s->array()->items($s->string())->description('Riesgos u objeciones probables (máx. 3)')->required(),
                'next_actions' => $s->array()->items($s->object([
                    'action' => $s->string()->description('Acción concreta y breve')->required(),
                    'channel' => $s->string()->enum(['call', 'email', 'whatsapp', 'meeting', 'task'])->required(),
                    'when' => $s->string()->enum(['today', 'this_week', 'later'])->required(),
                    'why' => $s->string()->description('Motivo en una frase')->required(),
                ]))->description('1 a 4 próximos pasos priorizados')->required(),
                'suggested_message' => $s->string()->description('Borrador de primer mensaje (correo o WhatsApp) en español de Chile, tono Vivienda en Regla, máx. 90 palabras. Sin datos inventados')->required(),
                'questions_to_ask' => $s->array()->items($s->string())->description('2–4 preguntas de calificación para completar el perfil')->required(),
                'profile_suggestions' => $s->array()->items($s->object([
                    'field' => $s->string()->enum(self::APPLICABLE)->required(),
                    'value' => $s->string()->description('priority: low|normal|high|urgent · tags: etiquetas separadas por coma · company/job_title: texto')->required(),
                    'confidence' => $s->string()->enum(['high', 'medium', 'low'])->required(),
                    'reason' => $s->string()->required(),
                ]))->description('Datos que se pueden completar o mejorar con la información disponible. Solo si hay evidencia; si no, lista vacía')->required(),
            ],
            ['lead_id' => $lead->id],
        );

        $analysis = $this->normalize($result) + [
            'provider' => $provider->slug,
            'model' => $provider->model,
            'generated_at' => now()->toIso8601String(),
            'shared_contact' => (bool) Setting::get('ai.share_contact', false),
        ];

        $lead->forceFill([
            'ai_analysis' => $analysis,
            'ai_analyzed_at' => now(),
            'ai_input_hash' => $hash,
            'ai_adjustment' => $analysis['score_adjustment'],
        ])->saveQuietly();

        $this->scorer->score($lead); // incorpora el ajuste y deja el desglose coherente

        return $analysis;
    }

    /** @param  array<string, mixed>  $r  @return array<string, mixed> */
    private function normalize(array $r): array
    {
        $str = fn ($v, int $max) => mb_substr(trim(strip_tags((string) $v)), 0, $max);
        $list = fn ($v, int $n, int $max) => collect(is_array($v) ? $v : [])->map(fn ($x) => $str($x, $max))->filter()->take($n)->values()->all();

        $suggestions = collect($r['profile_suggestions'] ?? [])->filter(fn ($x) => is_array($x) && in_array($x['field'] ?? '', self::APPLICABLE, true) && filled($x['value'] ?? ''))
            ->map(fn ($x) => [
                'field' => $x['field'],
                'value' => $str($x['value'], 120),
                'confidence' => in_array($x['confidence'] ?? '', ['high', 'medium', 'low'], true) ? $x['confidence'] : 'low',
                'reason' => $str($x['reason'] ?? '', 200),
            ])
            ->filter(fn ($x) => $x['field'] !== 'priority' || in_array($x['value'], array_keys(Lead::PRIORITIES), true))
            ->take(4)->values()->all();

        return [
            'summary' => $str($r['summary'] ?? '', 400),
            'temperature' => in_array($r['temperature'] ?? '', ['hot', 'warm', 'cold'], true) ? $r['temperature'] : 'warm',
            'score_adjustment' => max(-10, min(10, (int) ($r['score_adjustment'] ?? 0))),
            'adjustment_reason' => $str($r['adjustment_reason'] ?? '', 240),
            'buying_signals' => $list($r['buying_signals'] ?? [], 4, 200),
            'risks' => $list($r['risks'] ?? [], 3, 200),
            'next_actions' => collect($r['next_actions'] ?? [])->filter(fn ($x) => is_array($x) && filled($x['action'] ?? ''))->map(fn ($x) => [
                'action' => $str($x['action'], 200),
                'channel' => in_array($x['channel'] ?? '', ['call', 'email', 'whatsapp', 'meeting', 'task'], true) ? $x['channel'] : 'task',
                'when' => in_array($x['when'] ?? '', ['today', 'this_week', 'later'], true) ? $x['when'] : 'this_week',
                'why' => $str($x['why'] ?? '', 200),
            ])->take(4)->values()->all(),
            'suggested_message' => $str($r['suggested_message'] ?? '', 900),
            'questions_to_ask' => $list($r['questions_to_ask'] ?? [], 4, 200),
            'profile_suggestions' => $suggestions,
        ];
    }

    /** @return array<string, mixed>|null */
    private function custom(Lead $lead): ?array
    {
        $fields = \App\Models\LeadField::where('is_active', true)->pluck('label', 'key');
        $out = [];
        foreach ($lead->custom ?? [] as $key => $value) {
            if ($value !== null && $value !== '' && isset($fields[$key])) {
                $out[$fields[$key]] = is_scalar($value) ? $value : json_encode($value);
            }
        }

        return $out ?: null;
    }

    private function instructions(): string
    {
        return <<<TXT
Eres un analista comercial senior de Vivienda en Regla, estudio de arquitectura en Chile (regularizaciones, planos y diseño de interiores). Tu trabajo es ayudar a prospectar mejor cada prospecto: entender su potencial, decidir el siguiente paso y completar su perfil.

{$this->brand()}

Reglas:
- Basa TODO en los datos entregados. No inventes empresa, superficie, presupuesto, cargo, cifras ni hechos. Si algo no se sabe, dilo o sugiere cómo averiguarlo (preguntas de calificación).
- «score_adjustment» solo si hay evidencia clara que el puntaje automático no captura (p. ej. urgencia explícita, presupuesto declarado: positivo; mensaje genérico o spam: negativo). Si dudas, 0.
- Próximos pasos: concretos, ordenados por prioridad, con canal y plazo. Considera la etapa actual, los seguimientos previos y el tiempo desde el ingreso. Un prospecto nuevo sin contacto requiere primer contacto rápido.
- El mensaje sugerido debe sonar humano y cercano (tuteo, español de Chile), mencionar su necesidad si existe y proponer UN paso claro (llamada o reunión). Sin exclamaciones excesivas ni promesas.
- Sugerencias de perfil: solo con evidencia (p. ej. etiquetas por intereses, prioridad según urgencia). No repitas valores que ya están definidos.
TXT;
    }

    private function brand(): string
    {
        return "Contexto de la agencia:\n".BrandContext::text();
    }
}
