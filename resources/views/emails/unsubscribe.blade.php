<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Darse de baja · {{ $company }}</title>
    <style>
        body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(#F3F6F9,#fff);font-family:'Asap',Arial,sans-serif;color:#0B1522}
        .card{background:#fff;border:1px solid #e4e4e4;border-radius:20px;padding:36px 32px;max-width:420px;width:calc(100% - 32px);text-align:center;box-shadow:0 8px 30px rgba(0,0,0,.05)}
        h1{font-size:22px;margin:0 0 8px;color:#027BB9}p{color:#5B6B7C;line-height:1.5;margin:0 0 20px}
        button{background:#027BB9;color:#fff;border:0;border-radius:999px;padding:12px 28px;font:600 15px 'Asap',Arial,sans-serif;cursor:pointer}
        button:hover{background:#027BB9}.ok{color:#0d9f85}
    </style>
</head>
<body>
<div class="card">
    @if ($done)
        <h1 class="ok" style="color:#0d9f85">Listo, quedaste fuera de la lista</h1>
        <p>Ya no recibirás más correos de <strong>{{ $company }}</strong> en <strong>{{ $email }}</strong>.</p>
    @else
        <h1>¿Quieres darte de baja?</h1>
        <p>Dejarás de recibir correos de <strong>{{ $company }}</strong> en <strong>{{ $email }}</strong>.</p>
        <form method="POST" action="{{ route('unsubscribe.store', $uuid) }}">
            <button type="submit">Confirmar baja</button>
        </form>
    @endif
</div>
</body>
</html>
