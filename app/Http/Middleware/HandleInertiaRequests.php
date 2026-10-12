<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user()?->loadMissing('role');

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? [
                    ...$user->toArray(),
                    'role' => $user->role?->only(['id', 'name', 'slug']),
                    'is_portal' => $user->isPortal(),
                ] : null,
                'permissions' => $user?->permissionKeys() ?? [],
            ],
            // UF de hoy (para convertir UF ↔ CLP en formularios); en caché para no consultar la base en cada página.
            'retention_rate' => fn () => $user ? \App\Support\Honorarios::rate() : null,
            'uf' => fn () => $user ? \Illuminate\Support\Facades\Cache::remember('uf.today.shared', 600, fn () => app(\App\Services\UfService::class)->today()) : null,
            // Documento e impuesto por defecto de los cobros nuevos (Datos de mi empresa → Documento tributario).
            'billing_defaults' => fn () => $user ? \App\Support\TaxDocument::invoiceDefaults() : null,
            // ¿Hay un proveedor de IA utilizable? (lectura de facturas en cualquier formulario de cobro). Solo para quien puede usar IA.
            'ai_available' => fn () => $user && $user->hasPermission('ai.use')
                ? \Illuminate\Support\Facades\Cache::remember('ai.available', 60, fn () => app(\App\Services\Ai\AiGateway::class)->isAvailable())
                : false,
            // Vista previa del portal (equipo viendo como el cliente).
            'portal_preview' => fn () => $user && ! $user->isPortal() && ($pv = $request->session()->get('portal_preview'))
                ? ['client' => \App\Models\Client::withTrashed()->whereKey($pv['client_id'])->value('name'), 'user' => $pv['user_id'] ? \App\Models\User::whereKey($pv['user_id'])->value('name') : null]
                : null,
            'brand' => fn () => ['hero' => \App\Support\Theme::heroUrl()],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
