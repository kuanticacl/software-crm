<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'number', 'subscription_id', 'client_id', 'concept', 'period_start', 'period_end', 'currency', 'amount_net', 'tax_rate', 'amount_tax', 'amount_total',
    'issued_at', 'due_date', 'status', 'paid_at', 'payment_method', 'payment_reference', 'payment_link', 'document_number', 'notes', 'sent_at',
])]
class Invoice extends Model
{
    public const STATUSES = ['pending' => 'Pendiente', 'paid' => 'Pagado', 'void' => 'Anulado'];

    public const METHODS = ['transferencia' => 'Transferencia', 'tarjeta' => 'Tarjeta', 'webpay' => 'Webpay', 'efectivo' => 'Efectivo', 'otro' => 'Otro'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'issued_at' => 'date', 'due_date' => 'date',
            'paid_at' => 'datetime', 'sent_at' => 'datetime', 'tax_rate' => 'float',
            'amount_net' => 'integer', 'amount_tax' => 'integer', 'amount_total' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->due_date->lt(today());
    }

    /** Estado efectivo: «overdue» si está pendiente y vencida. */
    public function effectiveStatus(): string
    {
        return $this->isOverdue() ? 'overdue' : $this->status;
    }

    public function scopeOverdue($q)
    {
        return $q->where('status', 'pending')->whereDate('due_date', '<', today());
    }

    public static function nextNumber(): string
    {
        $year = now()->year;
        $last = static::where('number', 'like', "C-{$year}-%")->orderByDesc('number')->value('number');

        return sprintf('C-%d-%05d', $year, $last ? ((int) substr($last, -5)) + 1 : 1);
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'number' => $this->number,
            'concept' => $this->concept,
            'status' => $this->effectiveStatus(),
            'status_label' => ['pending' => 'Pendiente', 'overdue' => 'Vencida', 'paid' => 'Pagada', 'void' => 'Anulada'][$this->effectiveStatus()],
            'currency' => $this->currency,
            'amount_net' => $this->amount_net,
            'tax_rate' => $this->tax_rate,
            'amount_tax' => $this->amount_tax,
            'amount_total' => $this->amount_total,
            'issued_at' => $this->issued_at->toDateString(),
            'due_date' => $this->due_date->toDateString(),
            'days_overdue' => $this->isOverdue() ? (int) $this->due_date->diffInDays(today()) : 0,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'payment_method' => $this->payment_method,
            'payment_link' => $this->payment_link,
            'document_number' => $this->document_number,
            'subscription_id' => $this->subscription?->uid,
        ];
    }
}
