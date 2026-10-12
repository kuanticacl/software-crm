<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pago del plan de una reserva: la reserva (pesos), cada cuota del pie (UF) y el saldo con crédito. */
#[Fillable(['reservation_id', 'kind', 'number', 'label', 'due_date', 'currency', 'amount', 'status', 'paid_at', 'paid_clp', 'method', 'reference', 'notes'])]
class ReservationPayment extends Model
{
    public const KINDS = ['reserva' => 'Reserva', 'pie' => 'Cuota del pie', 'saldo' => 'Saldo (crédito / contado)', 'otro' => 'Otro'];

    public const STATUSES = ['pending' => 'Pendiente', 'paid' => 'Pagado', 'cancelled' => 'Anulado'];

    public const METHODS = ['Transferencia', 'Webpay', 'Cheque', 'Vale vista', 'Efectivo', 'Tarjeta de crédito', 'Crédito hipotecario', 'Subsidio', 'Otro'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'paid_at' => 'date', 'amount' => 'float', 'paid_clp' => 'float', 'number' => 'integer'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->due_date && $this->due_date->endOfDay()->isPast();
    }
}
