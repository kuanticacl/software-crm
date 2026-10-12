<div class="card" style="page-break-inside:auto;page-break-before:always">
    <table class="h2t"><tr><td class="num">{{ $n + 1 }}</td><td class="tt">Servicios e inversión</td></tr></table>
    @if ($items = $p->items) @endif
    @if ($p->items->isEmpty())
        <p class="mut">Aún no se han agregado servicios.</p>
    @else
        <table style="margin-bottom:6px"><tr>
            @if ($subOnce > 0)<td class="kpi" style="width:48%"><div class="l">Inversión inicial (neto)</div><div class="v">{{ $m($p->total_one_time) }}</div></td><td style="width:2%"></td>@endif
            @if ($subMonthly > 0)<td class="kpi p" style="width:48%"><div class="l">Mensual (neto){{ $months > 1 ? ' · '.$months.' meses' : '' }}</div><div class="v">{{ $m($p->total_monthly) }}</div></td>@endif
        </tr></table>

        @foreach ([['Pago único', 'one_time', $once], ['Servicios mensuales · '.$months.' '.($months === 1 ? 'mes' : 'meses'), 'monthly', $monthly]] as [$label, $key, $group])
            @if ($group->isNotEmpty())
                <div class="grp"><span class="dot">●</span> {{ $label }}</div>
                <table class="items">
                    <thead><tr><th>Servicio</th><th class="r">Cant.</th><th class="r">Valor unit.</th><th class="r">Dto.</th><th class="r">Total{{ $key === 'monthly' ? ' / mes' : '' }}</th></tr></thead>
                    <tbody>
                    @foreach ($group as $i)
                        <tr>
                            <td style="width:46%">
                                <div class="b">{{ $i->name }}</div>
                                @if ($i->description)<div class="idesc">{!! \App\Support\ProposalText::html($i->description) !!}</div>@endif
                                @if (! empty($i->deliverables))<ul class="deliv">@foreach ($i->deliverables as $d)<li>{{ $d }}</li>@endforeach</ul>@endif
                            </td>
                            <td class="r">{{ rtrim(rtrim(number_format($i->quantity, 2, ',', '.'), '0'), ',') }} {{ $i->unit }}</td>
                            <td class="r">{{ $m($i->unit_price) }}</td>
                            <td class="r">{{ $i->discount_pct ? $i->discount_pct.'%' : '—' }}</td>
                            <td class="r b">{{ $m($i->lineTotal($p->decimals())) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        @endforeach

        <table style="width:55%;margin:14px 0 0 auto" class="tot">
            @if ($subOnce > 0)<tr><td>Subtotal pago único</td><td class="r">{{ $m($subOnce) }}</td></tr>@endif
            @if ($subMonthly > 0)<tr><td>Subtotal mensual × {{ $months }}</td><td class="r">{{ $m($subMonthly * $months) }}</td></tr>@endif
            @if ($discount > 0)<tr><td>Descuento{{ $p->discount_type === 'percent' ? ' ('.$p->discount_value.'%)' : '' }}</td><td class="r">− {{ $m($discount) }}</td></tr>@endif
            @php($tl = $p->taxLabels())
            @if ($tl['tax'])
            <tr><td style="border-top:1px solid #E8E8E8;padding-top:6px"><b>{{ $tl['net'] }}</b></td><td class="r" style="border-top:1px solid #E8E8E8;padding-top:6px"><b>{{ $m($p->total_net) }}</b></td></tr>
            <tr><td>{{ $tl['tax'] }}</td><td class="r">{{ $m($p->total_tax) }}</td></tr>
            @endif
            <tr class="grand"><td>{{ $tl['gross'] }}</td><td class="r">{{ $m($p->total_gross) }}</td></tr>
            @if ($isUf)<tr><td colspan="2" class="r" style="padding-top:6px;font-size:9px;color:#707070">Equivalente en pesos ≈ <b style="color:#393939">{{ $clpGross }}</b><br>Valor de referencia: 1 UF = {{ $ufFmt }} al {{ $ufDate }}</td></tr>@endif
        </table>
    @endif
</div>
