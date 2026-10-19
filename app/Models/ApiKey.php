<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['name', 'prefix', 'key_hash', 'abilities', 'is_active', 'last_used_at', 'last_used_ip', 'created_by'])]
#[Hidden(['key_hash'])]
class ApiKey extends Model
{
    /** Permisos que se pueden otorgar a una API key. */
    public const ABILITIES = [
        'emails.send' => 'Enviar emails con plantillas (POST /api/v1/emails/send)',
        'emails.read' => 'Consultar estado de envíos (GET /api/v1/emails/{id})',
        'leads.create' => 'Crear leads (POST /api/v1/leads)',
        'billing.read' => 'Leer planes, suscripción, límites, cobros y saldo de un cliente (GET /api/v1/customers/{customer_id}/…)',
        'billing.write' => 'Registrar pagos de cobros desde la app o una pasarela (POST /api/v1/customers/{customer_id}/invoices/{n}/payments)',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /** Genera una key nueva. Devuelve [modelo, key en claro]; la key en claro se muestra una sola vez. */
    public static function issue(string $name, array $abilities, ?int $userId = null): array
    {
        $plain = 'qbk_'.Str::random(40);

        $key = static::create([
            'name' => $name,
            'prefix' => substr($plain, 0, 10),
            'key_hash' => hash('sha256', $plain),
            'abilities' => array_values($abilities),
            'created_by' => $userId,
        ]);

        return [$key, $plain];
    }

    public static function findByPlain(string $plain): ?self
    {
        return static::where('key_hash', hash('sha256', $plain))->first();
    }

    public function can(string $ability): bool
    {
        return $this->is_active && in_array($ability, $this->abilities ?? [], true);
    }
}
