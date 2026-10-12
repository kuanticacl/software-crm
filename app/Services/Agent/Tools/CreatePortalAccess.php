<?php

namespace App\Services\Agent\Tools;

use App\Models\Client;
use App\Services\Billing\PortalAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Ai\Tools\Request;

class CreatePortalAccess extends CrmTool
{
    protected ?string $permission = 'portal.manage';

    public function name(): string
    {
        return 'create_portal_access';
    }

    public function description(): string
    {
        return 'Da acceso al PORTAL DE CLIENTES a un contacto de un cliente: genera la contraseña y le envía por correo sus datos de ingreso. Es una acción hacia el cliente: confirma el nombre y correo con el usuario antes. Nunca muestres la contraseña (solo va por correo).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->required()->description('Id del cliente.'),
            'name' => $schema->string()->required(),
            'email' => $schema->string()->required(),
            'phone' => $schema->string(),
        ];
    }

    protected function run(Request $r): array|string
    {
        $d = $r->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')], 'phone' => ['nullable', 'string', 'max:40'],
        ]);

        $res = app(PortalAccess::class)->create(Client::findOrFail($d['client_id']), ['name' => $d['name'], 'email' => $d['email'], 'phone' => $d['phone'] ?? null]);
        $this->ctx->record($this->name(), "Creó el acceso al portal de {$d['name']}", url('/clients/'.$d['client_id']));

        return $res['mailed'] ? "OK: acceso creado y correo enviado a {$d['email']}." : 'Acceso creado, pero el correo no pudo enviarse: pídele al usuario usar «Reenviar acceso» en la ficha del cliente.';
    }
}
