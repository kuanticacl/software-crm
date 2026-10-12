<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'trigger', 'conditions', 'template_id', 'subject', 'to_mode', 'to_email', 'delay_minutes', 'variables', 'is_active', 'runs_count', 'last_run_at', 'created_by'])]
class Automation extends Model
{
    public const TRIGGERS = [
        'lead.created' => 'Cuando ingresa un prospecto nuevo',
        'lead.stage_changed' => 'Cuando un prospecto cambia de etapa',
    ];

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
