<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    /** Catálogo inicial de planes y servicios de MecaniQ (valores netos en CLP, referenciales; editables en Servicios). */
    public function run(): void
    {
        // MecaniQ vende suscripciones (Planes y límites), no un catálogo de servicios: aquí no se carga nada.
    }
}
