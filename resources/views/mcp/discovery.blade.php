@extends('mcp.layout')

@section('title', 'Connect an AI assistant')

@section('content')
    <h1>Connect an AI assistant</h1>
    <p>Ask about your agency's customers, outstanding invoices, statements, balances and profit using a secure, read-only MCP connection.</p>
    <p><strong>MCP server URL</strong><br><code>{{ $resource }}</code></p>
    <ol>
        <li>In your AI application's connection settings, add this remote MCP server and choose OAuth authentication.</li>
        <li>Start the connection there. It will open InYice in your browser so you can sign in and approve access.</li>
        <li>Return to your AI application and ask: “Using InYice, show my outstanding invoices.”</li>
    </ol>
    <p class="notice">A paid agency plan and a verified owner, admin or accounts login are required. Starter plans cannot connect. Enter your password only on InYice, never in a chat.</p>
    <p>Custom MCP support depends on your AI application. The current server accepts ChatGPT callback URLs; other clients require configuration by the operator. Giving an assistant this website address does not automatically install a connection. Signing in here alone does not connect it either.</p>
    <p><a href="{{ $issuer }}/connect/chatgpt">Manage existing connections</a></p>
    <p><a href="{{ $issuer }}/llms.txt">Connection instructions for AI assistants</a></p>
    <p><a href="{{ $issuer }}/.well-known/oauth-protected-resource/mcp/agency">MCP resource metadata</a> · <a href="{{ $issuer }}/.well-known/oauth-authorization-server">OAuth metadata</a></p>
@endsection
