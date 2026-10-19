<?php

namespace App\Services\Email;

use App\Models\ContactList;
use App\Models\EmailTemplate;
use App\Models\LeadField;
use App\Models\LeadSource;

/**
 * Recursos del CRM para la landing www.mecaniq.cl: orígenes (cada uno con su API key),
 * audiencia del boletín, campos personalizados y las plantillas de agradecimiento.
 * Es idempotente: solo crea lo que falta y nunca pisa lo que ya se editó en el CRM.
 */
class LandingKit
{
    public const SITE = 'https://www.mecaniq.cl';

    public const NEWSLETTER_TEMPLATE = 'bienvenida-boletin';

    public const ADVISORY_TEMPLATE = 'confirmacion-demo';

    public const AUDIENCE = 'Boletín MecaniQ';

    /** @return array<string, string> resumen de lo creado */
    public function install(): array
    {
        $created = [];

        foreach ([['Landing MecaniQ – Boletín', '#027BB9', 'newspaper', 20], ['Landing MecaniQ – Demo', '#6366F1', 'calendar-check', 21], ['WhatsApp MecaniQ', '#25D366', 'message-circle', 22], ['Prospección en terreno', '#F59E0B', 'map-pin', 23]] as [$name, $color, $icon, $order]) {
            $slug = \Illuminate\Support\Str::slug($name);
            if (! LeadSource::where('slug', $slug)->exists()) {
                LeadSource::create(['name' => $name, 'slug' => $slug, 'color' => $color, 'icon' => $icon, 'api_key' => LeadSource::generateKey(), 'sort_order' => $order]);
                $created[] = "origen: $name";
            }
        }

        if (! ContactList::where('name', self::AUDIENCE)->exists()) {
            ContactList::create(['name' => self::AUDIENCE, 'description' => 'Dueños y jefes de taller suscritos al boletín de novedades y consejos de MecaniQ.']);
            $created[] = 'audiencia: '.self::AUDIENCE;
        }

        $fields = [
            ['tipo_solicitud', 'Tipo de solicitud', 'text'],
            ['fecha_preferida', 'Fecha preferida de la demo', 'date'],
            ['horario_preferido', 'Horario preferido', 'select', ['mañana', 'tarde']],
            ['tipo_taller', 'Tipo de taller', 'select', ['Mecánica general', 'Multimarca', 'Planchado y pintura', 'Neumáticos', 'Eléctrico / electrónico', 'Flota', 'Otro']],
            ['n_mecanicos', 'N.º de mecánicos', 'text'],
            ['ciudad', 'Ciudad o comuna', 'text'],
            ['sistema_actual', 'Cómo gestiona hoy el taller', 'text'],
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
        foreach ([self::NEWSLETTER_TEMPLATE => 'Bienvenida al boletín', self::ADVISORY_TEMPLATE => 'Confirmación de demo', UserMailer::WELCOME => 'Bienvenida de usuario', UserMailer::RESET => 'Recuperar contraseña', \App\Services\Proposals\ProposalMailer::SLUG => 'Propuesta comercial', \App\Services\Proposals\ProposalMailer::RESPONDED => 'Propuesta respondida'] as $slug => $label) {
            $before = EmailTemplate::where('slug', $slug)->first();
            $templates->ensure($slug, upgrade: true);
            if (! $before || $before->editor !== 'blocks') {
                $created[] = ($before ? 'plantilla pasada al editor visual: ' : 'plantilla: ').$label;
            }
        }

        return $created;
    }
}
