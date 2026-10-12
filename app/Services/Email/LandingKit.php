<?php

namespace App\Services\Email;

use App\Models\ContactList;
use App\Models\EmailTemplate;
use App\Models\LeadField;
use App\Models\LeadSource;

/**
 * Recursos del CRM para el sitio integraleads.cl: orígenes (cada uno con su API key),
 * audiencia del boletín, campos personalizados y las dos plantillas de agradecimiento.
 * Es idempotente: solo crea lo que falta y nunca pisa lo que ya se editó en el CRM.
 */
class LandingKit
{
    public const SITE = 'https://www.integraleads.cl';

    public const NEWSLETTER_TEMPLATE = 'gracias-contacto';

    public const ADVISORY_TEMPLATE = 'gracias-cotizacion';

    public const AUDIENCE = 'Boletín INTEGRALEADS';

    /** @return array<string, string> resumen de lo creado */
    public function install(): array
    {
        $created = [];

        foreach ([['Formulario Contacto', '#6419DB', 'mail', 20], ['Formulario Cotización', '#7D8FA3', 'calendar-check', 21]] as [$name, $color, $icon, $order]) {
            $slug = \Illuminate\Support\Str::slug($name);
            if (! LeadSource::where('slug', $slug)->exists()) {
                LeadSource::create(['name' => $name, 'slug' => $slug, 'color' => $color, 'icon' => $icon, 'api_key' => LeadSource::generateKey(), 'sort_order' => $order]);
                $created[] = "origen: $name";
            }
        }

        if (! ContactList::where('name', self::AUDIENCE)->exists()) {
            ContactList::create(['name' => self::AUDIENCE, 'description' => 'Suscriptores del boletín de novedades de INTEGRALEADS.']);
            $created[] = 'audiencia: '.self::AUDIENCE;
        }

        $fields = [
            ['tipo_servicio', 'Servicio de interés', 'select', ['Regularización', 'Planos', 'Diseño de interiores · Departamento', 'Diseño de interiores · Casa', 'Diseño de interiores · Oficina', 'Diseño de interiores · Retail o local comercial', 'Stand', 'Otro']],
            ['comuna', 'Comuna de la propiedad', 'text'],
            ['superficie', 'Superficie aproximada (m²)', 'text'],
            ['presupuesto', 'Presupuesto estimado', 'text'],
            ['plazo', 'Plazo esperado', 'text'],
            ['fecha_preferida', 'Fecha preferida de visita o reunión', 'date'],
            ['interes', 'Interés', 'text'],
        ];
        foreach ($fields as $i => $f) {
            [$key, $label, $type] = $f;
            $options = $f[3] ?? null;
            if (! LeadField::where('key', $key)->exists()) {
                LeadField::create(['key' => $key, 'label' => $label, 'type' => $type, 'options' => $options, 'sort_order' => 10 + $i]);
                $created[] = "campo: $label";
            }
        }

        $templates = app(DefaultTemplates::class);
        foreach ([self::NEWSLETTER_TEMPLATE => 'Gracias por contactarnos', self::ADVISORY_TEMPLATE => 'Solicitud de cotización', UserMailer::WELCOME => 'Bienvenida de usuario', UserMailer::RESET => 'Recuperar contraseña', \App\Services\Proposals\ProposalMailer::SLUG => 'Propuesta comercial', \App\Services\Proposals\ProposalMailer::RESPONDED => 'Propuesta respondida'] as $slug => $label) {
            $before = EmailTemplate::where('slug', $slug)->first();
            $templates->ensure($slug, upgrade: true);
            if (! $before || $before->editor !== 'blocks') {
                $created[] = ($before ? 'plantilla pasada al editor visual: ' : 'plantilla: ').$label;
            }
        }

        return $created;
    }
}
