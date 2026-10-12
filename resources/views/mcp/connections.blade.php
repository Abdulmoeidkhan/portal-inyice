@extends('mcp.layout')
@section('title', 'AI connections')
@section('content')
    <h1>Your AI connections</h1>
    <p>{{ $user->company->display_name }} · {{ $user->email }}</p>
    <div class="notice">
        <p>Add InYice in ChatGPT, Claude or Gemini CLI using this server address, then sign in and allow read access:</p>
        <code>{{ $mcpUrl }}</code>
        <p class="muted">Custom MCP connections must be enabled in your assistant or workspace. <a href="{{ url('/connect/ai') }}">View setup instructions for each assistant</a>.</p>
    </div>
    @forelse($connections as $connection)
        <div class="connection">
            <strong>{{ $connection->client->name }}</strong>
            <p class="muted">Read-only agency access · Connected {{ $connection->created_at->format('j M Y') }}</p>
            <form method="POST" action="{{ route('mcp.disconnect', $connection->client_id) }}">
                @csrf
                @method('DELETE')
                <button class="secondary" type="submit">Disconnect</button>
            </form>
        </div>
    @empty
        <p>No active AI connections yet.</p>
    @endforelse
    <form method="POST" action="{{ route('mcp.logout') }}" class="actions">
        @csrf
        <button class="secondary" type="submit">Sign out</button>
        <a href="/">Return to InYice</a>
    </form>
@endsection
