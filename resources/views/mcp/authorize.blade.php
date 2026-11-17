@extends('mcp.layout')
@section('title', 'Allow agency access')
@section('content')
    <h1>Allow access to your agency?</h1>
    <p><strong>{{ $client->name }}</strong> wants to read information from <strong>{{ $user->company->display_name }}</strong>.</p>
    <p class="muted">Signed in as {{ $user->email }}. Return destination: {{ parse_url($request->input('redirect_uri'), PHP_URL_HOST) }}.</p>
    <div class="notice">
        <strong>This connection can read:</strong>
        <ul><li>Customers, vendors, contact details, orders, passenger/travel details and internal notes</li><li>All invoices, payments, receipts, allocations and customer/vendor statements</li><li>Cash/bank balances, ledgers, tax and other business reports</li><li>Company settings, currencies and manual accounting records/reports</li><li>Company users and roles for owner/admin connections only</li></ul>
        <p>Access follows your current role. Passwords, access/share tokens and bank account numbers are excluded.</p>
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
