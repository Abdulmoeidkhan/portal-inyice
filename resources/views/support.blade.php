@extends('mcp.layout')
@section('title', 'Contact support')
@section('content')
    <h1>How can we help?</h1>
    <p>Get help with your InYice account, accounting workspace or ChatGPT connection. You do not need to sign in to send a request.</p>
    <p>Browse the <a href="https://help.inyice.com">help center</a> or email <a href="mailto:{{ $email }}">{{ $email }}</a>.</p>
    <form method="POST" action="{{ route('support.store') }}">
        @csrf
        <label for="support-name">Name</label>
        <input id="support-name" name="name" autocomplete="name" maxlength="100" value="{{ old('name') }}" required>
        <label for="support-email">Email address</label>
        <input id="support-email" name="email" type="email" autocomplete="email" maxlength="254" value="{{ old('email') }}" required>
        <label for="support-topic">What do you need help with?</label>
        <select id="support-topic" name="topic" required>
            @foreach(['Connection issue', 'Account or billing', 'Privacy or deletion', 'Other'] as $topic)
                <option value="{{ $topic }}" @selected(old('topic', 'Connection issue') === $topic)>{{ $topic }}</option>
            @endforeach
        </select>
        <label for="support-body">Message</label>
        <textarea id="support-body" name="body" rows="7" minlength="10" maxlength="5000" aria-describedby="support-guidance support-privacy" required>{{ old('body') }}</textarea>
        <div hidden aria-hidden="true">
            <label for="support-website">Leave this field empty</label>
            <input id="support-website" name="website" tabindex="-1" autocomplete="off">
        </div>
        <p id="support-guidance" class="muted">For connection errors, include the page URL, exact error and time it happened. Do not include passwords, access tokens, passport details or customer financial records.</p>
        <p id="support-privacy" class="muted">We use your name, email and message to respond to this request. The form sends them to InYice's support mailbox through our email provider. To ask about your information, choose “Privacy or deletion” or email us directly.</p>
        <div class="actions"><button type="submit">Send request</button></div>
    </form>
@endsection
