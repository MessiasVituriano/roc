<!DOCTYPE html>
<html lang="pt-BR" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1">
    <meta name="theme-color" content="#020617">
    <title>{{ config('live.event_title') }} - {{ config('app.name', 'Sistema IO') }}</title>
    <meta name="description" content="{{ config('live.event_title') }} — Sistema IO, Inteligência em Ocupação">
    {{-- rótulo do ícone na tela de início: trunca em ~12 caracteres, tem de ser curto --}}
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'Sistema IO') }}">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="apple-touch-icon" href="/brand/logo-io.svg">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased">
    {{--
        Splash antes do Vue montar: numa rede de evento o bundle demora, e uma
        tela preta parece app quebrado. O estilo vai inline porque o CSS do Vite
        ainda não chegou até aqui. O mount do Vue substitui este conteúdo.
    --}}
    <div id="app">
        <div style="min-height:100dvh;display:grid;place-items:center;font-family:'Instrument Sans',ui-sans-serif,system-ui,sans-serif">
            <div style="display:flex;flex-direction:column;align-items:center;gap:16px">
                <img src="/brand/logo-io.svg" alt="Sistema IO" width="72" height="93"
                     style="filter:drop-shadow(0 0 10px rgba(56,189,248,.35))">
                <p style="margin:0;font-size:30px;font-weight:900;background:linear-gradient(90deg,#7dd3fc,#a7f3d0,#bef264);-webkit-background-clip:text;background-clip:text;color:transparent">
                    Sistema IO
                </p>
                <p style="margin:-8px 0 0;font-size:11px;font-weight:700;letter-spacing:.28em;text-transform:uppercase;color:rgba(110,231,183,.7)">
                    Inteligência em Ocupação
                </p>
                <p style="margin:8px 0 0;font-size:13px;color:#94a3b8">Carregando…</p>
            </div>
        </div>
    </div>
</body>
</html>
