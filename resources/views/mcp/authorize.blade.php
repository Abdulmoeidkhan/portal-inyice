@extends('mcp.layout')
@section('title', 'Allow agency access')
@section('content')
    <h1>Allow access to your agency?</h1>
    <p><strong>{{ $client->name }}</strong> wants to read information from <strong>{{ $user->company->display_name }}</strong>.</p>
    <p class="muted">Signed in as {{ $user->email }}. Return destination: {{ parse_url($request->input('redirect_uri'), PHP_URL_HOST) }}.</p>
    <div class="notice">
        <strong>This connection can read:</strong>
        <ul><li>Customers and their contact details</li><li>Outstanding invoices and customer statements</li><li>Cash and bank balances</li><li>Profit reports</li></ul>
        <p>It cannot create or change invoices, record payments, or delete records.</p>
    </div>
    <p>The connected AI service receives the information you ask it to retrieve. You can remove access from your InYice connections page.</p>
    <div class="actions">
        <form method="POST" action="{{ route('passport.authorizations.approve') }}">
            @csrf
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit">Allow read access</button>
        </form>
        <form method="POST" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="secondary">Cancel</button>
        </form>
    </div>
    <form method="POST" action="{{ route('mcp.logout') }}" class="actions">
        @csrf
        <button class="secondary" type="submit">Sign in with a different account</button>
    </form>
@endsection
