<?php

namespace App\Services;

use App\Jobs\AnalyzeLead;
use App\Models\Lead;
use App\Services\Leads\LeadAssignment;
use App\Services\Leads\LeadScorer;
use App\Models\LeadActivity;
use App\Models\LeadField;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Email\AutomationRunner;
use Illuminate\Support\Facades\DB;

class LeadService
{
    /** Separación entre posiciones del Kanban: permite insertar entre dos tarjetas sin renumerar. */
    private const GAP = 1024;

    /**
     * Normaliza el valor estimado: se guarda el monto en la moneda elegida (CLP o UF) y su equivalente
     * en pesos (estimated_value, con la UF de hoy), que es lo que suman el Kanban y el dashboard.
     * Si solo llega estimated_value (API, agente, propuesta) se entiende en pesos.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public static function withValue(array $a): array
    {
        $legacy = array_key_exists('estimated_value', $a) && $a['estimated_value'] !== null;
        if (array_key_exists('estimated_amount', $a) && ! ($legacy && ($a['estimated_amount'] ?? null) === null)) {
            $amount = $a['estimated_amount'] === null || $a['estimated_amount'] === '' ? null : (float) $a['estimated_amount'];
            $currency = strtoupper((string) ($a['estimated_currency'] ?? 'CLP')) === 'UF' ? 'UF' : 'CLP';
            $clp = $amount;
            if ($amount !== null && $currency === 'UF') {
                $uf = app(\App\Services\UfService::class)->today()['value'] ?? null;
                if (! $uf) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['estimated_amount' => 'No hay valor de UF disponible para convertir. Ingresa el monto en pesos.']);
                }
                $clp = round($amount * $uf);
            }
            $a['estimated_amount'] = $amount;
            $a['estimated_currency'] = $amount === null ? 'CLP' : $currency;
            $a['estimated_value'] = $clp;
        } elseif (array_key_exists('estimated_value', $a)) {
            $a['estimated_currency'] = 'CLP';
            $a['estimated_amount'] = $a['estimated_value'];
        }

        return $a;
    }

    /**
     * Crea un lead en la primera etapa (arriba de la columna) y registra el ingreso.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?User $actor = null, array $activity = []): Lead
    {
        $attributes = $this->withDefaultAssignee($attributes);

        $lead = DB::transaction(function () use ($attributes, $actor, $activity) {
            $stageId = $attributes['stage_id'] ?? PipelineStage::initial()?->id;

            $lead = new Lead(self::withValue($attributes));
            $lead->stage_id = $stageId;
            $lead->position = $this->topPosition($stageId);
            $lead->stage_changed_at = now();
            $lead->save();

            $this->log($lead, 'created', $actor, 'Cliente ingresado'.($lead->source ? " desde {$lead->source->name}" : ''), $activity);

            if ($lead->assigned_to) {
                $this->log($lead, 'assigned', $actor, 'Asignado a '.$lead->assignee?->name, ['to' => $lead->assigned_to]);
            }

            return $lead;
        });

        LeadScorer::refresh($lead);
        AnalyzeLead::dispatchIfEnabled($lead);

        // Fuera de la transacción: un fallo de email nunca debe deshacer el alta del lead.
        // Si la web ya pidió su propia plantilla (email_template), las automatizaciones «solo web» no le escriben de nuevo.
        $channel = ($activity['channel'] ?? 'manual').(! empty($activity['email_template']) ? '+template' : '');
        app(AutomationRunner::class)->fire('lead.created', $lead->fresh(['source', 'stage']), [], $channel);

        return $lead;
    }

    /**
     * Ningún cliente queda sin asignar: si no viene responsable, se asigna al del origen (reparto parejo si son
     * varios) o al responsable por defecto. Un fallo aquí nunca debe impedir el alta del cliente.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withDefaultAssignee(array $attributes): array
    {
        if (! empty($attributes['assigned_to'])) {
            return $attributes;
        }

        try {
            $user = app(LeadAssignment::class)->assigneeFor(isset($attributes['source_id']) ? (int) $attributes['source_id'] : null);
            if ($user) {
                $attributes['assigned_to'] = $user->id;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $attributes;
    }

    /**
     * Actualiza datos del lead y deja constancia de qué campos cambiaron.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Lead $lead, array $attributes, ?User $actor = null): Lead
    {
        $oldStage = $lead->stage_id;
        $oldAssignee = $lead->assigned_to;

        $lead->fill(self::withValue($attributes));
        $changed = array_keys($lead->getDirty());
        $lead->save();

        $labels = [
            'first_name' => 'nombre', 'last_name' => 'apellido', 'email' => 'correo', 'phone' => 'teléfono',
            'job_title' => 'cargo', 'company' => 'empresa', 'message' => 'mensaje', 'client_id' => 'cliente',
            'source_id' => 'origen', 'custom' => 'campos personalizados', 'priority' => 'prioridad',
            'estimated_value' => 'valor estimado', 'estimated_amount' => 'valor estimado', 'estimated_currency' => 'valor estimado', 'tags' => 'etiquetas', 'next_follow_up_at' => 'próximo seguimiento',
        ];

        $edited = collect($changed)->intersect(array_keys($labels))->map(fn ($k) => $labels[$k])->unique()->values();
        if ($edited->isNotEmpty()) {
            $this->log($lead, 'updated', $actor, 'Datos actualizados: '.$edited->implode(', '));
        }

        if ($oldStage !== $lead->stage_id) {
            $this->applyStageEffects($lead);
            $lead->save();
            $this->logStageChange($lead, $oldStage, $lead->stage_id, $actor);
            $this->fireStageChanged($lead, $oldStage);
        }

        if ($oldAssignee !== $lead->assigned_to) {
            $this->logAssignment($lead, $actor);
        }

        LeadScorer::refresh($lead);
        if ($changed) {
            AnalyzeLead::dispatchIfEnabled($lead);
        }

        return $lead;
    }

    /** Mueve el lead a una etapa y lo ubica entre dos tarjetas (o arriba si no se indican). */
    public function move(Lead $lead, int $stageId, ?int $afterId = null, ?User $actor = null, array $closing = []): Lead
    {
        $before = $lead->stage_id;

        $lead = DB::transaction(function () use ($lead, $stageId, $afterId, $actor, $closing) {
            $oldStage = $lead->stage_id;

            $lead->stage_id = $stageId;
            $lead->position = $this->positionAfter($stageId, $afterId, $lead->id);

            if ($oldStage !== $stageId) {
                $this->applyStageEffects($lead, $closing);
            }
            $lead->save();

            if ($oldStage !== $stageId) {
                $this->logStageChange($lead, $oldStage, $stageId, $actor, $closing);
            }

            return $lead;
        });

        if ($before !== $lead->stage_id) {
            LeadScorer::refresh($lead);
            $this->fireStageChanged($lead, $before);
        }

        return $lead;
    }

