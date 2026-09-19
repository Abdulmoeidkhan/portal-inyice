<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1f7ae0">
    <title>inYice-Lite</title>
    @include('mcp.discovery-links')
    <link rel="icon" href="/images/icons/icon-96x96.png" type="image/png" sizes="96x96">
    <link rel="icon" href="/images/icons/icon-192x192.png" type="image/png" sizes="192x192">
    <link rel="apple-touch-icon" href="/images/icons/icon-192x192.png">
    <link rel="manifest" href="/site.webmanifest">
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
</head>
<body>
    <div id="root"></div>
    <footer style="padding:12px;text-align:center;font:14px system-ui,sans-serif">
        <a href="/connect/ai">Connect an AI assistant via MCP</a> · <a href="/llms.txt">AI connection instructions</a>
    </footer>
</body>
</html>
