<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Connect your agency') · InYice</title>
    @include('mcp.discovery-links')
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f4f6fa;color:#17233b;font:16px/1.6 system-ui,sans-serif}
        main{max-width:560px;margin:7vh auto;padding:32px;background:white;border:1px solid #dce2eb;border-radius:16px}
        .brand{font-weight:750;color:#244fba;letter-spacing:.03em}h1{font-size:26px;line-height:1.3;margin:20px 0 12px}
        p{margin:12px 0}label{display:block;font-weight:600;margin-top:18px}input{width:100%;padding:12px;border:1px solid #8693a9;border-radius:7px;font:inherit}
        button{padding:11px 18px;border:1px solid #244fba;border-radius:7px;background:#244fba;color:white;font:inherit;cursor:pointer}
        button.secondary{background:white;color:#244fba}button:focus-visible,a:focus-visible,input:focus-visible{outline:3px solid #d58b05;outline-offset:3px}
        a{color:#244fba}.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:24px}.error{background:#fff0f0;color:#992020;padding:12px;border-radius:7px}
        .notice{background:#eef4ff;padding:16px;border-radius:9px}.muted{color:#526078;font-size:14px}.connection{padding:18px 0;border-bottom:1px solid #dce2eb}
        code{overflow-wrap:anywhere;font-size:14px}ul{padding-left:22px}@media(max-width:600px){main{margin:20px 12px;padding:24px}}
        select,textarea{box-sizing:border-box;width:100%;padding:12px;border:1px solid #8693a9;border-radius:7px;font:inherit;background:white;color:inherit}textarea{resize:vertical}select:focus-visible,textarea:focus-visible{outline:3px solid #d58b05;outline-offset:3px}
    </style>
</head>
<body>
<main>
    <div class="brand">InYice</div>
    @if($errors->any())
        <div class="error" role="alert">{{ $errors->first() }}</div>
    @endif
    @if(session('status'))
        <p class="notice" role="status">{{ session('status') }}</p>
    @endif
    @yield('content')
    <p class="muted"><a href="/connect/ai">How to connect an AI assistant via MCP</a></p>
    <p class="muted"><a href="https://help.inyice.com">Help center</a> · <a href="{{ route('support.show') }}">Contact support</a></p>
</main>
</body>
</html>
