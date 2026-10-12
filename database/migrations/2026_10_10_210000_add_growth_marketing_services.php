<?php

use App\Models\Service;
use Illuminate\Database\Migrations\Migration;

/**
 * Vivienda en Regla también es agencia de growth marketing: agrega esos servicios al catálogo (tarifas
 * referenciales en UF, editables en Servicios). No toca los que ya existen ni se repite si ya están.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Instalaciones nuevas: el seeder ya los incluye. Sin catálogo previo no se agrega nada aquí.
        if (! Service::query()->exists()) {
            return;
        }

        $next = (int) Service::max('sort_order') + 1;

        $items = [
            ['Estrategia de growth y embudo de ventas', 'Diagnóstico y diseño del embudo de captación: canales, mensajes, oferta y metas, con un plan de crecimiento a 90 días.', ['Diagnóstico de situación y competencia', 'Diseño del embudo y definición de KPIs', 'Plan de acción a 90 días', 'Reunión de presentación'], 'one_time', 'proyecto', 30],
            ['Gestión de campañas Meta Ads', 'Campañas de generación de clientes en Facebook e Instagram: estrategia, creatividades, segmentación y optimización semanal.', ['Estrategia y segmentación', 'Creatividades y copys', 'Optimización semanal', 'Reporte mensual de resultados'], 'monthly', 'mes', 25],
            ['Gestión de campañas Google Ads', 'Campañas de búsqueda y display para captar clientes con intención activa de compra.', ['Investigación de palabras clave', 'Campañas de búsqueda y display', 'Seguimiento de conversiones', 'Reporte mensual'], 'monthly', 'mes', 25],
            ['SEO y contenidos', 'Posicionamiento orgánico: auditoría técnica, palabras clave y contenidos que atraen tráfico calificado.', ['Auditoría SEO técnica', 'Plan de palabras clave y contenidos', 'Publicación de contenidos mensuales', 'Reporte de posicionamiento'], 'monthly', 'mes', 18],
            ['Email marketing y marketing automation', 'Secuencias y boletines automatizados para nutrir prospectos y reactivar clientes, conectados a tu CRM.', ['Diseño de plantillas', 'Secuencias automatizadas', 'Segmentación de audiencias', 'Reporte de aperturas y conversiones'], 'monthly', 'mes', 15],
            ['Optimización de conversión (CRO)', 'Mejoras continuas en tu sitio o landing basadas en datos: pruebas A/B, experiencia de usuario y formularios.', ['Análisis de comportamiento', 'Hipótesis y pruebas A/B', 'Implementación de mejoras', 'Reporte de resultados'], 'monthly', 'mes', 14],
            ['Analítica y tracking (GA4, GTM, píxeles)', 'Medición confiable de campañas y conversiones, conectada a dashboards y a tu CRM.', ['Configuración de GA4 y Tag Manager', 'Píxeles y eventos de conversión', 'Dashboard de resultados'], 'one_time', 'proyecto', 15],
        ];

        foreach ($items as $i => [$name, $description, $deliverables, $billing, $unit, $price]) {
            if (! Service::where('name', $name)->exists()) {
                Service::create([
                    'category' => 'Growth marketing', 'name' => $name, 'description' => $description, 'deliverables' => $deliverables,
                    'billing' => $billing, 'unit' => $unit, 'price' => $price, 'currency' => 'UF', 'sort_order' => $next + $i,
                ]);
            }
        }
    }

    public function down(): void {}
};
