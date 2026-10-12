<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['quote_id', 'unit_id', 'type', 'code', 'model', 'detail', 'list_price_uf', 'discount_pct', 'price_uf', 'sort_order'])]
class QuoteItem extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['list_price_uf' => 'float', 'discount_pct' => 'float', 'price_uf' => 'float'];
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
