<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    /** Catálogo inicial de planes y servicios de MecaniQ (valores netos en CLP, referenciales; editables en Servicios). */
    public function run(): void
    {
        if (Service::count() > 0) {
            return;
        }

        $catalog = [
            ['Planes MecaniQ', 'Plan Taller Inicial', 'Para talleres pequeños que quieren dejar el cuaderno: órdenes de trabajo, clientes y vehículos en el celular.', ['1 sucursal y hasta 3 usuarios', 'Órdenes de trabajo y cotizaciones', 'Ficha de cliente y vehículo con historial', 'Avisos por WhatsApp y correo', 'Soporte por chat'], 'monthly', 'mes', 19990],
            ['Planes MecaniQ', 'Plan Taller Pro', 'Para talleres en crecimiento: inventario de repuestos, cobros y reportes de gestión.', ['1 sucursal y hasta 10 usuarios', 'Todo lo del plan Inicial', 'Inventario y control de repuestos', 'Cobros, abonos y cuentas por cobrar', 'Recordatorios de mantención', 'Reportes de ventas y productividad'], 'monthly', 'mes', 39990],
            ['Planes MecaniQ', 'Plan Multi-sucursal', 'Para redes de talleres y flotas: varias sucursales con control central.', ['Sucursales y usuarios a convenir', 'Todo lo del plan Pro', 'Panel consolidado por sucursal', 'Roles y permisos avanzados', 'Soporte prioritario'], 'monthly', 'mes', 89990],
            ['Implementación', 'Puesta en marcha y capacitación', 'Configuración inicial del taller, carga de clientes y vehículos, y capacitación al equipo.', ['Configuración de la cuenta', 'Importación de clientes y vehículos', 'Capacitación al equipo (hasta 2 horas)', 'Acompañamiento la primera semana'], 'one_time', 'taller', 59990],
            ['Implementación', 'Integración con WhatsApp Business', 'Conexión del número del taller para enviar cotizaciones, avisos de vehículo listo y recordatorios.', ['Configuración del número', 'Plantillas de mensajes', 'Pruebas y capacitación'], 'one_time', 'taller', 49990],
            ['Marketing', 'Landing page del taller', 'Página web simple para que el taller reciba reservas y consultas, conectada a MecaniQ.', ['Diseño con identidad del taller', 'Formulario de reserva', 'Botón de WhatsApp y mapa', 'Publicación en dominio propio'], 'one_time', 'sitio', 149990],
        ];

        foreach ($catalog as $i => [$category, $name, $description, $deliverables, $billing, $unit, $price]) {
            Service::create(compact('category', 'name', 'description', 'deliverables', 'billing', 'unit', 'price') + ['currency' => 'CLP', 'sort_order' => $i]);
        }
    }
}
