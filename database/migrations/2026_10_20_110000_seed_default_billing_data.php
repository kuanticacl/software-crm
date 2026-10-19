<?php

use App\Models\Service;
use App\Services\Billing\BillingDefaults;
use Illuminate\Database\Migrations\Migration;

/** Planes, límites y automatizaciones de cobranza iniciales. Defensiva: un fallo aquí nunca debe impedir el despliegue. */
return new class extends Migration
{
    public function up(): void
    {
        try {
            BillingDefaults::install();
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            // MecaniQ vende suscripciones, no servicios: se retira el catálogo de servicios de ejemplo si nadie lo usó.
            Service::whereIn('category', ['Planes MecaniQ', 'Implementación', 'Marketing'])->whereNotIn('id', \App\Models\ProposalItem::whereNotNull('service_id')->pluck('service_id'))->get()->each->delete();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function down(): void {}
};
