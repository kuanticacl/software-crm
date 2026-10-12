<?php

return [
    /*
    | Dominio del portal de clientes (p. ej. clientes.viviendaenregla.cl). Es la misma aplicación del CRM: en ese host solo se
    | atiende el portal y el inicio de sesión. Vacío = el portal funciona igual en /portal del dominio del CRM.
    */
    'domain' => env('PORTAL_DOMAIN'),

    /** URL base pública del portal (para enlaces de correos). Por defecto https://{domain}, o la URL del CRM. */
    'url' => env('PORTAL_URL'),

    /** Días de anticipación con que se generan los cobros programados de servicios recurrentes. */
    'charge_ahead_days' => (int) env('BILLING_CHARGE_AHEAD_DAYS', 10),

    /**
     * Documento tributario por defecto de este CRM (se puede cambiar en Datos de la agencia):
     * iva (factura afecta), exenta (factura exenta), honorarios (boleta de honorarios) o none (sin documento).
     */
    'tax_document' => env('BILLING_TAX_DOCUMENT', 'honorarios'),

    /** IVA por defecto de los cobros (Chile). */
    'tax_rate' => (float) env('BILLING_TAX_RATE', 19),

    /** Recordatorios de pago por defecto, en días respecto del vencimiento (negativo = antes). */
    'reminder_offsets' => [-5, 0, 3, 7],
];
