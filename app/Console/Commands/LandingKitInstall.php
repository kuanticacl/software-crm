<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Email\LandingKit;
use Illuminate\Console\Command;

class LandingKitInstall extends Command
{
    protected $signature = 'crm:landing-kit {--force : Repetir aunque ya se haya ejecutado}';

    protected $description = 'Crea (si faltan) los orígenes, audiencia, campos y plantillas para www.mecaniq.cl';

    public function handle(LandingKit $kit): int
    {
        // Solo la primera vez: así no se recrea lo que un admin eliminó o renombró en el CRM.
        if (! $this->option('force') && Setting::get('landing_kit.installed')) {
            $this->info('El kit ya se instaló antes (usa --force para repetirlo).');

            return self::SUCCESS;
        }

        $created = $kit->install();
        Setting::put('landing_kit.installed', now()->toDateTimeString());
        $created ? collect($created)->each(fn ($c) => $this->line("+ $c")) : $this->info('Nada que crear: el kit ya existe.');

        return self::SUCCESS;
    }
}
