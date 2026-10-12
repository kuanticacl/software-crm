<?php

use App\Models\Automation;
use App\Services\Email\DefaultTemplates;
use Illuminate\Database\Migrations\Migration;

/**
 * Cada registro que llega desde la web recibe al instante el correo «Gracias por contactarnos»
 * (pronto nos pondremos en contacto). Se crea solo si aún no hay una automatización de ingreso.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Automation::where('trigger', 'lead.created')->exists()) {
            return;
        }

        $template = app(DefaultTemplates::class)->ensure('gracias-contacto');

        Automation::create([
            'name' => 'Gracias por tu registro (web)',
            'trigger' => 'lead.created',
            'conditions' => ['web_only' => true],
            'template_id' => $template->id,
            'to_mode' => 'lead',
            'delay_minutes' => 0,
            'is_active' => true,
        ]);
    }

    public function down(): void
    {
        Automation::where('name', 'Gracias por tu registro (web)')->delete();
    }
};
