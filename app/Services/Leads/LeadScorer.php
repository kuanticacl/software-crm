<?php

namespace App\Services\Leads;

use App\Models\EmailMessage;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadField;
use App\Models\PipelineStage;
use Illuminate\Support\Carbon;

/**
 * Perfilamiento interno y puntaje (0–100) de un lead. 100 % determinista y explicable: cada punto
 * tiene un motivo visible. Se recalcula cada vez que entra información nueva (datos, seguimientos,
 * notas, etapa, apertura/clic de correos) y de noche para reflejar el paso del tiempo.
 *
 * Grupos: Perfil y contacto (30) · Intención y origen (20) · Avance comercial (30) · Dinamismo (20).
 * La IA (opcional) puede aportar un ajuste de ±10 con su motivo, que se suma aparte y queda auditado.
 */
class LeadScorer
{
    public const GRADES = [
        'A' => ['label' => 'Caliente', 'min' => 70],
        'B' => ['label' => 'Tibio', 'min' => 50],
        'C' => ['label' => 'Frío', 'min' => 30],
        'D' => ['label' => 'Bajo', 'min' => 0],
    ];

    private const FREE_DOMAINS = ['gmail.com', 'hotmail.com', 'hotmail.es', 'outlook.com', 'outlook.es', 'yahoo.com', 'yahoo.es', 'live.com', 'icloud.com', 'msn.com', 'protonmail.com', 'gmx.com', 'aol.com', 'yahoo.cl', 'vtr.net'];

    private const DECISION = ['gerente', 'director', 'dueño', 'dueno', 'propietario', 'socio', 'fundador', 'ceo', 'presidente', 'gerencia', 'jefe', 'subgerente', 'head', 'cmo', 'coo', 'country manager', 'representante legal'];

    private const AREA = ['marketing', 'comercial', 'ventas', 'sales', 'growth', 'digital', 'proyectos', 'desarrollo', 'inmobiliario', 'negocios'];

    private const INTENT = ['cotiz', 'precio', 'valor', 'demo', 'agendar', 'reunión', 'reunion', 'llamar', 'contactar', 'propuesta', 'presupuesto', 'contratar', 'servicio', 'urgente', 'lo antes', 'interesad', 'necesit', 'quiero', 'quisiera', 'proyecto', 'campaña', 'campana', 'leads', 'ventas'];

