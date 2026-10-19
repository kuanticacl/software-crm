@php
    use App\Support\ProposalText;
    use App\Models\Proposal;

    $r = $p->recipient ?? [];
    $money = fn ($n) => \App\Services\Proposals\ProposalView::money($n, $p->currency);
    $currency = $p->currency;
    $isUf = $p->currency === 'UF' && $p->uf_value > 0;
    $ufDate = $p->uf_date ? \Illuminate\Support\Carbon::parse($p->uf_date)->locale('es')->translatedFormat('d \\d\\e F \\d\\e Y') : null;
    $ufFmt = \App\Services\Proposals\ProposalView::ufValue((float) $p->uf_value);
    $clpGross = \App\Services\Proposals\ProposalView::clp($p->clp($p->total_gross));
    $months = max(1, (int) ($p->contract_months ?: 1));
    $items = $p->items;
    $once = $items->where('billing', 'one_time');
    $monthly = $items->where('billing', 'monthly');
    $status = $p->effectiveStatus();
    $sections = collect($p->sections ?? [])->map(fn ($s) => ['title' => $s['title'], 'body' => ProposalText::html(ProposalText::fill($s['body'], $r))])->values();
    $servicesAt = $sections->search(fn ($s) => preg_match('/alcance|servicio/i', $s['title']));
    $subOnce = round($once->sum(fn ($i) => $i->lineTotal($p->decimals())), $p->decimals());
    $subMonthly = round($monthly->sum(fn ($i) => $i->lineTotal($p->decimals())), $p->decimals());
    $discount = round(($subOnce + $subMonthly * $months) - ($p->total_one_time + $p->total_monthly * $months), $p->decimals());
    $date = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->locale('es')->translatedFormat('d \d\e F \d\e Y') : null;
    $company = $r['company'] ?? $r['legal_name'] ?? 'Cliente';
    $agency = \App\Support\Agency::profile();
    $socials = \App\Support\Agency::socials();
    $tech = \App\Support\Agency::tech();
    $holding = \App\Support\Agency::holding();
    $owner = $p->owner;
    $pdfUrl = ($public ?? false) ? route('proposals.public.pdf', $p->public_token) : (($preview ?? false) ? null : route('proposals.pdf', $p->id));
    $asset = fn ($f) => asset($f);
    $heroUrl = \App\Support\Theme::heroUrl();
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $p->title }} · MecaniQ</title>
    @if ($public ?? false)<meta name="robots" content="noindex,nofollow">@endif
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Asap:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --o:{{ \App\Support\Theme::primary() ?? '#027BB9' }}; --o2:#7CD0F5; --ink:#0B1522; --mut:#5B6B7C; --line:#DFE6ED; --bg:#F3F6F9; --soft:{{ \App\Support\Theme::accent() ?? '#E1F2FA' }}; }
        * { box-sizing: border-box; }
        html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body { margin:0; background:var(--bg); color:var(--ink); font-family:'Asap',Arial,Helvetica,sans-serif; font-size:15px; line-height:1.6; }
        .wrap { max-width:880px; margin:0 auto; padding:24px 16px 64px; }
        .top { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:8px 4px 20px; }
        .top svg { height:26px; width:auto; color:var(--ink); }
        .pill { display:inline-flex; align-items:center; gap:8px; border-radius:999px; font-weight:600; font-family:inherit; font-size:14px; padding:10px 20px; text-decoration:none; cursor:pointer; border:1px solid var(--line); background:#fff; color:var(--ink); }
        .pill.primary { background:var(--o); border-color:var(--o); color:#fff; }
        .pill:hover { filter:brightness(.97); }
        .card { background:#fff; border-radius:24px; padding:32px; margin-bottom:16px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
        .cover { position:relative; overflow:hidden; border-radius:28px; margin-bottom:16px; padding:34px 40px 36px; min-height:340px; background:{!! $heroUrl ? "#F3F6F9 url('".$heroUrl."') center/cover no-repeat" : 'linear-gradient(120deg,#F3F6F9 0%,#BFE3F4 55%,#7CD0F5 100%)' !!}; box-shadow:0 1px 2px rgba(0,0,0,.05); }
        .cover::before { content:''; position:absolute; {!! $heroUrl ? 'inset:0; background:linear-gradient(90deg,rgba(243,246,249,.94) 0%,rgba(243,246,249,.78) 48%,rgba(243,246,249,.15) 100%);' : 'right:-60px; bottom:-70px; width:300px; height:300px; border-radius:50%; background:rgba(255,255,255,.28);' !!} }
        .cover > * { position:relative; }
        .cover .logo { height:24px; width:auto; display:block; }
        .cover .tag { display:inline-block; margin-top:34px; background:rgba(255,255,255,.6); backdrop-filter:blur(14px); -webkit-backdrop-filter:blur(14px); border:1px solid rgba(255,255,255,.9); box-shadow:0 2px 10px rgba(0,0,0,.05); border-radius:999px; padding:4px 14px; font-size:13px; font-weight:600; color:var(--mut); }
        .cover h1 { font-family:'Asap',Arial,sans-serif; letter-spacing:-.01em; margin:14px 0 6px; font-size:38px; line-height:1.12; font-weight:600; color:var(--o); max-width:520px; }
        .cover p { margin:0; font-size:18px; color:var(--mut); max-width:520px; } .cover p strong { color:var(--ink); }
        .cover .meta { display:flex; flex-wrap:wrap; gap:10px; margin-top:26px; max-width:640px; }
        .glass { padding:7px 12px; background:rgba(255,255,255,.55); backdrop-filter:blur(16px); -webkit-backdrop-filter:blur(16px); border:1px solid rgba(255,255,255,.9); box-shadow:0 4px 18px rgba(0,0,0,.06); border-radius:14px; padding:8px 14px; }
        .glass.purple { background:rgba(11,21,34,.82); border-color:rgba(255,255,255,.25); box-shadow:0 8px 26px rgba(11,21,34,.28); color:#fff; }
        .glass span { display:block; font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--mut); } .glass strong { font-size:15px; } .glass.purple span { color:#B9DDEE; }
        h2 { margin:0 0 12px; font-size:21px; line-height:1.25; display:flex; align-items:center; gap:12px; }
        h2 .n { flex:none; width:32px; height:32px; border-radius:11px; background:var(--soft); color:var(--o); font-size:14px; font-weight:700; display:grid; place-items:center; }
        .body p { margin:0 0 10px; } .body ul { margin:0 0 10px; padding-left:20px; } .body li { margin:3px 0; } .body li::marker { color:var(--o); }
        .body a { color:var(--o); }
        dl.info { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px 24px; margin:0; }
        dl.info dt { font-size:12px; color:var(--mut); text-transform:uppercase; letter-spacing:.05em; } dl.info dd { margin:0; font-weight:600; }
        table { width:100%; border-collapse:collapse; }
        th { text-align:left; font-size:12px; color:var(--mut); text-transform:uppercase; letter-spacing:.05em; padding:0 10px 8px; border-bottom:2px solid var(--o); }
        td { padding:12px 10px; border-bottom:1px solid var(--line); vertical-align:top; }
        td.r, th.r { text-align:right; white-space:nowrap; }
        .iname { font-weight:700; } .idesc { color:var(--mut); font-size:13.5px; margin-top:2px; }
        .deliv { margin:6px 0 0; padding-left:18px; font-size:13.5px; color:var(--mut); } .deliv li::marker { color:var(--o); }
        .grp { display:flex; align-items:center; gap:10px; margin:22px 0 8px; font-weight:700; }
        .grp .dot { width:10px; height:10px; border-radius:50%; background:var(--o); } .grp small { font-weight:500; color:var(--mut); }
        .totals { margin:24px 0 0 auto; max-width:380px; }
        .totals div { display:flex; justify-content:space-between; padding:5px 0; } .totals .sep { border-top:1px solid var(--line); margin-top:6px; padding-top:10px; }
        .totals .grand { background:var(--o); color:#fff; border-radius:16px; padding:14px 18px; margin-top:10px; font-size:18px; font-weight:700; }
        .kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; margin-bottom:8px; }
        .kpi { background:var(--soft); border:1px solid rgba(2,123,185,.12); border-radius:18px; padding:16px 18px; } .kpi.p { background:rgba(11,21,34,.07); border-color:rgba(11,21,34,.16); } .kpi.p strong { color:#0B1522; } .kpi span { font-size:12px; color:var(--mut); text-transform:uppercase; letter-spacing:.05em; } .kpi strong { display:block; font-size:24px; color:var(--o); line-height:1.2; }
        .accept { border:2px solid var(--o); } .accept form { display:grid; gap:12px; margin-top:12px; }
        .accept input, .accept textarea { font:inherit; width:100%; border:1px solid var(--line); border-radius:14px; padding:11px 14px; background:#fff; color:var(--ink); }
        .accept input:focus, .accept textarea:focus { outline:2px solid var(--o2); border-color:var(--o); }
        .actions { display:flex; flex-wrap:wrap; gap:10px; }
        .badge { display:inline-block; border-radius:999px; padding:4px 14px; font-weight:600; font-size:13px; color:#fff; }
        .sign { display:grid; grid-template-columns:1fr 1fr; gap:32px; margin-top:14px; } .sign .line { border-bottom:1px solid #B5B5B5; height:42px; } .sign .cap { font-size:12px; color:var(--mut); margin-top:6px; }
        .ficha { background:#fff; border-radius:24px; padding:30px 34px; box-shadow:0 0 15px rgba(0,0,0,.08); display:grid; grid-template-columns:1fr 1fr; gap:36px; }
        .ficha h3 { margin:0 0 12px; font-size:13px; text-transform:uppercase; letter-spacing:.08em; color:var(--mut); border-bottom:1px solid var(--o); padding-bottom:8px; }
        .holding { display:flex; flex-wrap:nowrap; align-items:center; gap:4px; margin-left:-8px; } .holding a { display:block; } .holding img { height:40px; width:auto; display:block; }
        .hold-note { margin:12px 0 0; font-size:12.5px; color:var(--mut); }
        .where { display:grid; gap:7px; font-size:14px; } .where div { display:flex; gap:9px; align-items:flex-start; } .where svg { flex:none; width:16px; height:16px; margin-top:3px; color:var(--o); stroke:currentColor; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; } .where a { color:inherit; text-decoration:none; }
        .soc { display:flex; gap:8px; margin-top:12px; } .soc a { width:34px; height:34px; border-radius:12px; display:grid; place-items:center; color:var(--o); background:rgba(2,123,185,.08); border:1px solid rgba(2,123,185,.18); } .soc svg { width:16px; height:16px; stroke:currentColor; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
        .tech { text-align:center; margin:22px 0 4px; } .tech p { margin:0 0 10px; font-size:11px; text-transform:uppercase; letter-spacing:.1em; color:#A8B3BF; }
        .tech div { display:flex; flex-wrap:wrap; justify-content:center; align-items:center; gap:10px 22px; } .tech img { height:16px; width:auto; opacity:.9; }
        .legal { text-align:center; color:#A8B3BF; font-size:12px; margin-top:14px; }
        @media (max-width:640px) { .sign, .ficha { grid-template-columns:1fr; } }
        .flash { background:#E7F8F3; color:#0A7A66; border-radius:16px; padding:12px 18px; margin-bottom:16px; font-weight:600; }
        .err { background:#FDECEC; color:#B42318; border-radius:16px; padding:12px 18px; margin-bottom:16px; }
        .vp { position:fixed; right:12px; bottom:12px; background:#0B1522; color:#fff; border-radius:999px; padding:6px 14px; font-size:12px; opacity:.85; }
        @media (max-width:640px) { .card { padding:22px 18px; border-radius:20px; } .cover { padding:32px 22px; } .cover h1 { font-size:27px; } th.hide, td.hide { display:none; } }
        @media print {
            body { background:#fff; font-size:13.5px; } .wrap { max-width:none; padding:0; } .noprint, .vp { display:none !important; }
            .card, .cover { box-shadow:none; break-inside:avoid; margin-bottom:12px; } .card { border:1px solid var(--line); } tr { break-inside:avoid; }
            @page { size:A4; margin:14mm; }
        }
            .accept .row2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; } @media (max-width:600px) { .accept .row2 { grid-template-columns:1fr; } }
        .sigbox { position:relative; border:1.5px dashed #C9D1DD; border-radius:16px; background:#fff; } .sigbox canvas { display:block; width:100%; height:200px; touch-action:none; cursor:crosshair; border-radius:16px; }
        .sighint { position:absolute; inset:0; display:grid; place-items:center; pointer-events:none; color:#9AA6BA; font-size:14px; } .sigbox .small { position:absolute; right:10px; bottom:10px; font-size:12px; padding:6px 12px; }
        .pill.danger { color:#DC2626; border-color:rgba(220,38,38,.35); }
    </style>
</head>
<body>
<div class="wrap">
    @if (($public ?? false) && $p->status === 'draft')
        <div style="margin:0 0 14px;padding:10px 16px;border-radius:14px;background:#FEF3C7;color:#92400E;font-size:13px;text-align:center">Borrador · vista previa. Esta propuesta aún no se ha enviado: el contenido puede cambiar.</div>
    @endif
    @if (session('proposal_ok'))<div class="flash noprint">{{ session('proposal_ok') }}</div>@endif
    @if (isset($errors) && $errors->any())<div class="err noprint">{{ $errors->first() }}</div>@endif

    <div class="top noprint">
        <span></span>
        <div class="actions">
            @if ($pdfUrl)<a class="pill primary" href="{{ $pdfUrl }}">Descargar PDF</a>@endif
        </div>
    </div>

    <section class="cover">
        <img class="logo" src="{{ $asset('brand/mecaniq-logo.png') }}" alt="MecaniQ">
        <span class="tag">Propuesta comercial · N.º {{ $p->number }}</span>
        <h1>{{ $p->title }}</h1>
        <p>Preparada para <strong>{{ $company }}</strong></p>
        <div class="meta">
            <div class="glass"><span>Fecha</span><strong>{{ $date($p->issued_at ?? now()) }}</strong></div>
            @if ($p->valid_until)<div class="glass"><span>Válida hasta</span><strong>{{ $date($p->valid_until) }}</strong></div>@endif
            @if ($p->owner)<div class="glass"><span>Responsable</span><strong>{{ $p->owner->name }}</strong></div>@endif
            @if ($isUf)<div class="glass"><span>UF de referencia · {{ $ufDate }}</span><strong>{{ $ufFmt }}</strong></div>@endif
            @if ($p->total_gross > 0)<div class="glass purple"><span>{{ $p->taxLabels()['gross'] }}</span><strong>{{ $money($p->total_gross) }}</strong>@if ($isUf)<small style="display:block;font-size:11px;opacity:.85">≈ {{ $clpGross }}</small>@endif</div>@endif
        </div>
    </section>

    @if (array_filter($r))
    <section class="card">
        <h2>Datos del cliente</h2>
        <dl class="info">
            @foreach ([['Razón social','legal_name'],['RUT','rut'],['Nombre de fantasía','company'],['Giro','activity'],['Dirección','address'],['Contacto','contact_name'],['Cargo','contact_role'],['Correo','email'],['Teléfono','phone']] as [$label,$key])
                @if (! empty($r[$key]))<div><dt>{{ $label }}</dt><dd>{{ $r[$key] }}</dd></div>@endif
            @endforeach
        </dl>
    </section>
    @endif

    @php $n = 0; @endphp

    @foreach ($sections as $i => $s)
        @php $n++; @endphp
        <section class="card">
            <h2><span class="n">{{ $n }}</span>{{ $s['title'] }}</h2>
            <div class="body">{!! $s['body'] !!}</div>
        </section>
        @if ($servicesAt === $i) @include('proposals._services') @php $n++; @endphp @endif
    @endforeach
    @if ($servicesAt === false || $servicesAt === null) @include('proposals._services') @endif

    @if (true)
        @if (in_array($status, ['accepted', 'rejected']))
            <section class="card" style="border:2px solid {{ Proposal::STATUS_COLORS[$status] }}">
                <span class="badge" style="background:{{ Proposal::STATUS_COLORS[$status] }}">{{ Proposal::STATUSES[$status] }}</span>
                <p style="margin:10px 0 0"><strong>{{ $p->responded_by }}</strong>@if ($p->signer_rut) · RUT {{ $p->signer_rut }}@endif · {{ $p->responded_at?->locale('es')->translatedFormat('d \d\e F \d\e Y, H:i') }}</p>
                @if ($p->response_note)<p style="margin:6px 0 0;color:var(--mut)">{{ $p->response_note }}</p>@endif
            </section>
        @elseif ($status === 'expired')
            <section class="card"><span class="badge" style="background:{{ Proposal::STATUS_COLORS['expired'] }}">Vencida</span><p style="margin:10px 0 0">Esta propuesta venció el {{ $date($p->valid_until) }}. Escríbenos para actualizarla.</p></section>
        @elseif (($public ?? false) && $status !== 'draft')
            @if ($status === 'changes_requested')
                <section class="card" style="border:2px solid {{ Proposal::STATUS_COLORS['changes_requested'] }}">
                    <span class="badge" style="background:{{ Proposal::STATUS_COLORS['changes_requested'] }}">Ajustes solicitados</span>
                    <p style="margin:10px 0 0"><strong>{{ $p->responded_by }}</strong> · {{ $p->responded_at?->locale('es')->translatedFormat('d \d\e F \d\e Y, H:i') }}</p>
                    @if ($p->response_note)<p style="margin:6px 0 0;color:var(--mut)">«{{ $p->response_note }}»</p>@endif
                    <p style="margin:8px 0 0;color:var(--mut)">Estamos preparando una nueva versión. Si ya estás conforme con esta, también puedes firmarla abajo.</p>
                </section>
            @endif
            <section class="card accept noprint" id="responder">
                <h2>Tu respuesta</h2>
                <p style="margin:0;color:var(--mut)">Puedes <strong>firmar y aceptar</strong> la propuesta, <strong>solicitar ajustes</strong> o <strong>rechazarla</strong>. Tu respuesta queda registrada con fecha y hora, y nuestro equipo se pondrá en contacto.</p>
                <form method="post" action="{{ route('proposals.public.respond', $p->public_token) }}" id="resp-form">
                    @csrf
                    <input type="hidden" name="signature" id="resp-signature">
                    <div class="row2">
                        <input name="name" required maxlength="160" placeholder="Tu nombre completo" value="{{ old('name', $r['contact_name'] ?? '') }}">
                        <input name="rut" maxlength="20" placeholder="Tu RUT (opcional)" value="{{ old('rut') }}">
                    </div>
                    <div class="sigbox">
                        <canvas id="resp-pad" width="640" height="200" aria-label="Espacio para dibujar tu firma"></canvas>
                        <span class="sighint" id="resp-hint">Dibuja tu firma aquí (con el dedo o el mouse)</span>
                        <button type="button" class="pill small" id="resp-clear">Borrar firma</button>
                    </div>
                    <textarea name="note" rows="3" maxlength="2000" placeholder="Comentarios o ajustes que necesitas (obligatorio si solicitas ajustes)">{{ old('note') }}</textarea>
                    @if ($errors->any())<p style="margin:0;color:#DC2626;font-size:13px">{{ $errors->first() }}</p>@endif
                    <p id="resp-error" style="margin:0;color:#DC2626;font-size:13px" hidden></p>
                    <div class="actions">
                        <button class="pill primary" name="action" value="accept" type="submit" data-need="signature">Firmar y aceptar</button>
                        <button class="pill" name="action" value="changes" type="submit" data-need="note">Solicitar ajustes</button>
                        <button class="pill danger" name="action" value="reject" type="submit" data-confirm="¿Seguro que quieres rechazar la propuesta?">Rechazar</button>
                    </div>
                </form>
            </section>
            <script>
            (function () {
                var c = document.getElementById('resp-pad'); if (!c) return;
                var ctx = c.getContext('2d'), drawing = false, dirty = false, last = null;
                var form = document.getElementById('resp-form'), sig = document.getElementById('resp-signature'), hint = document.getElementById('resp-hint'), err = document.getElementById('resp-error');
                var ratio = Math.max(window.devicePixelRatio || 1, 1);
                function size() { var w = c.clientWidth || 640; c.width = w * ratio; c.height = 200 * ratio; ctx.scale(ratio, ratio); ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0B1522'; dirty = false; sig.value = ''; hint.hidden = false; }
                size();
                function pos(e) { var r = c.getBoundingClientRect(); var t = e.touches ? e.touches[0] : e; return { x: t.clientX - r.left, y: t.clientY - r.top }; }
                function start(e) { e.preventDefault(); drawing = true; last = pos(e); ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(last.x + 0.1, last.y + 0.1); ctx.stroke(); }
                function move(e) { if (!drawing) return; e.preventDefault(); var p = pos(e); ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke(); last = p; dirty = true; hint.hidden = true; }
                function end() { if (!drawing) return; drawing = false; if (dirty) sig.value = c.toDataURL('image/png'); }
                ['mousedown', 'touchstart'].forEach(function (n) { c.addEventListener(n, start, { passive: false }); });
                ['mousemove', 'touchmove'].forEach(function (n) { c.addEventListener(n, move, { passive: false }); });
                ['mouseup', 'mouseleave', 'touchend', 'touchcancel'].forEach(function (n) { c.addEventListener(n, end); });
                document.getElementById('resp-clear').addEventListener('click', function () { ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.clearRect(0, 0, c.width, c.height); size(); });
                form.addEventListener('submit', function (e) {
                    var b = e.submitter; err.hidden = true; if (!b) return;
                    if (b.dataset.need === 'signature' && !sig.value) { e.preventDefault(); err.textContent = 'Dibuja tu firma para aceptar la propuesta.'; err.hidden = false; return; }
                    if (b.dataset.need === 'note' && !form.note.value.trim()) { e.preventDefault(); err.textContent = 'Cuéntanos qué ajustes necesitas.'; err.hidden = false; form.note.focus(); return; }
                    if (b.dataset.confirm && !confirm(b.dataset.confirm)) { e.preventDefault(); return; }
                    if (b.value !== 'accept') sig.value = '';
                });
            })();
            </script>
        @endif
    @endif

    {{-- Firma y aceptación --}}
    <section class="card">
        <h2>Firma y aceptación</h2>
        <p style="margin:0;color:var(--mut)">Atentamente, el equipo de MecaniQ. La propuesta se acepta firmándola y devolviéndola{{ ($public ?? false) ? ' o con la firma en línea de este enlace' : '' }}.</p>
        <div class="sign">
            <div><div class="line"></div><strong>{{ $owner->name ?? 'Equipo MecaniQ' }}</strong><div class="cap">{{ $agency['legal_name'] }} @if (! empty($agency['tax_id']))· RUT {{ $agency['tax_id'] }}@endif<br>{{ $owner->email ?? $agency['email'] }}</div></div>
            <div>
                @if ($status === 'accepted')
                    <div class="line" style="display:grid;place-items:end center;padding-bottom:4px;min-height:64px">
                        @if ($p->signature_data)<img src="{{ $p->signature_data }}" alt="Firma de {{ $p->responded_by }}" style="max-height:60px;max-width:100%">@else<span style="color:#0D9F85;font-weight:700">ACEPTADA EN LÍNEA</span>@endif
                    </div>
                    <strong>{{ $p->responded_by }}</strong><div class="cap">@if ($p->signer_rut)RUT {{ $p->signer_rut }} · @endif{{ $p->responded_at?->locale('es')->translatedFormat('d \d\e F \d\e Y, H:i') }}@if ($p->signature_data) · firmada en línea @endif</div>
                @else
                    <div class="line"></div><strong>{{ $r['contact_name'] ?? 'Nombre y firma del cliente' }}</strong><div class="cap">{{ $r['legal_name'] ?? $company }}@if (! empty($r['rut'])) · RUT {{ $r['rut'] }}@endif<br>Fecha: ______ / ______ / ____________</div>
                @endif
            </div>
        </div>
    </section>

    {{-- Pie: holding (izquierda) · ubicación y redes (derecha) --}}
    <footer class="ficha">
        <div>
            @if (count($holding))
                <h3>Empresas relacionadas</h3>
                <div class="holding">
                    @foreach ($holding as $h)<a href="{{ $h['url'] }}" target="_blank" rel="noopener" title="{{ $h['name'] }}"><img src="{{ $asset('brand/partners/'.$h['logo'].'.png') }}" alt="{{ $h['name'] }}"></a>@endforeach
                </div>
            @else
                <h3>Software Factory &amp; Growth Marketing</h3>
                <p class="hold-note" style="margin-top:0">Más de 10 años transformando empresas con desarrollo web, aplicaciones móviles, automatizaciones, inteligencia artificial y growth marketing.</p>
            @endif
        </div>
        <div>
            <h3>Dónde estamos</h3>
            <div class="where">
                <div><svg viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg><span><strong>{{ $agency['legal_name'] }}</strong> @if (! empty($agency['tax_id']))· RUT {{ $agency['tax_id'] }}@endif<br>{{ $agency['address'] }}</span></div>
                <div><svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg><a href="mailto:{{ $agency['email'] }}">{{ $agency['email'] }}</a></div>
                @if ($agency['phone'])<div><svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg><span>{{ $agency['phone'] }}</span></div>@endif
                <div><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg><a href="{{ $agency['website'] }}" style="color:var(--o);font-weight:600">{{ preg_replace('#^https?://#', '', rtrim($agency['website'], '/')) }}</a></div>
            </div>
            @if ($socials)
                <div class="soc">
                    @foreach ($socials as $k => $sc)
                        <a href="{{ $sc['url'] }}" target="_blank" rel="noopener" title="{{ $sc['label'] }}">
                            @switch($k)
                                @case('instagram')<svg viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><path d="M17.5 6.5h.01"/></svg>@break
                                @case('linkedin')<svg viewBox="0 0 24 24"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-4 0v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg>@break
                                @case('facebook')<svg viewBox="0 0 24 24"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>@break
                                @case('youtube')<svg viewBox="0 0 24 24"><path d="M2.5 17a24.12 24.12 0 0 1 0-10 2 2 0 0 1 1.4-1.4 49.56 49.56 0 0 1 16.2 0A2 2 0 0 1 21.5 7a24.12 24.12 0 0 1 0 10 2 2 0 0 1-1.4 1.4 49.55 49.55 0 0 1-16.2 0A2 2 0 0 1 2.5 17"/><path d="m10 15 5-3-5-3z"/></svg>@break
                                @default<svg viewBox="0 0 24 24"><path d="M9 12a4 4 0 1 0 4 4V4a5 5 0 0 0 5 5"/></svg>
                            @endswitch
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </footer>

    {{-- Tecnologías: lo último de la página, pequeño y sutil --}}
    @if (count($tech))
    <div class="tech">
        <p>Tecnologías con las que trabajamos</p>
        <div>@foreach ($tech as $t)<img src="{{ $asset('brand/tech/'.$t.'.png') }}" alt="{{ $t }}">@endforeach</div>
    </div>
    @endif
    <p class="legal">© {{ now()->year }} {{ $agency['legal_name'] }} · Documento confidencial para {{ $company }}</p>
</div>
@if ($preview ?? false)<div class="vp noprint">Vista previa</div>@endif
</body>
</html>
