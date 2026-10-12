<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Services\Billing\PortalAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Administra los accesos de un cliente al portal de clientes: crear con contraseña generada y correo de bienvenida, reenviar, desactivar. */
class PortalAccessController extends Controller
{
    public function __construct(private PortalAccess $access) {}

    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:40'],
            'lead_id' => ['nullable', 'integer', Rule::exists('leads', 'id')->where('client_id', $client->id)],
        ], ['email.unique' => 'Ya existe un usuario con ese correo.']);

        $r = $this->access->create($client, $data);

        return $this->done($r['mailed'], "Acceso creado para {$r['user']->email}.", $r['user']->email);
    }

    /** Genera una contraseña nueva, la envía por correo y obliga a cambiarla al ingresar. */
    public function reset(User $user): RedirectResponse
    {
        abort_unless($user->isPortal(), 404);

        return $this->done($this->access->reset($user), 'Contraseña nueva generada.', $user->email);
    }

    /** Abre el portal tal como lo ve el cliente (solo lectura), opcionalmente como un acceso concreto. */
    public function preview(Request $request, Client $client): RedirectResponse
    {
        $userId = $request->integer('user_id') ?: null;
        abort_if($userId && ! $client->portalUsers()->whereKey($userId)->exists(), 404);

        $request->session()->put('portal_preview', ['client_id' => $client->id, 'user_id' => $userId, 'return' => '/clients/'.$client->id]);

        return redirect('/portal');
    }

    public function exitPreview(Request $request): RedirectResponse
    {
        $return = $request->session()->pull('portal_preview')['return'] ?? '/clients';

        return redirect($return);
    }

    public function toggle(User $user): RedirectResponse
    {
        abort_unless($user->isPortal(), 404);

        $user->forceFill(['is_active' => ! $user->is_active])->save();
        $this->toast($user->is_active ? 'Acceso reactivado.' : 'Acceso desactivado.');

        return back();
    }

    public function destroy(User $user): RedirectResponse
    {
        abort_unless($user->isPortal(), 404);

        $user->delete();
        $this->toast('Acceso eliminado.');

        return back();
    }

    private function done(bool $mailed, string $done, string $email): RedirectResponse
    {
        $mailed
            ? $this->toast("{$done} Enviamos un correo con los datos de ingreso a {$email}.")
            : $this->toast("{$done} Pero no pudimos enviar el correo: revisa la configuración de email y usa «Reenviar acceso».", 'error');

        return back();
    }
}
