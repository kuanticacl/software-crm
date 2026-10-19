<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'slug', 'tagline', 'description', 'features', 'is_active', 'is_public', 'sort_order'])]
class Plan extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['features' => 'array', 'is_active' => 'boolean', 'is_public' => 'boolean'];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    public function limits(): BelongsToMany
    {
        return $this->belongsToMany(LimitType::class, 'plan_limits')->withPivot('value');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function priceFor(string $period): ?int
    {
        $price = $this->relationLoaded('prices') ? $this->prices->firstWhere('period', $period) : $this->prices()->where('period', $period)->first();

        return $price && $price->is_active ? (int) $price->amount : null;
    }

    /** Límites del plan: [{key, name, unit, value|null}] (null = ilimitado). @return list<array<string, mixed>> */
    public function limitsList(array $overrides = []): array
    {
        return $this->limits->sortBy('sort_order')->map(fn (LimitType $l) => [
            'key' => $l->key, 'name' => $l->name, 'unit' => $l->unit,
            'value' => array_key_exists($l->key, $overrides) ? ($overrides[$l->key] === null ? null : (int) $overrides[$l->key]) : ($l->pivot->value === null ? null : (int) $l->pivot->value),
            'overridden' => array_key_exists($l->key, $overrides),
        ])->values()->all();
    }

    /** Valida y arma el catálogo público. @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->slug, 'name' => $this->name, 'tagline' => $this->tagline, 'description' => $this->description, 'features' => $this->features ?? [],
            'prices' => $this->prices->where('is_active', true)->mapWithKeys(fn (PlanPrice $p) => [$p->period => ['amount_net' => (int) $p->amount, 'currency' => 'CLP']])->all(),
            'limits' => collect($this->limitsList())->map(fn ($l) => ['key' => $l['key'], 'name' => $l['name'], 'unit' => $l['unit'], 'value' => $l['value'], 'unlimited' => $l['value'] === null])->all(),
        ];
    }
}
