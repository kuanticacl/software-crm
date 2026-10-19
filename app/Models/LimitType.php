<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Tipo de límite que se puede fijar en los planes (usuarios, sucursales, órdenes de trabajo al mes…). */
#[Fillable(['key', 'name', 'unit', 'description', 'sort_order'])]
class LimitType extends Model {}
