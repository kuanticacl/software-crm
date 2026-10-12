<?php

namespace App\Services\RealEstate;

use App\Models\Quote;
use App\Support\Agency;

/** Datos ya formateados para la cotización (web pública y PDF). */
class QuoteView
{
    public static function uf(float|int|null $n): string
    {
        return number_format((float) $n, 2, ',', '.').' UF';
    }

    public static function clp(float|int|null $n): string
    {
        return '$'.number_format((float) round((float) $n), 0, ',', '.');
    }

    public static function m2(?float $n): ?string
    {
        return $n ? rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',').' m²' : null;
    }

    /** @return array<string, mixed> */
    public static function data(Quote $q): array
    {
        $q->loadMissing(['project', 'items.unit', 'owner', 'client', 'lead']);
        $p = $q->project;
        $uf = (float) $q->uf_value;
        $reservationUf = $uf > 0 ? round($q->reservation_amount / $uf, 2) : 0;
        $pieRest = max(0, round($q->pie_uf - $reservationUf, 2));
        $installment = $q->pie_installments > 0 ? round($pieRest / $q->pie_installments, 2) : 0;

        $main = $q->items->first(fn ($i) => in_array($i->type, \App\Models\Unit::MAIN_TYPES, true)) ?? $q->items->first();

        return [
            'q' => $q,
            'project' => $p,
            'agency' => Agency::profile(),
            'owner' => $q->owner,
            'buyer' => $q->buyer ?? [],
            'items' => $q->items,
            'main' => $main,
            'mainUnit' => $main?->unit,
            'plans' => $q->items->map(fn ($i) => $i->unit)->filter(fn ($u) => $u && $u->floor_plan_path)->unique('floor_plan_path')->values(),
            'ufText' => $uf > 0 ? self::clp($uf) : null,
            'ufDate' => $q->uf_date?->locale('es')->translatedFormat('d \d\e F \d\e Y'),
            'validText' => $q->valid_until?->locale('es')->translatedFormat('d \d\e F \d\e Y'),
            'issued' => ($q->sent_at ?? $q->created_at ?? now())->locale('es')->translatedFormat('d \d\e F \d\e Y'),
            'reservationUf' => $reservationUf,
            'pieRest' => $pieRest,
            'installment' => $installment,
            'credit' => $q->credit_uf,
            'dividend' => $q->monthly_payment_uf,
            'cover' => $p?->coverUrl(),
            'coverPath' => $p?->cover_path ? storage_path('app/public/'.$p->cover_path) : null,
        ];
    }
}
