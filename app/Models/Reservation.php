<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Reserva de una o más unidades: pago inicial, plan de pagos del pie y seguimiento hasta promesa y escritura. */
#[Fillable([
    'number', 'project_id', 'quote_id', 'lead_id', 'client_id', 'user_id', 'status', 'reserved_at', 'promise_due_date', 'promised_at',
    'deeded_at', 'cancelled_at', 'cancel_reason', 'uf_value', 'subtotal_uf', 'discount_uf', 'total_uf', 'pie_pct', 'pie_uf', 'credit_uf',
    'financing', 'bank', 'notes',
])]
class Reservation extends Model
{
    use SoftDeletes;

    public const STATUSES = [
        'reserved' => 'Reservada',
        'promised' => 'Promesada',
        'deeded' => 'Escriturada',
        'cancelled' => 'Desistida',
    ];

    public const STATUS_COLORS = ['reserved' => '#F79009', 'promised' => '#6419DB', 'deeded' => '#12B76A', 'cancelled' => '#F04438'];

    /** Estado que toman las unidades según el estado de la reserva. */
    public const UNIT_STATUS = ['reserved' => 'reservada', 'promised' => 'promesada', 'deeded' => 'vendida', 'cancelled' => 'disponible'];

    public const FINANCING = ['credito' => 'Crédito hipotecario', 'contado' => 'Contado', 'subsidio' => 'Subsidio habitacional', 'mixto' => 'Mixto'];

    protected function casts(): array
    {
        return [
            'reserved_at' => 'date', 'promise_due_date' => 'date', 'promised_at' => 'date', 'deeded_at' => 'date', 'cancelled_at' => 'date',
            'uf_value' => 'float', 'subtotal_uf' => 'float', 'discount_uf' => 'float', 'total_uf' => 'float', 'pie_pct' => 'float', 'pie_uf' => 'float', 'credit_uf' => 'float',
        ];
    }

    public static function nextNumber(): string
    {
        $year = now()->year;
        $last = static::withTrashed()->where('number', 'like', "R-{$year}-%")->orderByDesc('number')->value('number');

        return sprintf('R-%d-%04d', $year, $last ? ((int) substr($last, -4)) + 1 : 1);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class)->withTrashed();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(ReservationUnit::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ReservationPayment::class)->orderByRaw("CASE kind WHEN 'reserva' THEN 0 WHEN 'pie' THEN 1 WHEN 'otro' THEN 2 ELSE 3 END")->orderBy('due_date')->orderBy('number');
    }

    public function isActive(): bool
    {
        return $this->status !== 'cancelled';
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->hasPermission('reservations.view_all')
            ? $query
            : $query->where(fn ($q) => $q->where('user_id', $user->id)->orWhereHas('lead', fn ($l) => $l->where('assigned_to', $user->id)));
    }
}
