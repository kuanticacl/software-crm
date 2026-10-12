@php $num = ($n ?? 0) + 1; @endphp
<section class="card">
    <h2><span class="n">{{ $num }}</span>Servicios e inversión</h2>

    @if ($items->isEmpty())
        <p style="color:var(--mut)">Aún no se han agregado servicios.</p>
    @else
        <div class="kpis">
            @if ($subOnce > 0)<div class="kpi"><span>Inversión inicial (neto)</span><strong>{{ $money($p->total_one_time) }}</strong></div>@endif
            @if ($subMonthly > 0)<div class="kpi p"><span>Mensual (neto)</span><strong>{{ $money($p->total_monthly) }}</strong>@if ($months > 1)<small style="color:var(--mut)">por {{ $months }} meses</small>@endif</div>@endif
        </div>

        @foreach ([['Pago único', 'one_time', $once], ['Servicios mensuales', 'monthly', $monthly]] as [$label, $key, $group])
            @if ($group->isNotEmpty())
                <div class="grp"><span class="dot"></span>{{ $label }}@if ($key === 'monthly')<small>· {{ $months }} {{ $months === 1 ? 'mes' : 'meses' }}</small>@endif</div>
                <table>
                    <thead><tr><th>Servicio</th><th class="r hide">Cant.</th><th class="r hide">Valor unit.</th><th class="r hide">Dto.</th><th class="r">Total{{ $key === 'monthly' ? ' / mes' : '' }}</th></tr></thead>
                    <tbody>
                    @foreach ($group as $i)
                        <tr>
                            <td>
                                <div class="iname">{{ $i->name }}</div>
                                @if ($i->description)<div class="idesc">{!! \App\Support\ProposalText::html($i->description) !!}</div>@endif
                                @if (! empty($i->deliverables))<ul class="deliv">@foreach ($i->deliverables as $d)<li>{{ $d }}</li>@endforeach</ul>@endif
                            </td>
                            <td class="r hide">{{ rtrim(rtrim(number_format($i->quantity, 2, ',', '.'), '0'), ',') }} <span style="color:var(--mut)">{{ $i->unit }}</span></td>
                            <td class="r hide">{{ $money($i->unit_price) }}</td>
                            <td class="r hide">{{ $i->discount_pct ? $i->discount_pct.'%' : '—' }}</td>
                            <td class="r"><strong>{{ $money($i->lineTotal($p->decimals())) }}</strong></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        @endforeach

        <div class="totals">
            @if ($subOnce > 0)<div><span>Subtotal pago único</span><span>{{ $money($subOnce) }}</span></div>@endif
            @if ($subMonthly > 0)<div><span>Subtotal mensual × {{ $months }}</span><span>{{ $money($subMonthly * $months) }}</span></div>@endif
            @if ($discount > 0)<div><span>Descuento{{ $p->discount_type === 'percent' ? ' ('.$p->discount_value.'%)' : '' }}</span><span>− {{ $money($discount) }}</span></div>@endif
            @php($tl = $p->taxLabels())
            @if ($tl['tax'])
            <div class="sep"><span>{{ $tl['net'] }}</span><strong>{{ $money($p->total_net) }}</strong></div>
            <div><span>{{ $tl['tax'] }}</span><span>{{ $money($p->total_tax) }}</span></div>
            @endif
            <div class="grand"><span>{{ $tl['gross'] }}</span><span>{{ $money($p->total_gross) }}</span></div>
            @if ($isUf)<div style="display:block;margin-top:8px;font-size:12.5px;color:var(--mut);text-align:right">Equivalente en pesos ≈ <strong style="color:var(--ink)">{{ $clpGross }}</strong><br>Valor de referencia: 1 UF = {{ $ufFmt }} al {{ $ufDate }}</div>@endif
        </div>
    @endif
</section>
