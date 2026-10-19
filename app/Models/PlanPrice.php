<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['plan_id', 'period', 'amount', 'is_active'])]
class PlanPrice extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'integer', 'is_active' => 'boolean'];
    }
}
