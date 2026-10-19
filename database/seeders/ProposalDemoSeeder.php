<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\PipelineStage;
use App\Models\Proposal;
use App\Models\User;
use App\Services\LeadService;
use App\Services\Proposals\ProposalBuilder;
use App\Services\UfService;
use App\Support\Rut;
use Illuminate\Database\Seeder;

/**
 * Datos de muestra para ver el CRM «con vida»: un cliente (inmobiliaria ficticia) por cada origen, con su lead
 * y propuestas en distintos estados (borrador, enviada, vista, aceptada, rechazada, vencida y varias versiones).
 * Ejecutar: php artisan crm:seed-demo  (es idempotente: no duplica si ya se cargó).
 */
class ProposalDemoSeeder extends Seeder
{
    /** @return array<string, array<string, mixed>> por nombre de origen */
    private function scenarios(): array
    {
        return [
            'Sitio web' => ['company' => 'Taller Los Robles', 'legal' => 'Taller Mecánico Los Robles SpA', 'body' => 76511230, 'activity' => 'Mecánica automotriz general', 'address' => 'Av. Vicuña Mackenna 4501', 'commune' => 'Ñuñoa', 'contact' => ['Camila', 'Rojas', 'Dueña del taller'], 'brief' => 'Quiere dejar el cuaderno y llevar las órdenes de trabajo en el celular.', 'plan' => [['Plan Taller Inicial', 1, 0], ['Puesta en marcha y capacitación', 1, 0]], 'states' => ['draft']],
            'Landing page' => ['company' => 'Frenos del Sur', 'legal' => 'Frenos y Suspensión del Sur Ltda.', 'body' => 77124560, 'activity' => 'Frenos, suspensión y alineación', 'address' => 'Av. Libertad 1350', 'commune' => 'Viña del Mar', 'contact' => ['Matías', 'Soto', 'Jefe de taller'], 'brief' => 'Necesita cotizar más rápido y avisar a los clientes cuando el auto está listo.', 'plan' => [['Plan Taller Pro', 1, 0], ['Integración con WhatsApp Business', 1, 0]], 'states' => ['sent']],
            'Meta Ads' => ['company' => 'AutoPlancha Plaza', 'legal' => 'Planchado y Pintura Plaza S.A.', 'body' => 76890340, 'activity' => 'Planchado y pintura', 'address' => 'Av. Providencia 2653', 'commune' => 'Providencia', 'contact' => ['Francisca', 'Mora', 'Gerenta'], 'brief' => 'Controlar repuestos y cobros por siniestro con las aseguradoras.', 'plan' => [['Plan Taller Pro', 1, 0], ['Puesta en marcha y capacitación', 1, 0]], 'states' => ['viewed']],
            'Google Ads' => ['company' => 'Red Mecánica Pacífico', 'legal' => 'Red de Talleres Pacífico SpA', 'body' => 77350120, 'activity' => 'Red de 4 talleres multimarca', 'address' => 'Av. Andrés Bello 2425', 'commune' => 'Providencia', 'contact' => ['Rodrigo', 'Vega', 'Director de operaciones'], 'brief' => 'Quiere ver todas las sucursales en un solo panel.', 'plan' => [['Plan Multi-sucursal', 1, 0], ['Puesta en marcha y capacitación', 4, 0]], 'states' => ['rejected', 'sent']],
            'WhatsApp' => ['company' => 'Neumáticos Nodo', 'legal' => 'Nodo Neumáticos SpA', 'body' => 76673450, 'activity' => 'Venta e instalación de neumáticos', 'address' => 'Av. Nueva Costanera 3880', 'commune' => 'Vitacura', 'contact' => ['Daniela', 'Fuentes', 'Jefa de local'], 'brief' => 'Recordatorios de rotación y una página para reservar horas.', 'plan' => [['Plan Taller Inicial', 1, 0], ['Landing page del taller', 1, 0]], 'states' => ['accepted']],
            'Referido' => ['company' => 'Mecánica Alameda', 'legal' => 'Mecánica Alameda SpA', 'body' => 76244780, 'activity' => 'Mecánica y electricidad automotriz', 'address' => 'Av. Libertador Bernardo O\'Higgins 1302', 'commune' => 'Santiago', 'contact' => ['Tomás', 'Herrera', 'Socio y gerente'], 'brief' => 'Ordenar el historial de cada vehículo y las mantenciones.', 'plan' => [['Plan Taller Pro', 1, 0]], 'states' => ['sent']],
            'Manual' => ['company' => 'Taller Cumbres', 'legal' => 'Taller Cumbres Ltda.', 'body' => 77019340, 'activity' => 'Mecánica general y flotas', 'address' => 'Av. Alemania 0671', 'commune' => 'Temuco', 'contact' => ['Javier', 'Contreras', 'Gerente de operaciones'], 'brief' => 'Gestionar la flota de un cliente corporativo con órdenes de trabajo.', 'plan' => [['Plan Taller Pro', 1, 0], ['Integración con WhatsApp Business', 1, 0]], 'states' => ['draft']],
        ];
    }

