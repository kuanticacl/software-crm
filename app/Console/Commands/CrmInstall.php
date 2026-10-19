<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;

class CrmInstall extends Command
{
    protected $signature = 'crm:install';

    protected $description = 'Carga los datos iniciales (roles, etapas, orígenes, servicios y administrador) solo si el CRM está vacío';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->info('El CRM ya tiene datos: no se carga nada.');

            return self::SUCCESS;
        }

        $this->call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
        $this->info('Datos iniciales cargados. Administrador: '.env('ADMIN_EMAIL', 'admin@mecaniq.cl'));

        return self::SUCCESS;
    }
}
