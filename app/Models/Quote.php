<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** Cotización inmobiliaria: una unidad principal más complementos (estacionamiento, bodega, quincho…), con pie y crédito. */
#[Fillable([
    'number', 'project_id', 'lead_id', 'client_id', 'user_id', 'status', 'buyer', 'uf_value', 'uf_date', 'valid_until',
    'subtotal_uf', 'discount_uf', 'total_uf', 'pie_pct', 'pie_uf', 'reservation_amount', 'pie_installments', 'credit_uf',
    'mortgage_rate', 'mortgage_years', 'monthly_payment_uf', 'notes', 'internal_notes', 'public_token', 'sent_at', 'viewed_at',
    'view_count', 'lost_reason',
])]
class Quote extends Model
{
    use SoftDeletes;

    public const STATUSES = [
        'draft' => 'Borrador',
        'sent' => 'Enviada',
        'viewed' => 'Vista',
        'reserved' => 'Reservada',
        'expired' => 'Vencida',
        'lost' => 'Perdida',
    ];

    public const STATUS_COLORS = [
        'draft' => '#98A2B3', 'sent' => '#2E90FA', 'viewed' => '#6419DB', 'reserved' => '#12B76A', 'expired' => '#F79009', 'lost' => '#F04438',
    ];

    protected function casts(): array
    {
        return [
            'buyer' => 'array',
            'uf_value' => 'float', 'uf_date' => 'date', 'valid_until' => 'date',
            'subtotal_uf' => 'float', 'discount_uf' => 'float', 'total_uf' => 'float', 'pie_pct' => 'float', 'pie_uf' => 'float',
            'reservation_amount' => 'float', 'credit_uf' => 'float', 'mortgage_rate' => 'float', 'monthly_payment_uf' => 'float',
            'sent_at' => 'datetime', 'viewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Quote $q) {
            $q->public_token ??= (string) Str::uuid();
            $q->number ??= static::nextNumber();
        });
    }

    public static function nextNumber(): string
    {
        $year = now()->year;
        $last = static::withTrashed()->where('number', 'like', "C-{$year}-%")->orderByDesc('number')->value('number');

        return sprintf('C-%d-%04d', $year, $last ? ((int) substr($last, -4)) + 1 : 1);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reservation(): HasOne
    {
        return $this->hasOne(Reservation::class)->where('status', '!=', 'cancelled');
    }

    /** Una cotización enviada y vencida se considera «Vencida» aunque nadie haya cambiado su estado. */
    public function effectiveStatus(): string
    {
        if (in_array($this->status, ['sent', 'viewed'], true) && $this->valid_until && $this->valid_until->endOfDay()->isPast()) {
            return 'expired';
        }

        return $this->status;
    }

    /** Ve todas quien tiene «quotes.view_all»; el resto, las suyas y las de los proyectos donde es ejecutivo. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->hasPermission('quotes.view_all')
            ? $query
            : $query->where(fn ($q) => $q->where('user_id', $user->id)->orWhereHas('lead', fn ($l) => $l->where('assigned_to', $user->id)));
    }

    public function clp(float|int|null $uf): int
    {
        return (int) round((float) $uf * (float) $this->uf_value);
    }

    public function publicUrl(): string
    {
        return url('/c/'.$this->public_token);
    }

    public function buyerName(): ?string
    {
        return $this->buyer['name'] ?? $this->client?->name ?? $this->lead?->full_name;
    }
}
