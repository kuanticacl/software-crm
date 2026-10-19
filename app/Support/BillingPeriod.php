<?php

namespace App\Support;

/** Períodos de cobro de una suscripción. */
class BillingPeriod
{
    public const MONTHS = ['monthly' => 1, 'quarterly' => 3, 'annual' => 12];

    public const LABELS = ['monthly' => 'Mensual', 'quarterly' => 'Trimestral', 'annual' => 'Anual'];

    /** Para frases: «por mes», «por trimestre», «por año». */
    public const PER = ['monthly' => 'por mes', 'quarterly' => 'por trimestre', 'annual' => 'por año'];

    public static function valid(?string $p): bool
    {
        return $p !== null && isset(self::MONTHS[$p]);
    }

    public static function months(string $p): int
    {
        return self::MONTHS[$p] ?? 1;
    }
}
