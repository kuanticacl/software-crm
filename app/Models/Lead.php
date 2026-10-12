<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'first_name', 'last_name', 'email', 'phone', 'job_title', 'company', 'message',
    'client_id', 'source_id', 'stage_id', 'assigned_to', 'position',
    'priority', 'estimated_value', 'estimated_currency', 'estimated_amount', 'tags', 'next_follow_up_at', 'stage_changed_at', 'closed_at', 'lost_reason',
    'score', 'score_grade', 'profile_completeness', 'score_breakdown', 'profile', 'scored_at',
    'ai_analysis', 'ai_analyzed_at', 'ai_input_hash', 'ai_adjustment',
    'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
    'ip_address', 'user_agent', 'referrer', 'landing_url',
    'country', 'region', 'city', 'latitude', 'longitude', 'meta', 'custom',
])]
class Lead extends Model
{
    use SoftDeletes;

    public const PRIORITIES = ['low' => 'Baja', 'normal' => 'Normal', 'high' => 'Alta', 'urgent' => 'Urgente'];

    public const UTM_FIELDS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'custom' => 'array',
            'tags' => 'array',
            'estimated_value' => 'float',
            'estimated_amount' => 'float',
            'next_follow_up_at' => 'datetime',
            'stage_changed_at' => 'datetime',
            'closed_at' => 'datetime',
            'score_breakdown' => 'array',
            'profile' => 'array',
            'scored_at' => 'datetime',
            'ai_analysis' => 'array',
            'ai_analyzed_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class)->latest('id');
    }

    public function latestProposal(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Proposal::class)->latestOfMany();
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class)->latest('id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class)->latest('id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(LeadNote::class)->latest();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->latest('occurred_at')->latest('id');
    }

    /** Un usuario sin leads.view_all solo ve los leads asignados a él. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->hasPermission('leads.view_all')
            ? $query
            : $query->where('assigned_to', $user->id);
    }
}
