<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\PipelineStage;
use App\Models\Proposal;
use App\Models\Service;
use App\Models\User;
use App\Services\LeadService;
use App\Services\Proposals\ProposalBuilder;
use App\Services\UfService;
use App\Support\Rut;
use Illuminate\Database\Seeder;

/**
 * Datos de muestra para ver el CRM «con vida»: un cliente (empresa ficticia) por cada origen, con su lead
 * y propuestas en distintos estados (borrador, enviada, vista, aceptada, rechazada, vencida y varias versiones).
 * Ejecutar: php artisan crm:seed-demo  (es idempotente: no duplica si ya se cargó).
 */
class ProposalDemoSeeder extends Seeder
{
    /** @return array<string, array<string, mixed>> por nombre de origen */
    private function scenarios(): array
    {
        return [
            'Sitio web' => ['company' => 'Logística Andes', 'legal' => 'Logística Andes SpA', 'body' => 76511230, 'activity' => 'Transporte y distribución de carga', 'address' => 'Av. Apoquindo 4501, of. 902', 'commune' => 'Las Condes', 'contact' => ['Camila', 'Rojas', 'Gerente de Operaciones'], 'brief' => 'Plataforma web para que sus clientes coticen y sigan sus despachos en línea.', 'plan' => [['Plataforma web a medida', 1, 0], ['Integración de sistemas y APIs', 1, 0], ['Mantención web mensual', 1, 0]], 'states' => ['draft']],
            'Landing page' => ['company' => 'Clínica Vista Norte', 'legal' => 'Centro Médico Vista Norte Ltda.', 'body' => 77124560, 'activity' => 'Servicios de salud ambulatoria', 'address' => 'Av. Libertad 1350, of. 401', 'commune' => 'Viña del Mar', 'contact' => ['Matías', 'Soto', 'Gerente Comercial'], 'brief' => 'Agendamiento de horas en línea y recordatorios automáticos por WhatsApp.', 'plan' => [['Sitio web corporativo', 1, 0], ['Agente de IA y chatbot WhatsApp', 1, 10], ['Automatización de procesos', 1, 0]], 'states' => ['sent']],
            'Meta Ads' => ['company' => 'Tienda Verde', 'legal' => 'Comercial Tienda Verde S.A.', 'body' => 76890340, 'activity' => 'Comercio minorista de productos sustentables', 'address' => 'Av. Providencia 2653, piso 11', 'commune' => 'Providencia', 'contact' => ['Francisca', 'Mora', 'Subgerente de Ventas'], 'brief' => 'Lanzar su tienda online con pagos en línea y gestión de inventario.', 'plan' => [['Tienda online (e-commerce)', 1, 0], ['Mantención web mensual', 1, 0], ['Dashboard y reportería', 1, 0]], 'states' => ['viewed']],
            'Google Ads' => ['company' => 'Constructora Pacífico', 'legal' => 'Constructora Pacífico SpA', 'body' => 77350120, 'activity' => 'Construcción y obras civiles', 'address' => 'Av. Andrés Bello 2425, of. 1503', 'commune' => 'Providencia', 'contact' => ['Rodrigo', 'Vega', 'Director de Proyectos'], 'brief' => 'App móvil para inspecciones en terreno con modo sin conexión.', 'plan' => [['App móvil iOS y Android', 1, 0], ['Soporte y evolución de plataforma', 1, 0]], 'states' => ['accepted']],
            'WhatsApp' => ['company' => 'Nodo Educa', 'legal' => 'Nodo Educa SpA', 'body' => 76673450, 'activity' => 'Capacitación y educación en línea', 'address' => 'Av. Nueva Costanera 3880, of. 62', 'commune' => 'Vitacura', 'contact' => ['Daniela', 'Fuentes', 'Jefa de Producto'], 'brief' => 'Automatizar matrículas y la comunicación con alumnos.', 'plan' => [['Automatización de procesos', 1, 0], ['Asistente interno con IA', 1, 0]], 'states' => ['rejected']],
            'Referido' => ['company' => 'Alameda Capital', 'legal' => 'Alameda Capital Servicios SpA', 'body' => 76244780, 'activity' => 'Servicios financieros y asesoría de inversiones', 'address' => 'Av. Libertador Bernardo O\'Higgins 1302, of. 703', 'commune' => 'Santiago', 'contact' => ['Tomás', 'Herrera', 'Socio y Gerente General'], 'brief' => 'Dashboard de cartera para clientes y reportes automáticos mensuales.', 'plan' => [['Dashboard y reportería', 1, 0], ['Plataforma web a medida', 1, 0]], 'states' => ['rejected', 'sent']],
            'Manual' => ['company' => 'Cumbres del Sur', 'legal' => 'Cumbres del Sur Ltda.', 'body' => 77019340, 'activity' => 'Turismo y hospedaje', 'address' => 'Av. Alemania 0671, of. 210', 'commune' => 'Temuco', 'contact' => ['Javier', 'Contreras', 'Gerente de Operaciones'], 'brief' => 'Sistema de reservas propio e integración con sus canales de venta.', 'plan' => [['Sitio web corporativo', 1, 0], ['Integración de sistemas y APIs', 1, 0]], 'states' => ['expired']],
        ];
    }

    public function run(LeadService $leads, ProposalBuilder $builder, UfService $uf): void
    {
        $owner = User::orderBy('id')->first();
        $services = Service::all()->keyBy('name');
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

                $items = collect($c['plan'])->map(function ($row) use ($services) {
                    $svc = $services->get($row[0]);

                    return $svc ? ['service_id' => $svc->id, 'name' => $svc->name, 'description' => $svc->description, 'deliverables' => $svc->deliverables, 'billing' => $svc->billing, 'unit' => $svc->unit, 'quantity' => $row[1], 'unit_price' => $svc->price, 'discount_pct' => $row[2]] : null;
                })->filter()->values()->all();

                $p = $builder->save([
                    'title' => 'Desarrollo de software · '.$c['company'].($v > 0 ? ' (v'.($v + 1).')' : ''),
                    'currency' => 'UF', 'lead_id' => $lead->id, 'client_id' => $client->id,
                    'recipient' => ProposalBuilder::recipientFor($client, $lead),
                    'sections' => $this->sections($c),
                    'valid_until' => now()->subDays($days)->addDays(15)->toDateString(),
                    'contract_months' => 6, 'discount_type' => 'percent', 'discount_value' => $state === 'accepted' ? 5 : 0, 'tax_mode' => 'honorarios', 'tax_rate' => \App\Support\Honorarios::rate(),
                    'items' => $items, 'internal_notes' => '<p>Propuesta de ejemplo del seed demo.</p>',
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
            $target = match ($lastState) { 'accepted' => 'Concretado', 'rejected' => 'Descartado', 'draft' => 'Agendado', default => 'Propuesta' };
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
        $s[0]['body'] = '<p>Gracias por la oportunidad de presentar esta propuesta a <strong>[CLIENTE]</strong>. '.$c['brief'].'</p><p>En Vivienda en Regla somos una software factory y agencia de growth marketing con más de 10 años de experiencia: diseñamos y construimos soluciones web, móviles, de automatización e inteligencia artificial, y activamos estrategias de crecimiento (campañas, SEO, email marketing y analítica) a la medida de cada negocio.</p>';

        return $s;
    }
}
