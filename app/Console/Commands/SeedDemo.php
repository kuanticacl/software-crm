<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Database\Seeders\ProposalDemoSeeder;
use Illuminate\Console\Command;

class SeedDemo extends Command
{
    protected $signature = 'crm:seed-demo {--force : Volver a cargar aunque ya se haya cargado}';

    protected $description = 'Carga datos de muestra: un cliente con su prospecto y propuestas por cada origen (idempotente)';

    public function handle(): int
    {
        if (Setting::get('demo.proposals_seeded') && ! $this->option('force')) {
            $this->info('Los datos de muestra ya se cargaron (usa --force para repetir).');

            return self::SUCCESS;
        }

        $this->call('db:seed', ['--class' => ProposalDemoSeeder::class, '--force' => true]);
        Setting::put('demo.proposals_seeded', '1');

        return self::SUCCESS;
    }
}
