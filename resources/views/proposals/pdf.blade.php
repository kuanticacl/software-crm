@php
    use App\Services\Proposals\ProposalView;
    use App\Models\Proposal;
    $m = fn ($n) => ProposalView::money($n, $currency);
    $ufFmt = ProposalView::ufValue((float) $p->uf_value);
    $clpGross = ProposalView::clp($p->clp($p->total_gross));
    $img = fn ($path) => 'file://'.$public.'/'.$path;
    $heroFile = \App\Support\Theme::heroPath();
    $n = 0;
    $icon = ['instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'facebook' => 'Facebook', 'youtube' => 'YouTube', 'tiktok' => 'TikTok'];
@endphp
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $p->title }} · Quiebre</title>
<style>
    @font-face { font-family:'Asap'; font-weight:400; font-style:normal; src:url('file://{{ $fonts }}/Asap-400.ttf') format('truetype'); }
    @font-face { font-family:'Asap'; font-weight:600; font-style:normal; src:url('file://{{ $fonts }}/Asap-600.ttf') format('truetype'); }
    @font-face { font-family:'Asap'; font-weight:700; font-style:normal; src:url('file://{{ $fonts }}/Asap-700.ttf') format('truetype'); }
    @page { margin: 30px 34px 52px 34px; }
    * { box-sizing: border-box; }
    body { font-family:'Asap', Arial, sans-serif; font-size:10.5px; line-height:1.55; color:#393939; margin:0; }
    h2 { font-size:15px; margin:0 0 8px; color:#393939; }
    p { margin:0 0 6px; } ul { margin:0 0 6px; padding-left:16px; } li { margin:2px 0; }
    .mut { color:#707070; } .or { color:#FF5300; } .b { font-weight:700; }
    .card { border:1px solid #E8E8E8; border-radius:14px; padding:16px 18px; margin-bottom:12px; page-break-inside:avoid; }
    .h2t { width:auto; border-collapse:collapse; margin:0 0 8px; } .h2t td { padding:0; vertical-align:middle; }
    .h2t .num { width:22px; height:22px; border-radius:7px; background:#FFF0E8; color:#FF5300; font-size:10px; font-weight:700; text-align:center; vertical-align:middle; line-height:1; padding:0; }
    .h2t .tt { padding-left:8px; font-size:15px; font-weight:700; color:#393939; line-height:1.2; }
    table { width:100%; border-collapse:collapse; }
    .cover { width:100%; border-radius:18px; background-color:#F4F4F4; background-image:url('{{ $heroFile ? 'file://'.$heroFile : $img('brand/proposal-cover.jpg') }}'); background-repeat:no-repeat; background-position:right center; background-size:auto 100%; margin-bottom:12px; }
    .chip { background:rgba(255,255,255,.75); border:1px solid #E6E6E6; border-radius:10px; padding:6px 10px; }
    .chip .l { font-size:7.5px; text-transform:uppercase; letter-spacing:.06em; color:#8A8A8A; } .chip .v { font-size:11px; font-weight:700; color:#393939; }
    .chip.pu { background:rgba(100,25,219,.88); border-color:rgba(255,255,255,.3); } .chip.pu .l { color:#E4D6FB; } .chip.pu .v { color:#fff; }
    .tag { background:rgba(255,255,255,.75); border:1px solid #E6E6E6; border-radius:20px; padding:3px 11px; font-size:9px; font-weight:600; color:#707070; }
    .info td { width:33%; padding:0 6px 8px 0; vertical-align:top; } .info .l { font-size:7.5px; text-transform:uppercase; letter-spacing:.05em; color:#8A8A8A; } .info .v { font-weight:600; }
    .items th { text-align:left; font-size:8px; text-transform:uppercase; letter-spacing:.05em; color:#8A8A8A; padding:0 6px 5px; border-bottom:2px solid #FF5300; }
    .items td { padding:8px 6px; border-bottom:1px solid #EDEDED; vertical-align:top; }
    .r { text-align:right; white-space:nowrap; }
    .grp { font-weight:700; margin:12px 0 5px; } .dot { color:#FF5300; }
    .idesc { color:#707070; font-size:9.5px; } .deliv { color:#707070; font-size:9.5px; margin:3px 0 0; }
    .kpi { background:#FFF3EC; border-radius:12px; padding:9px 12px; } .kpi.p { background:#F1EAFD; }
    .kpi .l { font-size:7.5px; text-transform:uppercase; letter-spacing:.05em; color:#707070; } .kpi .v { font-size:17px; font-weight:700; color:#FF5300; } .kpi.p .v { color:#6419DB; }
    .tot td { padding:3px 0; } .grand td { background:#FF5300; color:#fff; font-weight:700; font-size:12.5px; padding:9px 12px; }
    .sign td { vertical-align:top; padding-right:18px; } .line { border-bottom:1px solid #9A9A9A; height:34px; } .cap { font-size:8px; color:#8A8A8A; margin-top:3px; }
    .tech { text-align:center; } .tech .cap { margin-bottom:5px; text-transform:uppercase; letter-spacing:.08em; }
    .foot { border:1px solid #E8E8E8; border-radius:14px; padding:16px 18px; page-break-inside:avoid; }
    .hold-t { font-size:8px;font-weight:600; text-transform:uppercase; letter-spacing:.08em; color:#707070; border-bottom:1px solid #FF5300; padding-bottom:5px; margin-bottom:8px; text-align:center; }
</style>
</head>
<body>

<table class="cover"><tr><td style="padding:24px 28px 26px;height:215px;vertical-align:top">
    <img src="{{ $img('brand/quiebre-logo-dark.png') }}" style="height:19px" alt="Quiebre">
    <div style="margin-top:26px"><span class="tag">Propuesta comercial · N.º {{ $p->number }}</span></div>
    <div style="font-size:25px;font-weight:600;line-height:1.15;margin:11px 0 5px;width:55%;color:#FF5300">{{ $p->title }}</div>
    <div style="font-size:12px;color:#707070">Preparada para <b style="color:#393939">{{ $company }}</b></div>
    <table style="margin-top:18px;width:auto"><tr>
        <td class="chip"><div class="l">Fecha</div><div class="v">{{ $issued }}</div></td><td style="width:7px"></td>
        @if ($valid)<td class="chip"><div class="l">Válida hasta</div><div class="v">{{ $valid }}</div></td><td style="width:7px"></td>@endif
        @if ($owner)<td class="chip"><div class="l">Responsable</div><div class="v">{{ $owner->name }}</div></td><td style="width:7px"></td>@endif
        @if ($isUf)<td class="chip"><div class="l">UF de referencia · {{ $ufDate }}</div><div class="v">{{ $ufFmt }}</div></td><td style="width:7px"></td>@endif
        @if ($p->total_gross > 0)<td class="chip pu"><div class="l">Total con IVA</div><div class="v">{{ $m($p->total_gross) }}</div></td>@endif
    </tr></table>
</td></tr></table>

@if (array_filter($r))
<div class="card">
    <h2>Datos del cliente</h2>
    @php $fields = collect([['Razón social','legal_name'],['RUT','rut'],['Nombre de fantasía','company'],['Giro','activity'],['Dirección','address'],['Contacto','contact_name'],['Cargo','contact_role'],['Correo','email'],['Teléfono','phone']])->filter(fn ($f) => ! empty($r[$f[1]]))->values(); @endphp
    <table class="info">
        @foreach ($fields->chunk(3) as $row)
            <tr>@foreach ($row as [$label, $key])<td><div class="l">{{ $label }}</div><div class="v">{{ $r[$key] }}</div></td>@endforeach @for ($k = $row->count(); $k < 3; $k++)<td></td>@endfor</tr>
        @endforeach
    </table>
</div>
@endif

@foreach ($sections as $i => $s)
    @php $n++; @endphp
    <div class="card">
        <table class="h2t"><tr><td class="num">{{ $n }}</td><td class="tt">{{ $s['title'] }}</td></tr></table>
        <div>{!! $s['body'] !!}</div>
    </div>
    @if ($servicesAt === $i) @include('proposals._pdf_services') @php $n++; @endphp @endif
@endforeach
@if ($servicesAt === false || $servicesAt === null) @include('proposals._pdf_services') @php $n++; @endphp @endif

{{-- Firma --}}
<div class="card" style="margin-top:4px">
    <h2>Firma y aceptación</h2>
    <p class="mut" style="margin-bottom:10px">Atentamente, el equipo de Quiebre. Para aceptar esta propuesta basta con firmarla y devolverla, o usar el enlace en línea.</p>
    <table class="sign"><tr>
        <td style="width:50%">
            <div class="line"></div>
            <div class="b" style="margin-top:4px">{{ $owner->name ?? 'Equipo Quiebre' }}</div>
            <div class="cap">{{ $agency['legal_name'] }} · RUT {{ $agency['tax_id'] }}<br>{{ $owner->email ?? $agency['email'] }}</div>
        </td>
        <td style="width:50%">
            @if ($p->status === 'accepted')
                @if ($p->signature_data)
                    <div class="line" style="text-align:center;padding-top:4px;height:46px"><img src="{{ $p->signature_data }}" style="height:42px" alt="Firma"></div>
                @else
                    <div class="line" style="text-align:center;padding-top:14px;color:#0D9F85;font-weight:700">ACEPTADA EN LÍNEA</div>
                @endif
                <div class="b" style="margin-top:4px">{{ $p->responded_by }}</div>
                <div class="cap">@if ($p->signer_rut)RUT {{ $p->signer_rut }} · @endif{{ $p->responded_at?->locale('es')->translatedFormat('d \d\e F \d\e Y, H:i') }}@if ($p->signature_data) · firmada en línea @endif</div>
            @else
                <div class="line"></div>
                <div class="b" style="margin-top:4px">{{ $r['contact_name'] ?? 'Nombre y firma del cliente' }}</div>
                <div class="cap">{{ $r['legal_name'] ?? $company }}@if (! empty($r['rut'])) · RUT {{ $r['rut'] }}@endif<br>Fecha: ______ / ______ / ____________</div>
            @endif
        </td>
    </tr></table>
</div>

{{-- Pie: holding (izquierda) · ubicación y redes (derecha) --}}
<div class="foot">
    <table><tr>
        <td style="width:50%;vertical-align:top;padding-right:16px">
            <div class="hold-t" style="text-align:left">Parte del holding</div>
            <table style="width:auto"><tr>
                @foreach ($holding as $h)<td style="padding:0;width:92px"><a href="{{ $h['url'] }}" style="text-decoration:none"><img src="{{ $img('brand/partners/'.$h['logo'].'.png') }}" style="height:28px;border:0" alt="{{ $h['name'] }}"></a></td>@endforeach
            </tr></table>
            <div class="mut" style="font-size:8.5px;margin-top:8px">Quiebre es parte de un holding de empresas de tecnología y marketing para el sector inmobiliario.<br>@foreach ($holding as $h)<a href="{{ $h['url'] }}" style="color:inherit;text-decoration:none">{{ preg_replace('#^https?://(www\.)?#', '', $h['url']) }}</a>@if (! $loop->last) · @endif @endforeach</div>
        </td>
        <td style="width:50%;vertical-align:top">
            <div class="hold-t" style="text-align:left">Dónde estamos</div>
            <div class="b">{{ $agency['legal_name'] }} <span class="mut" style="font-weight:400">· RUT {{ $agency['tax_id'] }}</span></div>
            <div class="mut">{{ $agency['address'] }}</div>
            <div class="mut">{{ $agency['email'] }}@if ($agency['phone']) · {{ $agency['phone'] }}@endif</div>
            <div class="or b">{{ preg_replace('#^https?://#', '', rtrim($agency['website'], '/')) }}</div>
            @if ($socials)<div class="mut" style="margin-top:4px;font-size:9px">@foreach ($socials as $k => $sc){{ $sc['label'] }}: {{ preg_replace('#^https?://(www\.)?#', '', rtrim($sc['url'], '/')) }}@if (! $loop->last) · @endif @endforeach</div>@endif
        </td>
    </tr></table>
</div>

{{-- Tecnologías: al final de todo, pequeño y sutil --}}
<div class="tech" style="margin-top:14px">
    <div class="cap" style="font-size:7px;color:#A8A8A8">Tecnologías con las que trabajamos</div>
    <table style="width:auto;margin:0 auto"><tr>
        @foreach ($tech as $t)<td style="padding:0 6px"><img src="{{ $img('brand/tech/'.$t.'.png') }}" style="height:11px" alt="{{ $t }}"></td>@endforeach
    </tr></table>
</div>

</body>
</html>
