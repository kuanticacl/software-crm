<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Documento tributario con que trabaja este CRM (Datos de la agencia → Documento tributario):
 * define cómo se calculan las propuestas y el documento por defecto de los cobros.
 * Cada propuesta puede cambiarlo para un caso puntual.
 */
class TaxDocument
{
    public const MODES = [
        'iva' => 'Factura afecta (neto + IVA)',
        'exenta' => 'Factura exenta (sin IVA)',
        'honorarios' => 'Boleta de honorarios (neto + retención)',
        'none' => 'Sin boleta ni factura',
    ];

    public const IVA = 19.0;

    public static function default(): string
    {
        $mode = (string) (Setting::get('billing.tax_document') ?: config('portal.tax_document', 'iva'));

        return array_key_exists($mode, self::MODES) ? $mode : 'iva';
    }

    /** Tasa que corresponde a cada modo: IVA, retención vigente de honorarios o 0. */
    public static function rate(string $mode): float
    {
        return match ($mode) {
            'iva' => self::IVA,
            'honorarios' => Honorarios::rate(),
            default => 0.0,
        };
    }

    /** Valores por defecto de un cobro nuevo según el documento configurado. @return array{document_type: string, tax_rate: float} */
    public static function invoiceDefaults(): array
    {
        $mode = self::default();

        return [
            'document_type' => match ($mode) {
                'honorarios' => 'boleta',
                'none' => 'otro',
                default => 'factura',
            },
            'tax_rate' => $mode === 'iva' ? self::IVA : 0.0,
        ];
    }

    /** Frase para las instrucciones de la IA. */
    public static function aiHint(): string
    {
        return match (self::default()) {
            'honorarios' => 'Los montos se hablan NETOS y se respaldan con boleta de honorarios: la propuesta suma sola la retención (tax_mode=honorarios). Nunca agregues IVA salvo que pidan factura.',
            'exenta' => 'Los montos se respaldan con factura exenta: sin IVA (tax_mode=exenta).',
            'none' => 'No se emite boleta ni factura: los montos son finales, sin impuestos (tax_mode=none).',
            default => 'Los montos se hablan NETOS y se respaldan con factura afecta: la propuesta suma el IVA (tax_mode=iva).',
        };
    }
}