    /** Recalcula sin romper nunca el flujo que lo invoca (alta, nota, correo abierto…). */
    public static function refresh(Lead|int|null $lead): void
    {
        try {
            $lead = $lead instanceof Lead ? $lead : ($lead ? Lead::find($lead) : null);
            if ($lead) {
                app(self::class)->score($lead);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Calcula, guarda y devuelve el lead con el puntaje al día. */
    public function score(Lead $lead, bool $save = true): Lead
    {
        $lead->loadMissing(['source', 'stage']);
        $result = $this->compute($lead);

        $lead->forceFill([
            'score' => $result['score'],
            'score_grade' => $result['grade'],
            'profile_completeness' => $result['completeness'],
            'score_breakdown' => $result['breakdown'],
            'profile' => $result['profile'],
            'scored_at' => now(),
        ]);

        if ($save && $lead->isDirty()) {
            $lead->saveQuietly();
        }

        return $lead;
    }

    /**
     * @return array{score: int, grade: string, completeness: int, breakdown: array<string, mixed>, profile: array<string, mixed>}
     */
    public function compute(Lead $lead): array
    {
        $stage = $lead->stage;
        $groups = [
            $this->profileGroup($lead),
            $this->intentGroup($lead),
            $this->progressGroup($lead, $stage),
            $this->dynamicsGroup($lead),
        ];

        $base = (int) collect($groups)->sum('points');
        $adjust = max(-10, min(10, (int) ($lead->ai_adjustment ?? 0)));
        $score = max(0, min(100, $base + $adjust));
        $note = null;

        if ($stage?->type === 'won') {
            $score = 100;
            $note = 'Cliente concretado: puntaje máximo.';
        } elseif ($stage?->type === 'lost') {
            $score = min($score, 20);
            $note = 'Cliente descartado: el puntaje se limita a 20.';
        }

        $completeness = $this->completeness($lead);

        return [
            'score' => $score,
            'grade' => $this->grade($score),
            'completeness' => $completeness['percent'],
            'breakdown' => ['groups' => $groups, 'base' => $base, 'ai_adjustment' => $adjust, 'note' => $note],
            'profile' => [
                'missing' => $completeness['missing'],
                'signals' => $this->signals($lead),
            ],
        ];
    }

    public function grade(int $score): string
    {
        foreach (self::GRADES as $g => $meta) {
            if ($score >= $meta['min']) {
                return $g;
            }
        }

        return 'D';
    }

    // ------------------------------------------------------------------------------------------ grupos

    /** @return array<string, mixed> */
    private function profileGroup(Lead $lead): array
    {
        $domain = $this->emailDomain($lead);
        $corporate = $domain && ! in_array($domain, self::FREE_DOMAINS, true);
        $title = mb_strtolower((string) $lead->job_title);
        $isDecision = $title !== '' && $this->containsAny($title, self::DECISION);
        $isArea = $title !== '' && $this->containsAny($title, self::AREA);
        $digits = strlen(preg_replace('/\D+/', '', (string) $lead->phone));
        $msgLen = mb_strlen(trim((string) $lead->message));

        return $this->group('profile', 'Perfil y contacto', 30, [
            $this->item('Correo válido', 5, filter_var($lead->email, FILTER_VALIDATE_EMAIL) !== false),
            $this->item('Correo corporativo (dominio propio)', 3, $corporate, $domain && ! $corporate ? 'Correo personal ('.$domain.')' : null),
            $this->item('Teléfono utilizable', 5, $digits >= 8),
            $this->item('Nombre y apellido', 2, trim((string) $lead->first_name) !== '' && trim((string) $lead->last_name) !== ''),
            $this->item('Empresa informada', 4, trim((string) $lead->company) !== ''),
            $this->item('Cargo informado', 3, $title !== ''),
            $this->item('Cargo con poder de decisión', 5, $isDecision),
            $this->item('Cargo en área comercial/marketing', 1, $isArea && ! $isDecision),
            $this->item('Mensaje con contexto', 2, $msgLen >= 20),
        ]);
    }

    /** @return array<string, mixed> */
    private function intentGroup(Lead $lead): array
    {
        $weight = max(0, min(10, (int) ($lead->source?->score_weight ?? 0)));
        $text = mb_strtolower(trim($lead->message.' '.collect($lead->custom ?? [])->flatten()->filter(fn ($v) => is_string($v))->implode(' ')));
        $hits = collect(self::INTENT)->filter(fn ($k) => str_contains($text, $k))->count();
        $intent = min(7, $hits * 2);

        return $this->group('intent', 'Intención y origen', 20, [
            ['label' => 'Calidad del origen'.($lead->source ? ' ('.$lead->source->name.')' : ''), 'points' => $weight, 'max' => 10, 'ok' => $weight > 0, 'hint' => $weight === 0 ? 'Configura el peso del origen en «Configuración CRM → Orígenes de clientes»' : null],
            ['label' => 'Señales de intención en el mensaje', 'points' => $intent, 'max' => 7, 'ok' => $intent > 0, 'hint' => null],
            $this->item('Llegó con campaña (UTM)', 3, filled($lead->utm_campaign) || filled($lead->utm_source)),
        ]);
    }

    /** @return array<string, mixed> */
    private function progressGroup(Lead $lead, ?PipelineStage $stage): array
    {
        $open = PipelineStage::where('type', 'open')->orderBy('sort_order')->pluck('id')->values();
        $idx = $stage ? $open->search($stage->id) : false;
        $ratio = ($idx !== false && $open->count() > 1) ? $idx / ($open->count() - 1) : ($stage && $stage->type !== 'open' ? 1 : 0);
        $stagePts = (int) round($ratio * 12);

        $touches = LeadActivity::where('lead_id', $lead->id)->whereNotIn('type', LeadActivity::AUTOMATIC)->count();
        $touchPts = min(8, $touches * 2);
        $notes = $lead->notes()->count();

        $mail = EmailMessage::query()
            ->where(fn ($q) => $q->where('lead_id', $lead->id)->orWhere('to_email', $lead->email))
            ->selectRaw('COALESCE(SUM(open_count),0) as opens, COALESCE(SUM(click_count),0) as clicks')->first();
        $opens = (int) ($mail->opens ?? 0);
        $clicks = (int) ($mail->clicks ?? 0);

        return $this->group('progress', 'Avance comercial', 30, [
            ['label' => 'Avance en el embudo'.($stage ? ' ('.$stage->name.')' : ''), 'points' => $stagePts, 'max' => 12, 'ok' => $stagePts > 0, 'hint' => null],
            ['label' => 'Seguimientos realizados ('.$touches.')', 'points' => $touchPts, 'max' => 8, 'ok' => $touchPts > 0, 'hint' => $touches === 0 ? 'Aún sin llamadas, correos ni reuniones registradas' : null],
            $this->item('Notas internas', 4, $notes > 0),
            $this->item('Abrió correos nuestros', 2, $opens > 0),
            $this->item('Hizo clic en correos nuestros', 4, $clicks > 0),
        ]);
    }

    /** @return array<string, mixed> */
    private function dynamicsGroup(Lead $lead): array
    {
        $last = LeadActivity::where('lead_id', $lead->id)->whereNotIn('type', ['assigned', 'updated'])->max('occurred_at');
        $lastAt = $last ? Carbon::parse($last) : $lead->created_at;
        $days = $lastAt ? (int) floor($lastAt->diffInDays(now(), false)) : 999;
        $recency = match (true) {
            $days <= 2 => 10,
            $days <= 7 => 7,
            $days <= 14 => 4,
            $days <= 30 => 2,
            default => 0,
        };
        $follow = $lead->next_follow_up_at;
        $overdue = $follow && $follow->isPast() && $follow->diffInDays(now()) > 3;

        return $this->group('dynamics', 'Dinamismo', 20, [
            ['label' => 'Actividad reciente ('.($days <= 0 ? 'hoy' : "hace {$days} d").')', 'points' => $recency, 'max' => 10, 'ok' => $recency > 0, 'hint' => $recency === 0 ? 'Más de 30 días sin movimiento' : null],
            $this->item('Responsable asignado', 3, (bool) $lead->assigned_to),
            $this->item('Valor estimado definido', 4, (float) $lead->estimated_value > 0),
            $this->item('Seguimiento programado al día', 3, $follow !== null && ! $overdue, $overdue ? 'Seguimiento atrasado' : null),
        ]);
    }

    // ------------------------------------------------------------------------------------ completitud

    /** @return array{percent: int, missing: array<int, array<string, mixed>>} */
    private function completeness(Lead $lead): array
    {
        $checks = [
            ['field' => 'email', 'label' => 'Correo', 'weight' => 16, 'ok' => filter_var($lead->email, FILTER_VALIDATE_EMAIL) !== false, 'why' => 'Permite enviar propuestas y automatizaciones'],
            ['field' => 'phone', 'label' => 'Teléfono', 'weight' => 16, 'ok' => strlen(preg_replace('/\D+/', '', (string) $lead->phone)) >= 8, 'why' => 'Habilita el contacto directo (llamada o WhatsApp)'],
            ['field' => 'company', 'label' => 'Empresa', 'weight' => 14, 'ok' => filled($lead->company), 'why' => 'Define el tipo de cliente y su tamaño'],
            ['field' => 'job_title', 'label' => 'Cargo', 'weight' => 12, 'ok' => filled($lead->job_title), 'why' => 'Indica si es quien decide la compra'],
            ['field' => 'last_name', 'label' => 'Apellido', 'weight' => 6, 'ok' => filled($lead->last_name), 'why' => 'Trato más personal en correos'],
            ['field' => 'message', 'label' => 'Mensaje / necesidad', 'weight' => 10, 'ok' => mb_strlen(trim((string) $lead->message)) >= 20, 'why' => 'Revela la intención y el momento de compra'],
            ['field' => 'estimated_value', 'label' => 'Valor estimado', 'weight' => 8, 'ok' => (float) $lead->estimated_value > 0, 'why' => 'Ayuda a priorizar por potencial'],
            ['field' => 'assigned_to', 'label' => 'Responsable', 'weight' => 6, 'ok' => (bool) $lead->assigned_to, 'why' => 'Asegura que alguien lo gestione'],
            ['field' => 'location', 'label' => 'Ubicación', 'weight' => 4, 'ok' => filled($lead->city) || filled($lead->country), 'why' => 'Segmentación geográfica'],
        ];

        $fields = LeadField::query()->where('is_active', true)->get();
        if ($fields->isNotEmpty()) {
            $w = 8 / $fields->count();
            foreach ($fields as $f) {
                $v = $lead->custom[$f->key] ?? null;
                $checks[] = ['field' => 'custom.'.$f->key, 'label' => $f->label, 'weight' => $w, 'ok' => $v !== null && $v !== '' && $v !== [], 'why' => 'Campo personalizado de tu embudo'];
            }
        }

        $total = collect($checks)->sum('weight');
        $done = collect($checks)->where('ok', true)->sum('weight');
        $missing = collect($checks)->where('ok', false)->sortByDesc('weight')->map(fn ($c) => [
            'field' => $c['field'], 'label' => $c['label'], 'why' => $c['why'], 'impact' => (int) round($c['weight'] / max($total, 1) * 100),
        ])->values()->all();

        return ['percent' => (int) round($done / max($total, 1) * 100), 'missing' => $missing];
    }

    // --------------------------------------------------------------------------------------- señales

    /**
     * Datos inferidos que enriquecen el perfil sin pedirlos al cliente.
     *
     * @return array<int, array{key: string, label: string, value: string}>
     */
    private function signals(Lead $lead): array
    {
        $out = [];
        $domain = $this->emailDomain($lead);

        if ($domain) {
            $free = in_array($domain, self::FREE_DOMAINS, true);
            $out[] = ['key' => 'email_type', 'label' => 'Tipo de correo', 'value' => $free ? 'Personal ('.$domain.')' : 'Corporativo ('.$domain.')'];
            if (! $free) {
                $out[] = ['key' => 'website', 'label' => 'Sitio probable', 'value' => 'https://www.'.preg_replace('/^www\./', '', $domain)];
                if (blank($lead->company)) {
                    $out[] = ['key' => 'company_guess', 'label' => 'Empresa probable', 'value' => ucfirst(explode('.', $domain)[0])];
                }
            }
        }

        $title = mb_strtolower((string) $lead->job_title);
        if ($title !== '') {
            $out[] = ['key' => 'seniority', 'label' => 'Nivel del cargo', 'value' => $this->containsAny($title, self::DECISION) ? 'Decisor' : 'Influenciador / operativo'];
        }

        $text = mb_strtolower((string) $lead->message);
        $hits = collect(self::INTENT)->filter(fn ($k) => $text !== '' && str_contains($text, $k))->take(4)->values();
        if ($hits->isNotEmpty()) {
            $out[] = ['key' => 'intent', 'label' => 'Señales de intención', 'value' => $hits->implode(', ')];
        }

        $phone = preg_replace('/\D+/', '', (string) $lead->phone);
        if (strlen($phone) >= 8) {
            $out[] = ['key' => 'phone_type', 'label' => 'Teléfono', 'value' => str_starts_with($phone, '569') || (strlen($phone) === 9 && str_starts_with($phone, '9')) ? 'Móvil chileno (apto WhatsApp)' : 'Fijo o internacional'];
        }

        if ($lead->city || $lead->country) {
            $out[] = ['key' => 'location', 'label' => 'Ubicación (IP)', 'value' => collect([$lead->city, $lead->region, $lead->country])->filter()->implode(', ')];
        }

        if ($lead->utm_campaign || $lead->utm_source) {
            $out[] = ['key' => 'campaign', 'label' => 'Campaña de origen', 'value' => collect([$lead->utm_source, $lead->utm_medium, $lead->utm_campaign])->filter()->implode(' / ')];
        }

        return $out;
    }

    // --------------------------------------------------------------------------------------- helpers

    private function emailDomain(Lead $lead): ?string
    {
        return filter_var($lead->email, FILTER_VALIDATE_EMAIL) ? strtolower(substr(strrchr((string) $lead->email, '@'), 1)) : null;
    }

    /** @param  array<int, string>  $needles */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if (str_contains($haystack, $n)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{label: string, points: int, max: int, ok: bool, hint: ?string} */
    private function item(string $label, int $max, bool $ok, ?string $hint = null): array
    {
        return ['label' => $label, 'points' => $ok ? $max : 0, 'max' => $max, 'ok' => $ok, 'hint' => $ok ? null : $hint];
    }

    /** @param  array<int, array<string, mixed>>  $items */
    private function group(string $key, string $label, int $max, array $items): array
    {
        $points = min($max, (int) collect($items)->sum('points'));

        return ['key' => $key, 'label' => $label, 'points' => $points, 'max' => $max, 'items' => $items];
    }
}
