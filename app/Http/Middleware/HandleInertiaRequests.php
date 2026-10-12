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
                ] : null,
                'permissions' => $user?->permissionKeys() ?? [],
            ],
            // UF de hoy (para convertir UF ↔ CLP en formularios); en caché para no consultar la base en cada página.
            'uf' => fn () => $user ? \Illuminate\Support\Facades\Cache::remember('uf.today.shared', 600, fn () => app(\App\Services\UfService::class)->today()) : null,
            'brand' => fn () => ['hero' => \App\Support\Theme::heroUrl()],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
