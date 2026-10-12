<?php

namespace App\Support;

use App\Models\Setting;

/** Retención de las boletas de honorarios (Ley 21.133): sube gradualmente hasta 17% en 2028. */
class Honorarios
{
    /** Tasa por año, en %. */
    public const SCHEDULE = [2024 => 13.75, 2025 => 14.5, 2026 => 15.25, 2027 => 16.0, 2028 => 17.0];

    /** Tasa vigente para un año; se puede fijar a mano en Configuración (Setting «billing.retention_rate»). */
    public static function rate(?int $year = null): float
    {
        $override = Setting::get('billing.retention_rate');
        if ($override !== null && $override !== '' && is_numeric($override)) {
            return (float) $override;
        }
        $year ??= (int) now()->year;

        return self::SCHEDULE[$year] ?? ($year > 2028 ? 17.0 : 13.75);
    }
}
