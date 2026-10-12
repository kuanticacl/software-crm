<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reservation_id', 'unit_id', 'type', 'code', 'model', 'list_price_uf', 'price_uf'])]
class ReservationUnit extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['list_price_uf' => 'float', 'price_uf' => 'float'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class)->withTrashed();
    }

    public function label(): string
    {
        return (Unit::TYPES[$this->type] ?? ucfirst($this->type)).' '.$this->code;
    }
}
