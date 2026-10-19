<?php

namespace App\Services\Billing;

use App\Models\Automation;
use App\Models\LimitType;
use App\Models\Plan;
use App\Services\Email\DefaultTemplates;

/** Datos iniciales de suscripciones: tipos de límite, 3 planes de ejemplo y las automatizaciones de cobranza. Idempotente; todo es editable. */
class BillingDefaults
{
    public static function install(): void
    {
        self::limits();
        self::plans();
        self::automations();
    }

    private static function limits(): void
    {
        if (LimitType::count() > 0) {
            return;
        }
        foreach ([
            ['usuarios', 'Usuarios', 'usuarios', 'Personas del taller con acceso a la app'],
            ['sucursales', 'Sucursales', 'sucursales', 'Talleres o locales gestionados en una misma cuenta'],
            ['ordenes_mes', 'Órdenes de trabajo al mes', 'órdenes', 'Órdenes de trabajo que se pueden crear por mes'],
            ['whatsapp_mes', 'Mensajes de WhatsApp al mes', 'mensajes', 'Avisos y cotizaciones enviados por WhatsApp'],
            ['almacenamiento_gb', 'Almacenamiento', 'GB', 'Fotos y documentos de vehículos'],
            ['api_por_minuto', 'Peticiones de API por minuto', 'req/min', 'Tasa máxima de llamadas a la API de MecaniQ (rate limit)'],
        ] as $i => [$key, $name, $unit, $desc]) {
            LimitType::create(['key' => $key, 'name' => $name, 'unit' => $unit, 'description' => $desc, 'sort_order' => $i]);
        }
    }

    private static function plans(): void
    {
        if (Plan::withTrashed()->count() > 0) {
            return;
        }
        $ids = LimitType::pluck('id', 'key');
        // [nombre, tagline, [mensual, trimestral, anual], [límites...], características]
        $defs = [
            ['Inicial', 'Para dejar el cuaderno', [19990, 53990, 191900], ['usuarios' => 3, 'sucursales' => 1, 'ordenes_mes' => 150, 'whatsapp_mes' => 100, 'almacenamiento_gb' => 2, 'api_por_minuto' => 30], ['Órdenes de trabajo y cotizaciones', 'Ficha de cliente y vehículo', 'Soporte por chat']],
            ['Pro', 'Para talleres en crecimiento', [39990, 107990, 383900], ['usuarios' => 10, 'sucursales' => 1, 'ordenes_mes' => 800, 'whatsapp_mes' => 600, 'almacenamiento_gb' => 20, 'api_por_minuto' => 120], ['Todo lo del plan Inicial', 'Inventario de repuestos', 'Cobros y cuentas por cobrar', 'Reportes de gestión']],
            ['Multi-sucursal', 'Para redes de talleres y flotas', [89990, 242990, 863900], ['usuarios' => 40, 'sucursales' => 5, 'ordenes_mes' => null, 'whatsapp_mes' => 3000, 'almacenamiento_gb' => 100, 'api_por_minuto' => 600], ['Todo lo del plan Pro', 'Panel consolidado por sucursal', 'Roles y permisos avanzados', 'Soporte prioritario']],
        ];
        foreach ($defs as $i => [$name, $tag, $prices, $limits, $features]) {
            $plan = Plan::create(['name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'tagline' => $tag, 'features' => $features, 'sort_order' => $i]);
            foreach (['monthly', 'quarterly', 'annual'] as $n => $period) {
                $plan->prices()->create(['period' => $period, 'amount' => $prices[$n]]);
            }
            foreach ($limits as $key => $value) {
                if (isset($ids[$key])) {
                    $plan->limits()->attach($ids[$key], ['value' => $value]);
                }
            }
        }
    }

    /** Cinco automatizaciones de ejemplo; se pueden editar, desactivar o duplicar desde Email → Automatizaciones. */
    private static function automations(): void
    {
        $t = app(DefaultTemplates::class);
        $defs = [
            ['Suscripción: bienvenida', 'subscription.activated', null, 'bienvenida-suscripcion'],
            ['Cobro: aviso al emitirse', 'invoice.issued', null, 'cobro-suscripcion'],
            ['Cobro: recordatorio 5 días antes', 'invoice.due', -5, 'recordatorio-pago'],
            ['Cobro: recordatorio el día del vencimiento', 'invoice.due', 0, 'recordatorio-pago'],
            ['Cobro: aviso 3 días después del vencimiento', 'invoice.due', 3, 'recordatorio-pago'],
            ['Cobro: aviso 10 días después del vencimiento', 'invoice.due', 10, 'recordatorio-pago'],
            ['Cobro: pago recibido', 'invoice.paid', null, 'pago-recibido'],
            ['Suscripción: aviso 10 días antes de renovar o terminar', 'subscription.renewal', -10, 'renovacion-suscripcion'],
        ];
        foreach ($defs as [$name, $trigger, $offset, $slug]) {
            if (Automation::where('name', $name)->exists()) {
                continue;
            }
            Automation::create([
                'name' => $name, 'trigger' => $trigger, 'offset_days' => $offset, 'template_id' => $t->ensure($slug)->id,
                'to_mode' => 'lead', 'delay_minutes' => 0, 'is_active' => true,
            ]);
        }
    }
}
