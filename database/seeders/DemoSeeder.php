<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadField;
use App\Models\LeadSource;
use App\Models\PipelineStage;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadService;
use Illuminate\Database\Seeder;

/** Datos de ejemplo para probar el CRM: php artisan db:seed --class=DemoSeeder */
class DemoSeeder extends Seeder
{
    public function run(LeadService $service): void
    {
        mt_srand(7);

        $comercial = Role::where('slug', 'comercial')->value('id');
        $team = collect([['Camila Rojas', 'camila@viviendaenregla.cl'], ['Diego Fuentes', 'diego@viviendaenregla.cl'], ['Valentina Mora', 'valentina@viviendaenregla.cl']])
            ->map(fn ($u) => User::firstOrCreate(['email' => $u[1]], [
                'name' => $u[0], 'password' => 'password', 'role_id' => $comercial, 'email_verified_at' => now(), 'job_title' => 'Ejecutivo comercial',
            ]));

        LeadField::firstOrCreate(['key' => 'servicio_de_interes'], [
            'label' => 'Servicio de interés', 'type' => 'select', 'options' => ['Sitio web', 'App móvil', 'Automatización'],
            'show_on_card' => true, 'sort_order' => 0,
        ]);

        $clients = collect(['Logística Andes', 'Tienda Verde', 'Clínica Vista Norte', 'Nodo Educa', 'Constructora Pacífico'])
            ->map(fn ($n) => Client::firstOrCreate(['name' => $n], ['email' => 'contacto@'.strtolower(str_replace(' ', '', $n)).'.cl', 'phone' => '+5622'.mt_rand(1000000, 9999999), 'city' => 'Santiago']));

        $sources = LeadSource::where('slug', '!=', LeadSource::MANUAL_SLUG)->get();
        $stages = PipelineStage::orderBy('sort_order')->get();
        $names = [['Sofía', 'Araya'], ['Matías', 'Soto'], ['Javiera', 'Muñoz'], ['Felipe', 'Reyes'], ['Constanza', 'Díaz'], ['Nicolás', 'Pizarro'], ['Francisca', 'Vera'], ['Tomás', 'Contreras'], ['Antonia', 'Silva'], ['Benjamín', 'Rojas']];
        $utms = [['google', 'cpc', 'proyecto-otono'], ['facebook', 'paid_social', 'leads-verano'], ['instagram', 'story', 'torre-norte'], ['newsletter', 'email', 'oct-2026'], [null, null, null]];

        foreach (range(1, 48) as $i) {
            [$first, $last] = $names[array_rand($names)];
            $source = $sources->random();
            $utm = $utms[array_rand($utms)];

            $lead = $service->create([
                'first_name' => $first, 'last_name' => $last,
                'email' => strtolower(\Str::ascii($first.'.'.$last)).$i.'@correo.cl',
                'phone' => '+569'.mt_rand(10000000, 99999999),
                'company' => mt_rand(0, 1) ? $clients->random()->name : null,
                'job_title' => ['Gerente comercial', 'Gerente de operaciones', 'Jefe de TI', 'Fundador'][mt_rand(0, 3)],
                'message' => 'Quiero cotizar el desarrollo de una plataforma web.',
                'client_id' => mt_rand(0, 3) === 0 ? $clients->random()->id : null,
                'source_id' => $source->id,
                'assigned_to' => mt_rand(0, 4) === 0 ? null : $team->random()->id,
                'utm_source' => $utm[0], 'utm_medium' => $utm[1], 'utm_campaign' => $utm[2],
                'ip_address' => mt_rand(180, 190).'.'.mt_rand(1, 250).'.'.mt_rand(1, 250).'.'.mt_rand(1, 250),
                'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
                'country' => 'Chile', 'region' => 'Región Metropolitana', 'city' => ['Santiago', 'Providencia', 'Las Condes', 'Ñuñoa'][mt_rand(0, 3)],
                'landing_url' => 'https://www.ejemplo.cl/contacto',
                'priority' => ['low', 'normal', 'normal', 'normal', 'high', 'urgent'][mt_rand(0, 5)],
                'estimated_value' => mt_rand(0, 2) ? mt_rand(8, 120) * 100000 : null,
                'tags' => [[], ['inversionista'], ['proyecto-norte', 'caliente'], ['referido'], ['licitación']][mt_rand(0, 4)] ?: null,
                'next_follow_up_at' => [null, now()->subDays(mt_rand(1, 5)), now()->addHours(mt_rand(1, 20)), now()->addDays(mt_rand(2, 9))][mt_rand(0, 3)],
                'custom' => mt_rand(0, 1) ? ['servicio_de_interes' => ['Sitio web', 'App móvil', 'Automatización'][mt_rand(0, 2)]] : null,
            ]);

            $stage = $stages->random();
            if ($stage->id !== $lead->stage_id) {
                $service->move($lead, $stage->id);
            }
            Lead::whereKey($lead->id)->update(['stage_changed_at' => now()->subDays(mt_rand(0, 14)), 'created_at' => now()->subDays(mt_rand(0, 60))->subMinutes(mt_rand(0, 900))]);
        }
    }
}
