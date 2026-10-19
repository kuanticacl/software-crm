<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['customer_id', 'name', 'legal_name', 'activity', 'tax_id', 'email', 'phone', 'website', 'address', 'commune', 'city', 'contact_name', 'contact_role', 'notes', 'is_active', 'created_by'])]
class Client extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Clave pública del cliente para integrar la app móvil y el sitio (no se reutiliza ni cambia).
        static::creating(fn (Client $c) => $c->customer_id ??= 'cus_'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(16)));
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
