<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Boletines programados: requiere el cron de Laravel (* * * * * php artisan schedule:run).
Schedule::command('campaigns:dispatch-due')->everyMinute()->withoutOverlapping();
Schedule::command('leads:rescore --open')->dailyAt('03:30')->withoutOverlapping();

// UF diaria (findic.cl): se publica de madrugada; el segundo intento cubre caídas de la API.
Schedule::command('uf:sync')->dailyAt('00:10')->withoutOverlapping();
Schedule::command('uf:sync')->dailyAt('08:30')->withoutOverlapping();

// Cobranza de suscripciones: emite renovaciones, marca atrasos y envía recordatorios por días (Email → Automatizaciones).
Schedule::command('billing:run')->dailyAt('08:00')->timezone('America/Santiago')->withoutOverlapping();
