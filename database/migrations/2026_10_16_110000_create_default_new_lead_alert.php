<?php

use App\Models\Automation;
use App\Models\Setting;
use App\Models\User;
use App\Services\Email\DefaultTemplates;
use App\Services\Leads\LeadAssignment;
use Illuminate\Database\Migrations\Migration;

/**
 * Valores por defecto de Quiebre: todo cliente nuevo se asigna a Angelo Rossi (si no tiene otro responsable) y el
 * equipo recibe una alerta por correo con la plantilla «Alerta de cliente nuevo». Todo se puede cambiar desde
 * Orígenes de clientes y Email → Automatizaciones. Defensiva: un fallo aquí nunca debe impedir el despliegue.
 */
return new class extends Migration
{
    private const ADMIN_EMAIL = 'arossi@quiebre.cl';

    private const AUTOMATION = 'Alerta de cliente nuevo al equipo';

    public function up(): void
    {
        try {
            $template = app(DefaultTemplates::class)->ensure('alerta-nuevo-cliente');

            if (! Automation::where('name', self::AUTOMATION)->exists()) {
                Automation::create([
                    'name' => self::AUTOMATION,
                    'trigger' => 'lead.created',
                    'conditions' => null,
                    'template_id' => $template->id,
                    'to_mode' => 'team',
                    'delay_minutes' => 0,
                    'is_active' => true,
                ]);
            }
        } catch (\Throwable $e) {
            report($e); // se puede crear a mano en Email → Automatizaciones
        }

        try {
            if (! Setting::has(LeadAssignment::DEFAULT_ASSIGNEE)) {
                $user = User::whereRaw('lower(email) = ?', [self::ADMIN_EMAIL])->where('is_active', true)->first();
                if ($user) {
                    Setting::put(LeadAssignment::DEFAULT_ASSIGNEE, (string) $user->id);
                }
            }
            if (! Setting::has(LeadAssignment::NOTIFY_EMAILS)) {
                Setting::put(LeadAssignment::NOTIFY_EMAILS, self::ADMIN_EMAIL);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function down(): void
    {
        Automation::where('name', self::AUTOMATION)->delete();
    }
};
