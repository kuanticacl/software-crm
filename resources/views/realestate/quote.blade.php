@php
    use App\Services\RealEstate\QuoteView as V;
    use App\Models\Unit;
    $status = $q->effectiveStatus();
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cotización {{ $q->number }} · {{ $project->name }}</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.bunny.net"><link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800&display=swap" rel="stylesheet">
    <style>
        :root { --p:#6419DB; --p2:#4A10A8; --soft:#F0E9FD; --ink:#171B2E; --mut:#565E74; --line:#E3E6F2; --bg:#F8F9FF; --ok:#12B76A; }
        * { box-sizing:border-box; }
        html { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        body { margin:0; background:var(--bg); color:var(--ink); font-family:'Plus Jakarta Sans',Arial,Helvetica,sans-serif; font-size:15px; line-height:1.55; }
        .wrap { max-width:960px; margin:0 auto; padding:20px 16px 64px; }
        .top { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:6px 2px 18px; flex-wrap:wrap; }
        .top img { height:24px; }
        .pill { display:inline-flex; align-items:center; gap:8px; border-radius:999px; font-weight:700; font-size:14px; padding:10px 18px; text-decoration:none; border:1px solid var(--line); background:#fff; color:var(--ink); }
        .pill.primary { background:var(--p); border-color:var(--p); color:#fff; }
        .card { background:#fff; border:1px solid var(--line); border-radius:22px; padding:26px; margin-bottom:16px; }
        .hero { position:relative; overflow:hidden; border-radius:26px; margin-bottom:16px; min-height:300px; display:flex; align-items:flex-end; color:#fff;
            background:{!! $cover ? "#2A1F4F url('".$cover."') center/cover no-repeat" : 'linear-gradient(125deg,#4A10A8 0%,#6419DB 55%,#9B6BEF 100%)' !!}; }
        .hero::before { content:''; position:absolute; inset:0; background:linear-gradient(180deg,rgba(23,27,46,0) 25%,rgba(23,27,46,.82) 100%); }
        .hero > div { position:relative; padding:28px 30px; width:100%; }
        .tag { display:inline-block; background:rgba(255,255,255,.18); border:1px solid rgba(255,255,255,.35); backdrop-filter:blur(10px); border-radius:999px; padding:4px 12px; font-size:12px; font-weight:700; letter-spacing:.02em; }
        .hero h1 { margin:10px 0 4px; font-size:34px; line-height:1.1; font-weight:800; letter-spacing:-.02em; }
        .hero p { margin:0; opacity:.92; }
        .grid { display:grid; gap:16px; }
        @media (min-width:820px) { .grid.two { grid-template-columns:1.2fr 1fr; } }
        h2 { font-size:18px; margin:0 0 14px; letter-spacing:-.01em; }
        .muted { color:var(--mut); }
        .kv { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:10px; }
        .kv div { background:var(--bg); border:1px solid var(--line); border-radius:14px; padding:10px 12px; }
        .kv span { display:block; font-size:12px; color:var(--mut); }
        .kv strong { font-size:15px; }
        table { width:100%; border-collapse:collapse; font-size:14px; }
        th { text-align:left; font-size:12px; color:var(--mut); font-weight:600; padding:8px 6px; border-bottom:1px solid var(--line); }
        td { padding:10px 6px; border-bottom:1px solid var(--line); vertical-align:top; }
        td.r, th.r { text-align:right; white-space:nowrap; }
        .strike { text-decoration:line-through; color:var(--mut); font-size:12px; }
        .total { display:flex; justify-content:space-between; align-items:center; gap:12px; background:var(--p); color:#fff; border-radius:16px; padding:14px 18px; margin-top:12px; }
        .total strong { font-size:22px; }
        .rows > div { display:flex; justify-content:space-between; gap:12px; padding:8px 0; border-bottom:1px dashed var(--line); }
        .rows > div:last-child { border:0; }
        .rows .hl { color:var(--p2); font-weight:700; }
        .plan { width:100%; border-radius:16px; border:1px solid var(--line); background:#fff; object-fit:contain; max-height:520px; }
        .badge { display:inline-block; border-radius:999px; padding:3px 10px; font-size:12px; font-weight:700; color:#fff; }
        .note { font-size:12.5px; color:var(--mut); }
        .exec { display:flex; align-items:center; gap:12px; }
        .avatar { width:44px; height:44px; border-radius:50%; background:var(--soft); color:var(--p2); display:flex; align-items:center; justify-content:center; font-weight:800; }
        .draft { background:#FFF4E5; color:#9A4B00; border:1px solid #FDD8A7; border-radius:14px; padding:10px 14px; margin-bottom:14px; font-size:14px; }
        @media print { .noprint { display:none !important; } body { background:#fff; } .card { break-inside:avoid; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <img src="/brand/integraleads-logo-dark.png" alt="INTEGRALEADS">
        <div class="noprint" style="display:flex;gap:8px;flex-wrap:wrap">
            <a class="pill" href="{{ $pdfUrl }}">Descargar PDF</a>
            @if ($owner?->phone)<a class="pill primary" href="https://wa.me/{{ preg_replace('/\D/', '', $owner->phone) }}?text={{ rawurlencode('Hola, te escribo por la cotización '.$q->number.' de '.$project->name) }}" target="_blank" rel="noopener">Hablar con mi ejecutivo</a>@endif
        </div>
    </div>

    @if ($status === 'draft')<div class="draft">Borrador · vista previa. Esta cotización aún no ha sido enviada.</div>@endif
    @if ($status === 'expired')<div class="draft">Esta cotización venció el {{ $validText }}. Pide a tu ejecutivo una actualización de precios y disponibilidad.</div>@endif

    <section class="hero">
        <div>
            <span class="tag">Cotización N.º {{ $q->number }}</span>
            <h1>{{ $project->name }}</h1>
            <p>{{ collect([$project->location(), $project->developer])->filter()->implode(' · ') }}@if ($project->delivery_note || $project->delivery_date) · Entrega {{ $project->delivery_note ?: $project->delivery_date?->locale('es')->translatedFormat('F Y') }}@endif</p>
        </div>
    </section>

    <div class="grid two">
        <section class="card">
            <h2>{{ $main?->label() }}</h2>
            @if ($mainUnit)
                <div class="kv" style="margin-bottom:14px">
                    @if ($mainUnit->programLabel())<div><span>Programa</span><strong>{{ $mainUnit->programLabel() }}</strong></div>@endif
                    @if ($mainUnit->model)<div><span>Modelo</span><strong>{{ $mainUnit->model }}</strong></div>@endif
                    @if ($mainUnit->floor !== null)<div><span>Piso</span><strong>{{ $mainUnit->floor }}</strong></div>@endif
                    @if ($mainUnit->orientation)<div><span>Orientación</span><strong>{{ $mainUnit->orientation }}</strong></div>@endif
                    @if ($mainUnit->surface_useful)<div><span>Sup. útil</span><strong>{{ V::m2($mainUnit->surface_useful) }}</strong></div>@endif
                    @if ($mainUnit->surface_terrace)<div><span>Terraza</span><strong>{{ V::m2($mainUnit->surface_terrace) }}</strong></div>@endif
                    @if ($mainUnit->surface_total)<div><span>Sup. total</span><strong>{{ V::m2($mainUnit->surface_total) }}</strong></div>@endif
                    @if ($mainUnit->surface_lot)<div><span>Terreno</span><strong>{{ V::m2($mainUnit->surface_lot) }}</strong></div>@endif
                </div>
            @endif
            @foreach ($plans as $u)
                <img class="plan" src="{{ $u->floorPlanUrl() }}" alt="Planta {{ $u->label() }}" style="margin-top:8px">
                <p class="note" style="margin:6px 0 0">Planta referencial · {{ $u->label() }}{{ $u->model ? ' · modelo '.$u->model : '' }}</p>
            @endforeach
            @if ($plans->isEmpty())<p class="muted">Pide a tu ejecutivo la planta de esta unidad.</p>@endif
        </section>

        <div>
            <section class="card">
                <h2>Detalle de precios</h2>
                <table>
                    <thead><tr><th>Unidad</th><th class="r">Precio</th></tr></thead>
                    <tbody>
                    @foreach ($items as $i)
                        <tr>
                            <td><strong>{{ $i->label() }}</strong>@if ($i->detail)<br><span class="note">{{ $i->detail }}</span>@endif</td>
                            <td class="r">@if ($i->discount_pct > 0)<span class="strike">{{ V::uf($i->list_price_uf) }}</span><br><span class="note">−{{ rtrim(rtrim(number_format($i->discount_pct, 2, ',', ''), '0'), ',') }}%</span><br>@endif<strong>{{ $i->price_uf > 0 ? V::uf($i->price_uf) : 'Incluido' }}</strong></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @if ($q->discount_uf > 0)<p class="note" style="margin:10px 0 0">Ahorro total: <strong style="color:var(--ok)">{{ V::uf($q->discount_uf) }}</strong></p>@endif
                <div class="total"><span>Valor total</span><span style="text-align:right"><strong>{{ V::uf($q->total_uf) }}</strong>@if ($ufText)<br><span style="font-size:13px;opacity:.85">≈ {{ V::clp($q->clp($q->total_uf)) }}</span>@endif</span></div>
            </section>

            <section class="card">
                <h2>Forma de pago</h2>
                <div class="rows">
                    <div><span>Reserva</span><strong>{{ V::clp($q->reservation_amount) }}</strong></div>
                    <div><span>Pie ({{ rtrim(rtrim(number_format($q->pie_pct, 2, ',', ''), '0'), ',') }}%)</span><strong>{{ V::uf($q->pie_uf) }}</strong></div>
                    @if ($q->pie_installments > 0 && $pieRest > 0)
                        <div class="hl"><span>Pie en {{ $q->pie_installments }} cuotas</span><span>{{ V::uf($installment) }} / mes@if ($ufText) <span class="note">(≈ {{ V::clp($installment * $q->uf_value) }})</span>@endif</span></div>
                    @endif
                    <div><span>Saldo con crédito hipotecario</span><strong>{{ V::uf($credit) }}</strong></div>
                    @if ($dividend > 0)
                        <div class="hl"><span>Dividendo estimado · {{ $q->mortgage_years }} años al {{ rtrim(rtrim(number_format($q->mortgage_rate, 2, ',', ''), '0'), ',') }}%</span><span>{{ V::uf($dividend) }} / mes@if ($ufText) <span class="note">(≈ {{ V::clp($dividend * $q->uf_value) }})</span>@endif</span></div>
                    @endif
                </div>
                <p class="note" style="margin:12px 0 0">El pie descuenta lo pagado en la reserva. El dividendo es una simulación referencial sin seguros: el banco define la tasa y las condiciones finales.</p>
            </section>
        </div>
    </div>

    <section class="card">
        <div class="grid two" style="align-items:center">
            <div class="exec">
                <div class="avatar">{{ mb_strtoupper(mb_substr($owner?->name ?? 'I', 0, 1)) }}</div>
                <div><strong>{{ $owner?->name ?? 'Equipo de ventas' }}</strong><br><span class="muted">Ejecutivo/a de ventas · {{ $project->name }}</span>
                    @if ($owner)<br><span class="note">{{ collect([$owner->email, $owner->phone])->filter()->implode(' · ') }}</span>@endif</div>
            </div>
            <div class="kv">
                <div><span>Emitida</span><strong>{{ $issued }}</strong></div>
                @if ($validText)<div><span>Válida hasta</span><strong>{{ $validText }}</strong></div>@endif
                @if ($ufText)<div><span>UF de referencia{{ $ufDate ? ' · '.$ufDate : '' }}</span><strong>{{ $ufText }}</strong></div>@endif
            </div>
        </div>
        @if (! empty($buyer['name']))<p class="muted" style="margin:16px 0 0">Preparada para <strong style="color:var(--ink)">{{ $buyer['name'] }}</strong>{{ ! empty($buyer['rut']) ? ' · RUT '.$buyer['rut'] : '' }}</p>@endif
        @if ($q->notes)<p style="margin:14px 0 0">{!! nl2br(e($q->notes)) !!}</p>@endif
        @if ($project->sales_office)<p class="note" style="margin:10px 0 0">Sala de ventas: {{ $project->sales_office }}</p>@endif
    </section>

    @if ($project->quote_conditions)
        <section class="card note">{!! nl2br(e($project->quote_conditions)) !!}</section>
    @endif
    <p class="note" style="text-align:center">Precios en UF sujetos a disponibilidad al momento de reservar. Imágenes y plantas referenciales. {{ $agency['legal_name'] }}{{ ! empty($agency['tax_id']) ? ' · RUT '.$agency['tax_id'] : '' }}</p>
</div>
</body>
</html>
