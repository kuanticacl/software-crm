<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Proyecto inmobiliario: inventario de unidades, ejecutivos asignados y condiciones comerciales por defecto. */
#[Fillable([
    'name', 'slug', 'developer', 'commune', 'city', 'address', 'sales_office', 'description', 'stage', 'delivery_date', 'delivery_note',
    'cover_path', 'website_url', 'brochure_url', 'color', 'reservation_amount', 'default_pie_pct', 'default_pie_installments', 'max_discount_pct',
    'mortgage_rate', 'mortgage_years', 'quote_validity_days', 'promise_days', 'quote_conditions', 'is_active', 'sort_order',
])]
class Project extends Model
{
    use SoftDeletes;

    public const STAGES = [
        'en_blanco' => 'En blanco',
        'en_verde' => 'En verde',
        'entrega_inmediata' => 'Entrega inmediata',
        'terminado' => 'Terminado',
    ];

    protected function casts(): array
    {
        return [
            'delivery_date' => 'date',
            'reservation_amount' => 'float', 'default_pie_pct' => 'float', 'max_discount_pct' => 'float', 'mortgage_rate' => 'float',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Project $p) {
            if (! $p->slug || $p->isDirty('name')) {
                $base = Str::slug($p->name) ?: 'proyecto';
                $slug = $base;
                $n = 1;
                while (static::withTrashed()->where('slug', $slug)->whereKeyNot($p->id)->exists()) {
                    $slug = $base.'-'.(++$n);
                }
                $p->slug = $slug;
            }
        });
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function executives(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('is_lead');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /** Quien no tiene «projects.view_all» solo ve los proyectos donde es ejecutivo. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->hasPermission('projects.view_all')
            ? $query
            : $query->whereHas('executives', fn ($q) => $q->whereKey($user->id));
    }

    public function coverUrl(): ?string
    {
        return $this->cover_path && Storage::disk('public')->exists($this->cover_path) ? '/storage/'.$this->cover_path : null;
    }

    public function location(): string
    {
        return collect([$this->commune, $this->city])->filter()->implode(', ');
    }
}
