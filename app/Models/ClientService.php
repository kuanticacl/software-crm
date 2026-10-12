<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\CarbonInterface;

/**
 * Servicio contratado por una empresa (con fechas, ciclo de cobro, renovación y servicios asociados).
 * «price», «internal_notes», costos y gastos son internos: el portal nunca los expone.
 */
#[Fillable(['client_id', 'proposal_id', 'catalog_service_id', 'parent_id', 'name', 'description', 'billing_cycle', 'modality', 'currency', 'price', 'works_budget', 'project_address', 'start_date', 'end_date', 'auto_renew', 'status', 'billing_day', 'next_charge_on', 'reminder_offsets', 'payment_link', 'internal_notes', 'created_by'])]
class ClientService extends Model
{
    use SoftDeletes;

    public const CYCLES = ['one_time' => 'Pago único', 'monthly' => 'Mensual', 'quarterly' => 'Trimestral', 'yearly' => 'Anual'];

    public const MODALITIES = ['honorarios' => 'Solo honorarios', 'honorarios_obra' => 'Honorarios + obra completa'];

    /** Estados que se eligen a mano. «Por iniciar» y «Finalizado» se deducen de las fechas (ver effectiveStatus). */
    public const STATUSES = ['active' => 'Activo', 'pending_payment' => 'Pendiente de pago', 'blocked' => 'Bloqueado', 'cancelled' => 'Cancelado'];

    /** Estados que solo se muestran (según las fechas) y sirven de filtro. */
    public const DERIVED = ['pending' => 'Por iniciar', 'ended' => 'Finalizado'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'next_charge_on' => 'date',
            'auto_renew' => 'boolean',
            'price' => 'float',
            'works_budget' => 'float',
            'reminder_offsets' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(ServiceExpense::class);
    }

    public function isRecurring(): bool
    {
        return $this->billing_cycle !== 'one_time';
    }

    /** Estado según las fechas: un servicio activo cuyo término ya pasó (sin renovación) figura como finalizado. */
    public function effectiveStatus(): string
    {
        $running = in_array($this->status, ['active', 'pending_payment'], true);

        if ($running && $this->end_date && ! $this->auto_renew && $this->end_date->lt(today())) {
            return 'ended';
        }
        if ($this->status === 'active' && $this->start_date && $this->start_date->gt(today())) {
            return 'pending';
        }

        return $this->status;
    }

    /** ¿Está vigente y generando ingreso? (activo o pendiente de pago, dentro de sus fechas). */
    public function isLive(): bool
    {
        return in_array($this->effectiveStatus(), ['active', 'pending_payment'], true);
    }

    /** Suma un período de cobro a una fecha (mantiene el día de cobro sin desbordar el mes). */
    public function addPeriod(CarbonInterface $date): CarbonInterface
    {
        $next = match ($this->billing_cycle) {
            'monthly' => $date->copy()->startOfMonth()->addMonthNoOverflow(),
            'quarterly' => $date->copy()->startOfMonth()->addMonthsNoOverflow(3),
            'yearly' => $date->copy()->startOfMonth()->addYearNoOverflow(),
            default => $date->copy(),
        };

        $day = $this->billing_day ?: $this->start_date?->day ?: $date->day;

        return $next->day(min($day, $next->daysInMonth));
    }

    /** Primera fecha de cobro: el día de cobro en o después del inicio del servicio. */
    public function firstChargeDate(): CarbonInterface
    {
        $start = $this->start_date->copy();
        if (! $this->billing_day) {
            return $start;
        }
        $candidate = $start->copy()->day(min($this->billing_day, $start->daysInMonth));

        return $candidate->lt($start) ? $this->addPeriodMonth($candidate) : $candidate;
    }

    private function addPeriodMonth(CarbonInterface $d): CarbonInterface
    {
        $n = $d->copy()->startOfMonth()->addMonthNoOverflow();

        return $n->day(min($this->billing_day, $n->daysInMonth));
    }
}
