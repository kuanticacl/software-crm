<?php

namespace App\Console\Commands;

use App\Services\Billing\BillingRunner;
use Illuminate\Console\Command;

class RunBilling extends Command
{
    protected $signature = 'billing:run';

    protected $description = 'Emite los cobros de renovación, avanza los períodos de suscripción y envía los correos de cobranza programados';

    public function handle(BillingRunner $runner): int
    {
        $r = $runner->run();
        $this->info("Cobros emitidos: {$r['issued']} · renovadas: {$r['renewed']} · finalizadas: {$r['expired']} · correos: {$r['emails']}");

        return self::SUCCESS;
    }
}
