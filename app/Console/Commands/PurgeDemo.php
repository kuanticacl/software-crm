<?php

namespace App\Console\Commands;

use App\Services\DemoPurge;
use Illuminate\Console\Command;

class PurgeDemo extends Command
{
    protected $signature = 'crm:purge-demo {--all : Borrar TODOS los prospectos, clientes y propuestas (no solo los de muestra)} {--force : Ejecutar de verdad (sin esto solo muestra qué se borraría)}';

    protected $description = 'Limpia los datos de prueba para empezar a usar el CRM en real, sin tocar la configuración';

    public function handle(DemoPurge $purge): int
    {
        $mode = $this->option('all') ? 'all' : 'demo';
        $p = $purge->preview($mode);
        $this->table(['leads', 'clientes', 'propuestas', 'correos', 'usuarios demo'], [array_values($p)]);

        if (! $this->option('force')) {
            $this->warn('Simulación: no se borró nada. Agrega --force para ejecutar.');

            return self::SUCCESS;
        }

        $done = $purge->purge($mode);
        $this->info('Listo: '.collect($done)->map(fn ($n, $k) => "$n $k")->implode(', ').'.');

        return self::SUCCESS;
    }
}
