@php
    use App\Services\RealEstate\QuoteView as V;
    $img = fn ($rel) => 'file://'.$public.'/'.$rel;
    $pct = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', ''), '0'), ',');
@endphp
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Cotización {{ $q->number }} · {{ $project->name }}</title>
<style>
    @font-face { font-family:'Open Sans'; font-weight:400; src:url('file://{{ $fonts }}/OpenSans-400.ttf') format('truetype'); }
    @font-face { font-family:'Open Sans'; font-weight:600; src:url('file://{{ $fonts }}/OpenSans-600.ttf') format('truetype'); }
    @font-face { font-family:'Open Sans'; font-weight:700; src:url('file://{{ $fonts }}/OpenSans-700.ttf') format('truetype'); }
    @page { margin:30px 34px 46px; }
    body { font-family:'Open Sans',sans-serif; font-size:10.5px; color:#171B2E; line-height:1.45; }
    table { width:100%; border-collapse:collapse; }
    .mut { color:#565E74; }
    .hero { background:#6419DB; color:#fff; border-radius:14px; padding:18px 22px; }
    .hero h1 { margin:6px 0 2px; font-size:22px; font-weight:700; }
    .tag { font-size:9px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; opacity:.85; }
    .card { border:1px solid #E3E6F2; border-radius:12px; padding:14px 16px; margin-top:12px; }
    h2 { font-size:12.5px; margin:0 0 8px; color:#4A10A8; }
    .items th { text-align:left; font-size:9px; color:#565E74; font-weight:600; padding:5px 4px; border-bottom:1px solid #E3E6F2; }
    .items td { padding:6px 4px; border-bottom:1px solid #EEF0F8; vertical-align:top; }
    .r { text-align:right; white-space:nowrap; }
    .total td { background:#F0E9FD; font-weight:700; font-size:12px; padding:8px; color:#4A10A8; }
    .kv td { padding:4px 0; border-bottom:1px dashed #E3E6F2; }
    .chip { background:#F8F9FF; border:1px solid #E3E6F2; border-radius:8px; padding:6px 8px; }
    .chip .l { font-size:8.5px; color:#565E74; }
    .chip .v { font-size:11px; font-weight:700; }
    .plan { max-width:100%; max-height:330px; }
    .small { font-size:8.5px; color:#565E74; }
</style>
</head>
<body>
<table><tr>
    <td><img src="{{ $img('brand/integraleads-logo-dark.png') }}" style="height:18px"></td>
    <td class="r mut">Cotización <b style="color:#171B2E">{{ $q->number }}</b> · {{ $issued }}</td>
</tr></table>

<div class="hero" style="margin-top:12px">
    <div class="tag">Cotización inmobiliaria</div>
    <h1>{{ $project->name }}</h1>
    <div>{{ collect([$project->location(), $project->developer])->filter()->implode(' · ') }}@if ($project->delivery_note || $project->delivery_date) · Entrega {{ $project->delivery_note ?: $project->delivery_date?->locale('es')->translatedFormat('F Y') }}@endif</div>
</div>

<table style="margin-top:12px"><tr>
    @if (! empty($buyer['name']))<td style="width:34%;padding-right:6px"><div class="chip"><div class="l">Preparada para</div><div class="v">{{ $buyer['name'] }}</div>@if (! empty($buyer['rut']))<div class="small">RUT {{ $buyer['rut'] }}</div>@endif</div></td>@endif
    <td style="width:33%;padding-right:6px"><div class="chip"><div class="l">Ejecutivo/a</div><div class="v">{{ $owner?->name ?? 'Equipo de ventas' }}</div>@if ($owner)<div class="small">{{ collect([$owner->email, $owner->phone])->filter()->implode(' · ') }}</div>@endif</div></td>
    <td><div class="chip"><div class="l">Válida hasta · UF de referencia</div><div class="v">{{ $validText ?? '—' }}</div>@if ($ufText)<div class="small">1 UF = {{ $ufText }}{{ $ufDate ? ' ('.$ufDate.')' : '' }}</div>@endif</div></td>
</tr></table>

<div class="card">
    <h2>Unidades cotizadas</h2>
    <table class="items">
        <thead><tr><th>Unidad</th><th>Detalle</th><th class="r">Lista</th><th class="r">Dcto.</th><th class="r">Precio</th></tr></thead>
        <tbody>
        @foreach ($items as $i)
            <tr>
                <td><b>{{ $i->label() }}</b></td>
                <td class="mut">{{ $i->detail }}</td>
                <td class="r">{{ V::uf($i->list_price_uf) }}</td>
                <td class="r">{{ $i->discount_pct > 0 ? $pct($i->discount_pct).'%' : '—' }}</td>
                <td class="r"><b>{{ $i->price_uf > 0 ? V::uf($i->price_uf) : 'Incluido' }}</b></td>
            </tr>
        @endforeach
        <tr class="total"><td colspan="4">Valor total{{ $q->discount_uf > 0 ? ' (ahorro '.V::uf($q->discount_uf).')' : '' }}</td><td class="r">{{ V::uf($q->total_uf) }}@if ($ufText)<br><span style="font-size:9px;font-weight:600">≈ {{ V::clp($q->clp($q->total_uf)) }}</span>@endif</td></tr>
        </tbody>
    </table>
</div>

<table style="margin-top:0"><tr>
    <td style="width:52%;vertical-align:top;padding-right:6px">
        <div class="card">
            <h2>Forma de pago</h2>
            <table class="kv">
                <tr><td>Reserva</td><td class="r"><b>{{ V::clp($q->reservation_amount) }}</b></td></tr>
                <tr><td>Pie ({{ $pct($q->pie_pct) }}%)</td><td class="r"><b>{{ V::uf($q->pie_uf) }}</b></td></tr>
                @if ($q->pie_installments > 0 && $pieRest > 0)
                    <tr><td>Saldo del pie en {{ $q->pie_installments }} cuotas</td><td class="r" style="color:#4A10A8"><b>{{ V::uf($installment) }} / mes</b></td></tr>
                @endif
                <tr><td>Saldo con crédito hipotecario</td><td class="r"><b>{{ V::uf($credit) }}</b></td></tr>
                @if ($dividend > 0)
                    <tr><td>Dividendo estimado ({{ $q->mortgage_years }} años, {{ $pct($q->mortgage_rate) }}%)</td><td class="r" style="color:#4A10A8"><b>{{ V::uf($dividend) }} / mes</b>@if ($ufText)<br><span class="small">≈ {{ V::clp($dividend * $q->uf_value) }}</span>@endif</td></tr>
                @endif
            </table>
            <p class="small" style="margin:8px 0 0">El pie descuenta lo pagado en la reserva. El dividendo es referencial, sin seguros; las condiciones finales las define el banco.</p>
        </div>
        @if ($q->notes)<div class="card"><h2>Comentarios</h2>{!! nl2br(e($q->notes)) !!}</div>@endif
    </td>
    <td style="vertical-align:top">
        @if ($mainUnit)
            <div class="card">
                <h2>{{ $main?->label() }}</h2>
                <table class="kv">
                    @if ($mainUnit->programLabel())<tr><td>Programa</td><td class="r"><b>{{ $mainUnit->programLabel() }}</b></td></tr>@endif
                    @if ($mainUnit->model)<tr><td>Modelo</td><td class="r"><b>{{ $mainUnit->model }}</b></td></tr>@endif
                    @if ($mainUnit->floor !== null)<tr><td>Piso / orientación</td><td class="r"><b>{{ $mainUnit->floor }}{{ $mainUnit->orientation ? ' · '.$mainUnit->orientation : '' }}</b></td></tr>@endif
                    @if ($mainUnit->surface_useful)<tr><td>Superficie útil</td><td class="r"><b>{{ V::m2($mainUnit->surface_useful) }}</b></td></tr>@endif
                    @if ($mainUnit->surface_terrace)<tr><td>Terraza</td><td class="r"><b>{{ V::m2($mainUnit->surface_terrace) }}</b></td></tr>@endif
                    @if ($mainUnit->surface_total)<tr><td>Superficie total</td><td class="r"><b>{{ V::m2($mainUnit->surface_total) }}</b></td></tr>@endif
                    @if ($mainUnit->surface_lot)<tr><td>Terreno</td><td class="r"><b>{{ V::m2($mainUnit->surface_lot) }}</b></td></tr>@endif
                </table>
            </div>
        @endif
    </td>
</tr></table>

@foreach ($plans as $u)
    @if ($u->floorPlanPath())
        <div class="card" style="text-align:center;page-break-inside:avoid">
            <h2 style="text-align:left">Planta · {{ $u->label() }}{{ $u->model ? ' · modelo '.$u->model : '' }}</h2>
            <img class="plan" src="file://{{ $u->floorPlanPath() }}">
        </div>
    @endif
@endforeach

@if ($project->quote_conditions)<div class="card small">{!! nl2br(e($project->quote_conditions)) !!}</div>@endif
<p class="small" style="margin-top:10px">Precios en UF sujetos a disponibilidad al momento de reservar. Imágenes y plantas referenciales. {{ $agency['legal_name'] }}{{ ! empty($agency['tax_id']) ? ' · RUT '.$agency['tax_id'] : '' }}{{ $project->sales_office ? ' · Sala de ventas: '.$project->sales_office : '' }}</p>
</body>
</html>