    private function fireStageChanged(Lead $lead, ?int $previousStageId): void
    {
        app(AutomationRunner::class)->fire('lead.stage_changed', $lead->fresh(['source', 'stage']), [
            'previous_stage' => $previousStageId ? PipelineStage::whereKey($previousStageId)->value('name') : null,
        ]);
    }

    /**
     * Al cambiar de etapa: reinicia el contador de "tiempo en etapa" y registra el cierre
     * (concretado/descartado) con su valor o motivo.
     *
     * @param  array{lost_reason?: ?string, estimated_value?: ?float}  $closing
     */
    private function applyStageEffects(Lead $lead, array $closing = []): void
    {
        $lead->stage_changed_at = now();
        $type = PipelineStage::whereKey($lead->stage_id)->value('type');

        if (in_array($type, ['won', 'lost'], true)) {
            $lead->closed_at = now();
            $lead->next_follow_up_at = null;
            $lead->lost_reason = $type === 'lost' ? ($closing['lost_reason'] ?? $lead->lost_reason) : null;
            if ($type === 'won' && (isset($closing['estimated_amount']) || isset($closing['estimated_value']))) {
                $lead->fill(self::withValue($closing));
            }
        } else {
            $lead->closed_at = null;
            $lead->lost_reason = null;
        }
    }

