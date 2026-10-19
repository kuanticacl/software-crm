<?php

namespace App\Models;

use App\Support\BillingPeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'uid', 'client_id', 'plan_id', 'proposal_id', 'period', 'amount', 'currency', 'tax_rate', 'status', 'auto_renew', 'started_at',
    'current_period_start', 'current_period_end', 'cancelled_at', 'cancel_reason', 'suspended_at', 'limit_overrides', 'contract_text', 'notes', 'created_by',
])]
class Subscription extends Model
{
    public const STATUSES = [
        'active' => 'Activa', 'past_due' => 'Con pago vencido', 'suspended' => 'Suspendida', 'cancelled' => 'Cancelada', 'expired' => 'Finalizada',
    ];

    public const COLORS = ['active' => '#0D9F85', 'past_due' => '#D97706', 'suspended' => '#8A98A8', 'cancelled' => '#DC2626', 'expired' => '#6366F1'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer', 'tax_rate' => 'float', 'auto_renew' => 'boolean', 'started_at' => 'date',
            'current_period_start' => 'date', 'current_period_end' => 'date', 'cancelled_at' => 'datetime', 'suspended_at' => 'datetime',
            'limit_overrides' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Subscription $s) => $s->uid ??= 'sub_'.Str::lower(Str::random(16)));
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withTrashed();
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** ¿La app debería dejar usar el servicio? (con un pago vencido todavía se permite; el bloqueo es decisión manual). */
    public function hasAccess(): bool
    {
        return in_array($this->status, ['active', 'past_due'], true);
    }

    public function periodLabel(): string
    {
        return BillingPeriod::LABELS[$this->period] ?? $this->period;
    }

    public function net(): int
    {
        return (int) $this->amount;
    }

    public function gross(): int
    {
        return (int) round($this->amount * (1 + $this->tax_rate / 100));
    }

    /** Estructura para la API y las pantallas. @return array<string, mixed> */
    public function toApi(): array
    {
        $this->loadMissing('plan.limits', 'plan.prices');

        return [
            'id' => $this->uid,
            'status' => $this->status,
            'status_label' => self::STATUSES[$this->status] ?? $this->status,
            'access' => $this->hasAccess(),
            'plan' => ['id' => $this->plan->slug, 'name' => $this->plan->name],
            'period' => $this->period,
            'amount_net' => $this->net(),
            'amount_total' => $this->gross(),
            'currency' => $this->currency,
            'tax_rate' => $this->tax_rate,
            'auto_renew' => $this->auto_renew,
            'started_at' => $this->started_at->toDateString(),
            'current_period_start' => $this->current_period_start->toDateString(),
            'current_period_end' => $this->current_period_end->toDateString(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'limits' => collect($this->plan->limitsList($this->limit_overrides ?? []))->map(fn ($l) => [
                'key' => $l['key'], 'name' => $l['name'], 'unit' => $l['unit'], 'value' => $l['value'], 'unlimited' => $l['value'] === null,
            ])->all(),
        ];
    }
}
