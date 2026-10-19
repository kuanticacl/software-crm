<?php

namespace App\Services\Ai;

use App\Models\Setting;

/** Contexto de marca de MecaniQ (app móvil para talleres mecánicos) que se entrega a la IA (voz, propuesta de valor y reglas de estilo). */
class BrandContext
{
    public static function defaultText(): string
    {
        return <<<'TXT'
MecaniQ es una app móvil para talleres mecánicos en Chile: gestiona órdenes de trabajo, clientes y vehículos, cotizaciones y presupuestos, historial de mantenciones, inventario de repuestos, recordatorios y avisos por WhatsApp, y cobros, todo desde el celular. Posicionamiento: «El taller en tu bolsillo — menos papeleo, más autos reparados».
Se dirige a dueños y jefes de taller mecánico, talleres de barrio, multimarca, de planchado y pintura, neumáticos y vulcanización, y flotas pequeñas. Tiene landing y un equipo comercial que prospecta talleres y agenda demos.
Voz: cercana, práctica y directa, como alguien del rubro. Español de Chile, trato de «tú», sin jerga técnica de software ni promesas exageradas. Frases cortas, beneficios concretos (ahorrar tiempo, no perder cobros, clientes que vuelven) y números cuando existan. Evita mayúsculas gritadas y signos de exclamación en exceso.
Llamados a la acción típicos: «Agenda una demo», «Prueba MecaniQ gratis», «Conversemos por WhatsApp».

TXT;
    }

    public static function text(): string
    {
        return Setting::get('ai.brand_context') ?: self::defaultText();
    }
}
