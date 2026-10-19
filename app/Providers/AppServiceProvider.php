<?php

namespace App\Providers;

use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();

        RateLimiter::for('email-api', fn (Request $request) => Limit::perMinute(60)->by(($request->bearerToken() ?: $request->header('X-Api-Key')) ?: $request->ip()));
        // Alfred: por visitante (IP que informa el sitio) y un tope global por origen para acotar el gasto de IA.
        RateLimiter::for('alfred-chat', fn (Request $request) => [
            Limit::perMinute(10)->by('alfred:'.$request->header('X-Api-Key').'|'.($request->input('ip') ?: $request->ip())),
            Limit::perDay(150)->by('alfred-day:'.$request->header('X-Api-Key').'|'.($request->input('ip') ?: $request->ip())),
            Limit::perDay(3000)->by('alfred-all:'.$request->header('X-Api-Key')),
        ]);
        RateLimiter::for('billing-api', fn (Request $request) => Limit::perMinute(120)->by(($request->bearerToken() ?: $request->header('X-Api-Key')) ?: $request->ip()));
        RateLimiter::for('lead-ingest', fn (Request $request) => Limit::perMinute(120)->by($request->header('X-Api-Key') ?: $request->ip()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Cada permiso de config/permissions.php se registra como Gate, de modo que
     * funciona con $user->can(), @can, authorize() y el middleware "can:".
     */
    protected function configureAuthorization(): void
    {
        foreach (Role::allPermissionKeys() as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
    }
}