    public function run(LeadService $leads, ProposalBuilder $builder, UfService $uf): void
    {
        $owner = User::orderBy('id')->first();
        $plans = \App\Models\Plan::all();
        $stages = PipelineStage::all()->keyBy('name');
        $sources = LeadSource::all()->keyBy('name');

        foreach ($this->scenarios() as $sourceName => $c) {
            $source = $sources->get($sourceName);
            $rut = Rut::format($c['body'].Rut::dv((string) $c['body']));
            if (! $source || Client::where('tax_id', $rut)->exists()) {
                continue;
            }

            [$first, $last, $role] = $c['contact'];
            $slug = strtolower(preg_replace('/[^a-z]/i', '', iconv('UTF-8', 'ASCII//TRANSLIT', $first)));
            $email = $slug.'@ejemplo.cl';

            $client = Client::create([
                'name' => $c['company'], 'legal_name' => $c['legal'], 'tax_id' => $rut, 'activity' => $c['activity'],
                'address' => $c['address'], 'commune' => $c['commune'], 'city' => $c['commune'] === 'Temuco' ? 'Temuco' : ($c['commune'] === 'Viña del Mar' ? 'Viña del Mar' : 'Santiago'),
                'contact_name' => "$first $last", 'contact_role' => $role, 'email' => $email, 'phone' => '+569'.random_int(61000000, 99999999),
                'website' => 'https://www.ejemplo.cl', 'notes' => '<p>Cliente de <strong>demostración</strong> creado por el seed inicial.</p>', 'created_by' => $owner?->id,
            ]);

            $lead = $leads->create([
                'first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => $client->phone, 'job_title' => $role,
                'company' => $c['company'], 'message' => $c['brief'], 'source_id' => $source->id, 'client_id' => $client->id,
                'assigned_to' => $owner?->id, 'utm_source' => strtolower(str_replace(' ', '-', $sourceName)), 'utm_campaign' => 'demo',
            ], $owner);

            $proposals = [];
            foreach ($c['states'] as $v => $state) {
                $days = match ($state) { 'draft' => 0, 'sent' => 9, 'viewed' => 6, 'accepted' => 14, 'rejected' => $v === 0 ? 24 : 20, 'expired' => 40 };
                if (count($c['states']) > 1) {
                    $days = $v === 0 ? 24 : 8; // versión 1 antigua, versión 2 reciente
                }

                // Cada escenario cotiza un plan (el primero de su lista que exista en el catálogo de planes).
                $plan = collect($c['plan'])->map(fn ($row) => $plans->first(fn ($pl) => str_contains($row[0], $pl->name)))->filter()->first();
                $items = [];

                $p = $builder->save([
                    'title' => 'MecaniQ para tu taller · '.$c['company'].($v > 0 ? ' (v'.($v + 1).')' : ''),
                    'currency' => 'CLP', 'lead_id' => $lead->id, 'client_id' => $client->id,
                    'recipient' => ProposalBuilder::recipientFor($client, $lead),
                    'sections' => $this->sections($c),
                    'valid_until' => now()->subDays($days)->addDays(15)->toDateString(),
                    'contract_months' => null, 'discount_type' => 'percent', 'discount_value' => $state === 'accepted' ? 5 : 0, 'tax_mode' => 'iva', 'tax_rate' => 19,
                    'items' => $items, 'plan_id' => $plan?->id, 'plan_period' => ['monthly', 'quarterly', 'annual'][$v % 3], 'auto_renew' => true, 'start_date' => now()->toDateString(), 'internal_notes' => '<p>Propuesta de ejemplo del seed demo.</p>',
                ], null, $owner?->id);

                $sentAt = now()->subDays($days);
                $ufDay = $sentAt->toDateString();
                $attrs = ['created_at' => $sentAt->copy()->subDay(), 'issued_at' => $sentAt->toDateString(), 'uf_date' => $ufDay, 'uf_value' => $uf->forDate($ufDay) ?: ($uf->today()['value'] ?? 41000)];

                match ($state) {
                    'draft' => $attrs = [],
                    'sent', 'expired' => $attrs += ['status' => 'sent', 'sent_at' => $sentAt],
                    'viewed' => $attrs += ['status' => 'viewed', 'sent_at' => $sentAt, 'viewed_at' => $sentAt->copy()->addHours(5), 'view_count' => 3],
                    'accepted' => $attrs += ['status' => 'accepted', 'sent_at' => $sentAt, 'viewed_at' => $sentAt->copy()->addHours(3), 'view_count' => 4, 'responded_at' => $sentAt->copy()->addDays(2), 'responded_by' => "$first $last", 'response_note' => 'Conformes con la propuesta, podemos partir la próxima semana.'],
                    'rejected' => $attrs += ['status' => 'rejected', 'sent_at' => $sentAt, 'viewed_at' => $sentAt->copy()->addHours(8), 'view_count' => 2, 'responded_at' => $sentAt->copy()->addDays(3), 'responded_by' => "$first $last", 'response_note' => 'Por ahora lo vamos a postergar al próximo trimestre.'],
                };
                if ($attrs) {
                    $p->forceFill($attrs)->save();
                    $leads->log($lead, 'proposal', $owner, "Propuesta {$p->number} ".match ($state) { 'accepted' => 'aceptada', 'rejected' => 'rechazada', 'viewed' => 'vista por el cliente', default => 'enviada' }, ['proposal_id' => $p->id], $sentAt);
                }
                $proposals[] = [$state, $p];
            }

            // Etapa del lead según la última propuesta.
            $lastState = end($proposals)[0];
            $target = match ($lastState) { 'accepted' => 'Taller activo', 'rejected' => 'Descartado', 'draft' => 'Demo agendada', default => 'Propuesta' };
            if ($stage = $stages->get($target)) {
                $leads->move($lead->fresh(), $stage->id, null, $owner, [
                    'lost_reason' => $lastState === 'rejected' ? 'Postergó la decisión' : null,
                    'estimated_value' => $lastState === 'accepted' ? end($proposals)[1]->clp(end($proposals)[1]->total_net) : null,
                ]);
            }
            if (! in_array($lastState, ['accepted', 'rejected'], true)) {
                $p = end($proposals)[1];
                $lead->fresh()->forceFill(['estimated_value' => $p->clp($p->total_net)])->save();
            }
        }
    }

    /** @param array<string, mixed> $c @return array<int, array{title: string, body: string}> */
    private function sections(array $c): array
    {
        $s = ProposalBuilder::defaultSections();
        $s[0]['body'] = '<p>Gracias por la oportunidad de presentar esta propuesta a <strong>[CLIENTE]</strong>. '.$c['brief'].'</p><p>MecaniQ es la app móvil que ayuda a los talleres a ordenar sus órdenes de trabajo, cotizar rápido y cobrar a tiempo.</p>';

        return $s;
    }
}
