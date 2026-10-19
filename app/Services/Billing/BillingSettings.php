<?php

namespace App\Services\Billing;

use App\Models\Setting;

/** Reglas de cobranza editables en «Configuración de cobros». */
class BillingSettings
{
    /** Días antes del término del período en que se emite el cobro de la renovación. */
    public static function issueDaysBefore(): int
    {
        return max(0, min(60, (int) (Setting::get('billing.issue_days_before') ?? 7)));
    }

    /** Plazo de pago del primer cobro (días desde que comienza la suscripción). */
    public static function firstDueDays(): int
    {
        return max(0, min(60, (int) (Setting::get('billing.first_due_days') ?? 5)));
    }

    /** Días de atraso desde los que la suscripción pasa a «Con pago vencido». */
    public static function pastDueAfterDays(): int
    {
        return max(0, min(90, (int) (Setting::get('billing.past_due_days') ?? 1)));
    }

    public static function contract(): string
    {
        return (string) Setting::get('billing.contract', self::defaultContract());
    }

    public static function defaultContract(): string
    {
        return <<<'HTML'
<h3>Contrato de servicios MecaniQ</h3>
<p><strong>1. Objeto.</strong> MecaniQ otorga al cliente una licencia de uso no exclusiva y no transferible de la aplicación móvil y web MecaniQ para la gestión de su taller, según el plan, período y límites indicados en esta propuesta.</p>
<p><strong>2. Vigencia y renovación.</strong> La suscripción comienza en la fecha indicada y se factura por el período contratado (mensual, trimestral o anual). Si el cliente eligió renovación automática, la suscripción se renueva por períodos iguales hasta que sea cancelada con aviso previo; en caso contrario, finaliza al término del período.</p>
<p><strong>3. Precio y pago.</strong> Los valores son netos y se les agrega IVA. El cobro se emite antes del inicio de cada período y debe pagarse a más tardar en su fecha de vencimiento por los medios informados.</p>
<p><strong>4. Límites del plan.</strong> El uso del servicio está sujeto a los límites del plan contratado. MecaniQ puede ofrecer cambiar de plan cuando el uso los supere.</p>
<p><strong>5. Atraso en el pago.</strong> Ante atraso, MecaniQ enviará recordatorios y podrá suspender el acceso al servicio hasta regularizar el pago. Los datos del cliente se conservan.</p>
<p><strong>6. Datos y confidencialidad.</strong> Los datos del taller y de sus clientes son del cliente. MecaniQ los trata solo para prestar el servicio y aplica medidas razonables de seguridad.</p>
<p><strong>7. Término.</strong> El cliente puede cancelar en cualquier momento; la cancelación rige desde el término del período ya pagado y no da derecho a reembolso del período en curso.</p>
<p><strong>8. Aceptación.</strong> La firma de la propuesta implica la aceptación de este contrato.</p>
HTML;
    }
}
