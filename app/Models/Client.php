<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['kind', 'name', 'legal_name', 'activity', 'tax_id', 'email', 'phone', 'website', 'address', 'commune', 'city', 'contact_name', 'contact_role', 'notes', 'is_active', 'created_by'])]
class Client extends Model
{
    use SoftDeletes;

    public const KINDS = ['person' => 'Persona', 'company' => 'Empresa'];

    public function isCompany(): bool
    {
        return $this->kind === 'company';
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class)->latest('id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class)->latest('id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(ClientService::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** Accesos al portal de clientes de esta empresa. */
    public function portalUsers(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
