<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'color', 'icon', 'api_key', 'is_active', 'is_system', 'sort_order', 'score_weight'])]
#[Hidden(['api_key'])]
class LeadSource extends Model
{
    use SoftDeletes;

    public const MANUAL_SLUG = 'manual';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'source_id');
    }

    /** Responsables del origen (uno o varios): reciben la alerta y se les asignan sus clientes. */
    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'lead_source_user')->withTimestamps();
    }

    public static function generateKey(): string
    {
        return 'qb_'.Str::random(40);
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'origen';
        $slug = $base;
        $i = 2;

        while (static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
