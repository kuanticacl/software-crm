<?php

namespace App\Services\Proposals;

use App\Models\Proposal;
use App\Support\Agency;
use App\Support\ProposalText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Datos ya calculados para dibujar una propuesta (documento web y PDF usan lo mismo). */
class ProposalView
{
    /** @return array<string, mixed> */
    public static function data(Proposal $p): array
    {
        $p->loadMissing(['items', 'owner:id,name,email']);
        $r = $p->recipient ?? [];
        $months = max(1, (int) ($p->contract_months ?: 1));
        $once = $p->items->where('billing', 'one_time')->values();
        $monthly = $p->items->where('billing', 'monthly')->values();
        $dec = $p->decimals();
        $subOnce = round($once->sum(fn ($i) => $i->lineTotal($dec)), $dec);
        $subMonthly = round($monthly->sum(fn ($i) => $i->lineTotal($dec)), $dec);
        $date = fn ($d) => $d ? Carbon::parse($d)->locale('es')->translatedFormat('d \d\e F \d\e Y') : null;

        $plan = $p->plan_id ? $p->loadMissing('plan.limits')->plan : null;
        $subscription = $plan ? [
            'plan' => $plan->name,
            'period' => \App\Support\BillingPeriod::LABELS[$p->plan_period] ?? '',
            'auto_renew' => (bool) $p->auto_renew,
            'start' => $date($p->start_date),
            'limits' => $plan->limitsList(),
            'contract' => ProposalText::html(\App\Services\Billing\BillingSettings::contract()),
        ] : null;

        return [
            'subscription' => $subscription,
            'p' => $p,
            'r' => $r,
            'status' => $p->effectiveStatus(),
            'company' => $r['company'] ?? $r['legal_name'] ?? 'Cliente',
            'months' => $months,
            'once' => $once,
            'monthly' => $monthly,
            'subOnce' => $subOnce,
            'subMonthly' => $subMonthly,
            'discount' => round(($subOnce + $subMonthly * $months) - ($p->total_one_time + $p->total_monthly * $months), 2),
            'currency' => $p->currency,
            'isUf' => $p->currency === 'UF' && $p->uf_value > 0,
            'ufDate' => $p->uf_date ? Carbon::parse($p->uf_date)->locale('es')->translatedFormat('d \\d\\e F \\d\\e Y') : null,
            'sections' => collect($p->sections ?? [])->map(fn ($s) => ['title' => $s['title'], 'body' => ProposalText::html(ProposalText::fill($s['body'], $r))])->values(),
            'servicesAt' => collect($p->sections ?? [])->search(fn ($s) => preg_match('/alcance|servicio/i', $s['title'])),
            'issued' => $date($p->issued_at ?? now()),
            'valid' => $date($p->valid_until),
            'agency' => Agency::profile(),
            'socials' => Agency::socials(),
            'tech' => Agency::tech(),
            'holding' => Agency::holding(),
            'owner' => $p->owner,
            'fileName' => 'Propuesta-'.$p->number.'-'.Str::slug($r['company'] ?? $r['legal_name'] ?? 'cliente').'.pdf',
        ];
    }

    /** UF con 2 decimales («UF 245,50»); pesos sin decimales («$1.234.567»). */
    public static function money(int|float|null $n, string $currency = 'CLP'): string
    {
        return $currency === 'UF'
            ? 'UF '.number_format((float) $n, 2, ',', '.')
            : '$'.number_format((int) round((float) $n), 0, ',', '.');
    }

    public static function clp(int|float|null $n): string
    {
        return self::money($n, 'CLP');
    }

    /** «$41.122,74» */
    public static function ufValue(?float $v): string
    {
        return $v ? '$'.number_format($v, 2, ',', '.') : '—';
    }
}
