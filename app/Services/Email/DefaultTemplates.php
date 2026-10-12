<?php

namespace App\Services\Email;

use App\Models\EmailTemplate;
use App\Services\Ai\BrandedEmailDesign;

/**
 * Plantillas por defecto del CRM. Se definen con el modelo de bloques del editor visual (diseño + HTML compilado
 * por el mismo compilador, ver scripts/build-email-designs.mjs), así que se abren y editan en Plantillas → editor visual.
 */
class DefaultTemplates
{
    public const SLUGS = ['bienvenida-usuario', 'recuperar-password', 'gracias-contacto', 'gracias-cotizacion', 'propuesta-comercial', 'propuesta-respondida', 'bienvenida-portal', 'cobro-factura', 'recordatorio-pago', 'cotizacion-inmobiliaria', 'reserva-confirmada'];

    /** Crea la plantilla si falta; con $upgrade, una versión antigua en HTML que nadie editó pasa al editor visual. */
    public function ensure(string $slug, bool $upgrade = false): EmailTemplate
    {
        $existing = EmailTemplate::where('slug', $slug)->first();

        if ($existing && (! $upgrade || ! $this->isUntouchedLegacy($existing))) {
            return $existing;
        }

        $data = $this->load($slug);

        if ($existing) {
            $existing->update(['editor' => 'blocks', 'design' => $data['design'], 'html' => $data['html']]);

            return $existing;
        }

        return EmailTemplate::create([
            'slug' => $slug, 'name' => $data['name'], 'description' => $data['description'], 'category' => $data['category'],
            'subject' => $data['subject'], 'preheader' => $data['preheader'], 'editor' => 'blocks',
            'design' => $data['design'], 'html' => $data['html'], 'variables' => $data['variables'], 'is_active' => true,
        ]);
    }

    /**
     * Migración única: las primeras versiones de estas plantillas se crearon como HTML. Solo se pasan al editor visual
     * las que conservan ese HTML original (y no se tocaron a mano: sin cambios pasadas las 3 primeras horas). Solo corre desde crm:landing-kit.
     */
    private function isUntouchedLegacy(EmailTemplate $t): bool
    {
        return $t->editor === 'html'
            && str_contains((string) $t->html, 'style="max-width:100%;background:#FFFFFF;border-radius:16px;overflow:hidden"')
            && $t->updated_at !== null && $t->created_at !== null
            && $t->updated_at->lte($t->created_at->copy()->addHours(3));
    }

    /** @return array<string, mixed> */
    private function load(string $slug): array
    {
        $raw = (string) file_get_contents(resource_path("email-designs/{$slug}.json"));
        $address = (string) MailSettings::footerAddress();
        $logo = BrandedEmailDesign::logoUrl();

        // El HTML ya viene escapado por el compilador; el diseño guarda el texto tal cual.
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $data['html'] = str_replace(['{{LOGO_URL}}', '{{ADDRESS}}'], [$logo, e($address)], $data['html']);
        $data['design'] = json_decode(str_replace(['{{LOGO_URL}}', '{{ADDRESS}}'], [addcslashes($logo, '/"\\'), trim(json_encode($address, JSON_UNESCAPED_UNICODE), '"')], json_encode($data['design'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), true);

        return $data;
    }
}
