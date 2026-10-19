<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'trigger', 'conditions', 'template_id', 'subject', 'to_mode', 'to_email', 'delay_minutes', 'offset_days', 'variables', 'is_active', 'runs_count', 'last_run_at', 'created_by'])]
class Automation extends Model
{
    public const TRIGGERS = [
        'lead.created' => 'Cuando ingresa un cliente nuevo',
        'lead.stage_changed' => 'Cuando un cliente cambia de etapa',
        'invoice.issued' => 'Cobro: cuando se emite un cobro',
        'invoice.due' => 'Cobro: días antes o después del vencimiento',
        'invoice.paid' => 'Cobro: cuando se registra un pago',
        'subscription.activated' => 'Suscripción: cuando comienza',
        'subscription.renewal' => 'Suscripción: días antes de la renovación o término',
    ];

    /** Disparadores de suscripciones y cobros (el destinatario es la empresa cliente, no un lead). */
    public const BILLING_TRIGGERS = ['invoice.issued', 'invoice.due', 'invoice.paid', 'subscription.activated', 'subscription.renewal'];

    /** Disparadores que usan «días antes/después» (offset_days). */
    public const OFFSET_TRIGGERS = ['invoice.due', 'subscription.renewal'];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'variables' => 'array',
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'template_id');
    }
}