    public function assign(Lead $lead, ?int $userId, ?User $actor = null): Lead
    {
        if ($lead->assigned_to === $userId) {
            return $lead;
        }

        $lead->assigned_to = $userId;
        $lead->save();
        $this->logAssignment($lead, $actor);
        LeadScorer::refresh($lead);

        return $lead;
    }

    public function log(Lead $lead, string $type, ?User $actor, ?string $description = null, array $properties = [], $occurredAt = null): LeadActivity
    {
        $activity = $lead->activities()->create([
            'user_id' => $actor?->id,
            'type' => $type,
            'description' => $description,
            'properties' => $properties ?: null,
            'occurred_at' => $occurredAt ?? now(),
        ]);

        // Un seguimiento humano (llamada, reunión…) mejora el perfil del lead.
        if (! in_array($type, LeadActivity::AUTOMATIC, true)) {
            LeadScorer::refresh($lead);
        }

        return $activity;
    }

    private function logStageChange(Lead $lead, ?int $from, int $to, ?User $actor, array $closing = []): void
    {
        $names = PipelineStage::whereIn('id', array_filter([$from, $to]))->pluck('name', 'id');

        $this->log(
            $lead,
            'stage_changed',
            $actor,
            'Etapa: '.($names[$from] ?? '—').' → '.($names[$to] ?? '—').(! empty($closing['lost_reason']) ? " (motivo: {$closing['lost_reason']})" : ''),
            ['from' => $from, 'to' => $to],
        );
    }

    private function logAssignment(Lead $lead, ?User $actor): void
    {
        $this->log(
            $lead,
            'assigned',
            $actor,
            $lead->assigned_to ? 'Asignado a '.User::find($lead->assigned_to)?->name : 'Se quitó la asignación',
            ['to' => $lead->assigned_to],
        );
    }

    private function topPosition(int $stageId): int
    {
        $min = Lead::withTrashed()->where('stage_id', $stageId)->min('position');

        return $min === null ? 0 : (int) $min - self::GAP;
    }

    /** Posición para insertar justo después de $afterId (o al inicio de la columna si es null). */
    private function positionAfter(int $stageId, ?int $afterId, int $movingId): int
    {
        $column = fn () => Lead::where('stage_id', $stageId)->where('id', '!=', $movingId);

        if ($afterId === null) {
            $min = $column()->min('position');

            return $min === null ? 0 : (int) $min - self::GAP;
        }

        $after = $column()->whereKey($afterId)->first();
        if (! $after) {
            return $this->positionAfter($stageId, null, $movingId);
        }

        $next = $column()->where('position', '>', $after->position)->orderBy('position')->first();

        if (! $next) {
            return (int) $after->position + self::GAP;
        }

        if ($next->position - $after->position < 2) {
            $this->renumber($stageId);
            $after->refresh();

            return $this->positionAfter($stageId, $afterId, $movingId);
        }

        return (int) floor(($after->position + $next->position) / 2);
    }

    private function renumber(int $stageId): void
    {
        $i = 0;
        Lead::where('stage_id', $stageId)->orderBy('position')->orderBy('id')->get(['id'])->each(function ($lead) use (&$i) {
            Lead::whereKey($lead->id)->update(['position' => $i++ * self::GAP]);
        });
    }

    /**
     * Separa los valores de campos personalizados recibidos de los que no se reconocen.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [custom, desconocidos]
     */
    public function normalizeCustom(array $input): array
    {
        $fields = LeadField::where('is_active', true)->get()->keyBy('key');
        $custom = [];
        $unknown = [];

        foreach ($input as $key => $value) {
            $field = $fields->get($key);

            if (! $field) {
                $unknown[$key] = $value;

                continue;
            }

            $custom[$key] = match ($field->type) {
                'checkbox' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'number' => is_numeric($value) ? $value + 0 : $value,
                default => is_scalar($value) ? (string) $value : $value,
            };
        }

        return [$custom, $unknown];
    }
}
