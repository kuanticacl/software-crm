<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'number', 'title', 'currency', 'uf_value', 'uf_date', 'client_id', 'lead_id', 'user_id', 'status', 'recipient', 'sections',
    'issued_at', 'valid_until', 'contract_months', 'discount_type', 'discount_value', 'tax_rate', 'tax_mode',
    'total_one_time', 'total_monthly', 'total_net', 'total_tax', 'total_gross',
    'internal_notes', 'public_token', 'sent_at', 'viewed_at', 'view_count', 'responded_at', 'responded_by', 'signer_rut', 'response_note', 'response_ip', 'response_user_agent', 'signature_data',
])]
class Proposal extends Model
{
    use SoftDeletes;

    public const STATUSES = [
        'draft' => 'Borrador',
        'sent' => 'Enviada',
        'viewed' => 'Vista',
        'changes_requested' => 'Ajustes solicitados',
        'accepted' => 'Aceptada',
        'rejected' => 'Rechazada',
        'expired' => 'Vencida',
    ];

    public const STATUS_COLORS = [
        'draft' => '#8A8A8A', 'sent' => '#1AA0E4', 'viewed' => '#121826', 'changes_requested' => '#D97706', 'accepted' => '#0D9F85', 'rejected' => '#DC2626', 'expired' => '#C23F00',
    ];

    /**
     * Cómo se cobra: en honorarios el monto se habla neto y se suma la retención de la boleta
     * (el cliente paga el bruto); con IVA se suma el IVA; sin impuesto, el total es el neto.
     */
    public const TAX_MODES = [
        'honorarios' => 'Neto + retención boleta de honorarios',
        'iva' => 'Neto + IVA (factura)',
        'none' => 'Sin impuesto',
    ];

    protected function casts(): array
    {
        return [
            'recipient' => 'array',
            'sections' => 'array',
            'issued_at' => 'date',
            'uf_date' => 'date',
            'uf_value' => 'float',
            'discount_value' => 'float',
            'tax_rate' => 'float',
            'total_one_time' => 'float',
            'total_monthly' => 'float',
            'total_net' => 'float',
            'total_tax' => 'float',
            'total_gross' => 'float',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Proposal $p) {
            $p->public_token ??= (string) Str::uuid();
            $p->number ??= static::nextNumber();
            $p->issued_at ??= now();
        });
    }

    public static function nextNumber(): string
    {
        $year = now()->year;
        $last = static::withTrashed()->where('number', 'like', "P-{$year}-%")->orderByDesc('number')->value('number');
        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        return sprintf('P-%d-%04d', $year, $seq);
    }

    /** Etiquetas de los totales según el modo de impuesto. @return array{net: string, tax: ?string, gross: string} */
    public function taxLabels(): array
    {
        $rate = rtrim(rtrim(number_format((float) $this->tax_rate, 2, ',', ''), '0'), ',');

        return match ($this->tax_mode) {
            'iva' => ['net' => 'Total neto', 'tax' => "IVA {$rate}%", 'gross' => 'Total con IVA'],
            'none' => ['net' => 'Total', 'tax' => null, 'gross' => 'Total'],
            default => ['net' => 'Honorarios netos', 'tax' => "Retención boleta de honorarios {$rate}%", 'gross' => 'Total boleta de honorarios'],
        };
    }

    /** Decimales de la moneda: la UF lleva 2, el peso chileno ninguno. */
    public function decimals(): int
    {
        return $this->currency === 'UF' ? 2 : 0;
    }

    /** Monto en pesos (con la UF guardada en la propuesta si está en UF). */
    public function clp(float|int|null $amount): int
    {
        $amount = (float) $amount;

        return (int) round($this->currency === 'UF' ? $amount * (float) $this->uf_value : $amount);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProposalItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Una propuesta enviada y vencida se considera «Vencida» aunque nadie haya cambiado su estado. */
    public function effectiveStatus(): string
    {
        if (in_array($this->status, ['sent', 'viewed'], true) && $this->valid_until && $this->valid_until->endOfDay()->isPast()) {
            return 'expired';
        }

        return $this->status;
    }

    public function isFinal(): bool
    {
        return in_array($this->status, ['accepted', 'rejected'], true);
    }

    /** Sin leads.view_all solo se ven las propias o las de leads asignados al usuario. */
    public function scopeVisibleTo(\Illuminate\Database\Eloquent\Builder $query, User $user): \Illuminate\Database\Eloquent\Builder
    {
        return $user->hasPermission('leads.view_all')
            ? $query
            : $query->where(fn ($q) => $q->where('user_id', $user->id)->orWhereHas('lead', fn ($l) => $l->where('assigned_to', $user->id)));
    }

    public function publicUrl(): string
    {
        return url('/p/'.$this->public_token);
    }
}
