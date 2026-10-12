<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/** Unidad del inventario: departamento, casa, estacionamiento, bodega, quincho… con precio de lista en UF. */
#[Fillable([
    'project_id', 'type', 'code', 'tower', 'model', 'floor', 'orientation', 'bedrooms', 'bathrooms', 'program',
    'surface_useful', 'surface_terrace', 'surface_total', 'surface_lot', 'list_price', 'status', 'floor_plan_path', 'features', 'notes',
])]
class Unit extends Model
{
    use SoftDeletes;

    public const TYPES = [
        'departamento' => 'Departamento',
        'casa' => 'Casa',
        'oficina' => 'Oficina',
        'local' => 'Local comercial',
        'estacionamiento' => 'Estacionamiento',
        'bodega' => 'Bodega',
        'quincho' => 'Quincho',
        'otro' => 'Otro',
    ];

    /** Tipos «principales» (lo que se compra) y complementos que se suman a la cotización. */
    public const MAIN_TYPES = ['departamento', 'casa', 'oficina', 'local'];

    public const STATUSES = [
        'disponible' => 'Disponible',
        'bloqueada' => 'Bloqueada',
        'reservada' => 'Reservada',
        'promesada' => 'Promesada',
        'vendida' => 'Vendida',
    ];

    public const STATUS_COLORS = [
        'disponible' => '#12B76A', 'bloqueada' => '#98A2B3', 'reservada' => '#F79009', 'promesada' => '#6419DB', 'vendida' => '#344054',
    ];

    public const ORIENTATIONS = ['N', 'NE', 'E', 'SE', 'S', 'SO', 'O', 'NO', 'NO-NE', 'SO-SE', 'Nor-Oriente', 'Nor-Poniente', 'Sur-Oriente', 'Sur-Poniente', 'Oriente', 'Poniente'];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'list_price' => 'float', 'surface_useful' => 'float', 'surface_terrace' => 'float', 'surface_total' => 'float', 'surface_lot' => 'float',
            'floor' => 'integer', 'bedrooms' => 'integer', 'bathrooms' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function isMain(): bool
    {
        return in_array($this->type, self::MAIN_TYPES, true);
    }

    public function scopeAvailable(Builder $q): Builder
    {
        return $q->where('status', 'disponible');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    /** «Departamento 1204», «Estacionamiento E-15». */
    public function label(): string
    {
        return $this->typeLabel().' '.$this->code;
    }

    /** Programa visible: el escrito a mano o «3D + 2B» a partir de dormitorios y baños. */
    public function programLabel(): ?string
    {
        if ($this->program) {
            return $this->program;
        }
        if ($this->bedrooms === null && $this->bathrooms === null) {
            return null;
        }

        return trim(($this->bedrooms !== null ? $this->bedrooms.'D' : '').($this->bathrooms !== null ? ' + '.$this->bathrooms.'B' : ''), ' +');
    }

    /** Resumen para cotizaciones: programa, modelo, piso y superficies. */
    public function detail(): string
    {
        $m2 = fn (?float $v) => $v ? rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',').' m²' : null;

        return collect([
            $this->programLabel(),
            $this->model ? 'Modelo '.$this->model : null,
            $this->floor !== null ? 'Piso '.$this->floor : null,
            $this->orientation ? 'Orientación '.$this->orientation : null,
            $this->surface_useful ? 'Útil '.$m2($this->surface_useful) : null,
            $this->surface_terrace ? 'Terraza '.$m2($this->surface_terrace) : null,
            $this->surface_total ? 'Total '.$m2($this->surface_total) : null,
            $this->surface_lot ? 'Terreno '.$m2($this->surface_lot) : null,
        ])->filter()->implode(' · ');
    }

    public function floorPlanUrl(): ?string
    {
        return $this->floor_plan_path && Storage::disk('public')->exists($this->floor_plan_path) ? '/storage/'.$this->floor_plan_path : null;
    }

    public function floorPlanPath(): ?string
    {
        return $this->floor_plan_path && Storage::disk('public')->exists($this->floor_plan_path) ? Storage::disk('public')->path($this->floor_plan_path) : null;
    }
}
