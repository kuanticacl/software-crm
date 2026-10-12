<?php

namespace App\Services\RealEstate;

/**
 * Cálculos de una cotización inmobiliaria (todo en UF, con 2 decimales):
 * precio final de cada ítem (lista − descuento), total, pie, saldo a financiar y dividendo estimado.
 */
class QuoteCalculator
{
    /**
     * @param  array<int, array{list_price_uf: float|int|string, discount_pct?: float|int|string|null}>  $items
     * @return array{items: array<int, float>, subtotal_uf: float, discount_uf: float, total_uf: float, pie_uf: float, credit_uf: float, monthly_payment_uf: float}
     */
    public static function totals(array $items, float $piePct, float $rate, int $years): array
    {
        $r = fn (float $n): float => round($n, 2);
        $prices = [];
        $subtotal = 0.0;
        foreach ($items as $i) {
            $list = (float) $i['list_price_uf'];
            $disc = max(0, min(100, (float) ($i['discount_pct'] ?? 0)));
            $prices[] = $r($list * (1 - $disc / 100));
            $subtotal += $list;
        }

        $total = $r(array_sum($prices));
        $pie = $r($total * max(0, min(100, $piePct)) / 100);
        $credit = $r($total - $pie);

        return [
            'items' => $prices,
            'subtotal_uf' => $r($subtotal),
            'discount_uf' => $r($subtotal - $total),
            'total_uf' => $total,
            'pie_uf' => $pie,
            'credit_uf' => $credit,
            'monthly_payment_uf' => self::dividend($credit, $rate, $years),
        ];
    }

    /** Dividendo mensual (sistema francés) sin seguros: crédito × i / (1 − (1 + i)^−n), con i = tasa anual / 12. */
    public static function dividend(float $credit, float $annualRate, int $years): float
    {
        $n = max(1, $years) * 12;
        if ($credit <= 0) {
            return 0.0;
        }
        $i = $annualRate / 100 / 12;

        return round($i > 0 ? $credit * $i / (1 - (1 + $i) ** -$n) : $credit / $n, 2);
    }

    /**
     * Plan del pie: descuenta lo pagado en la reserva y reparte el resto en cuotas mensuales iguales (la última ajusta el redondeo).
     *
     * @return array<int, array{number: int, due_date: string, amount: float}>
     */
    public static function pieInstallments(float $pieUf, float $reservationUf, int $count, \DateTimeInterface $first): array
    {
        $rest = round(max(0, $pieUf - $reservationUf), 2);
        if ($rest <= 0 || $count <= 0) {
            return [];
        }
        $each = floor($rest / $count * 100) / 100;
        $out = [];
        $date = \Carbon\CarbonImmutable::instance($first);
        for ($n = 1; $n <= $count; $n++) {
            $amount = $n === $count ? round($rest - $each * ($count - 1), 2) : $each;
            $out[] = ['number' => $n, 'due_date' => $date->addMonthsNoOverflow($n - 1)->toDateString(), 'amount' => $amount];
        }

        return $out;
    }
}
