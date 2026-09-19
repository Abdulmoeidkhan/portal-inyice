@extends('mcp.layout')
@section('title', 'Sign in to connect')
@section('content')
    <h1>Connect your agency</h1>
    <p>Sign in with your InYice account to connect an AI assistant or manage your existing connections.</p>
    <p class="muted">Use a verified owner, admin or accounts login on a paid agency plan. Starter plans do not include API or MCP access. Your password is submitted only to InYice.</p>
    <form method="POST" action="{{ route('mcp.login.submit') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" maxlength="255" required autofocus>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <div class="actions"><button type="submit">Continue</button></div>
    </form>
    <p class="muted"><a href="/forgot-password">Forgot your password?</a> · <a href="/">Return to InYice</a></p>
@endsection
