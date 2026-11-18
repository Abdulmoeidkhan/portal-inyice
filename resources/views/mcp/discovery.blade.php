@extends('mcp.layout')

@section('title', 'Connect an AI assistant')

@section('content')
    <h1>Connect an AI assistant</h1>
    <p>Read your agency's customers, vendors, orders, invoices, payments, receipts, statements, reports and manual accounting through an authenticated, read-only MCP connection.</p>
    <p>Owners and admins can also authorize basic order creation by requesting both <code>mcp:use</code> and <code>orders:create</code> during OAuth connection. Existing read-only connections need a new consent flow to enable this. Accounts connections remain read-only; sales and developer logins are not eligible for MCP.</p>
    <p><a href="/api-examples">Browse API request/response examples and mock URLs</a></p>
    <p><strong>MCP server URL</strong><br><code>{{ $resource }}</code></p>
    <ol>
        <li>In your AI application's connection settings, add this remote MCP server and choose OAuth authentication.</li>
        <li>Start the connection there. It will open InYice in your browser so you can sign in and approve access.</li>
        <li>Return to your AI application and ask: “Using InYice, show my outstanding invoices.”</li>
    </ol>
    <p class="notice">A paid agency plan and a verified owner, admin or accounts login are required. Starter plans cannot connect. Enter your password only on InYice, never in a chat.</p>
    <h2>ChatGPT</h2>
    <p>Add a custom remote MCP connection in your ChatGPT account or workspace, using the server URL above and OAuth authentication. Availability depends on your account and workspace settings. Choose dynamic client registration when configuring the OAuth client.</p>
    <h2>Claude</h2>
    <p>Open Settings → Connectors → Add custom connector, enter the server URL above, and complete the InYice sign-in and consent screen. Your workspace may require an administrator to enable custom connectors.</p>
    <h2>Gemini CLI</h2>
    <p>Add the following server to the <code>mcpServers</code> section in your Gemini CLI <code>settings.json</code>, then run <code>/mcp auth inyice</code>:</p>
    <pre>{{ json_encode(['mcpServers' => ['inyice' => ['httpUrl' => $resource, 'oauth' => ['enabled' => true, 'scopes' => ['mcp:use']]]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    <p>Gemini CLI opens a browser and receives the OAuth callback on localhost. This configuration is for Gemini CLI; support in the Gemini consumer chat app is not verified.</p>
    <p>Giving an assistant this website address does not automatically install a connection. Signing in here alone does not connect it either.</p>
    <p>After connecting, try “Find my customers”, “Show outstanding invoices”, or “Show this month's profit report”. These tools read operational accounting data; they do not post entries to the separate Accounting module or change business records.</p>
    <p><a href="https://developers.openai.com/plugins/build/auth">ChatGPT authentication documentation</a> · <a href="https://support.claude.com/en/articles/11175166-get-started-with-custom-connectors-using-remote-mcp">Claude connector documentation</a> · <a href="https://geminicli.com/docs/tools/mcp-server/">Gemini CLI documentation</a></p>
    <p><a href="{{ $issuer }}/connect/chatgpt">Manage existing connections</a></p>
    <p><a href="{{ $issuer }}/llms.txt">Connection instructions for AI assistants</a></p>
    <p><a href="{{ $issuer }}/.well-known/oauth-protected-resource/mcp/agency">MCP resource metadata</a> · <a href="{{ $issuer }}/.well-known/oauth-authorization-server">OAuth metadata</a></p>
@endsection
