@extends('mcp.layout')
@section('title', 'Read-only API examples')
@section('content')
<h1>Read-only API examples</h1>
<p>Explore requests and synthetic responses for agency data, reports and MCP tools. These public examples never expose live agency data. Live requests require authentication and the listed role.</p>
<p><a href="/api/v1/api-examples">Download the JSON catalog</a> · <a href="/connect/ai">Connect with MCP</a></p>
<p>Open an example for its request headers, query fields, response body and MCP equivalent. Open a mock to receive only the synthetic response body.</p>
<p>Use <code>page</code> and <code>per_page</code> (maximum 100) for records. Follow <code>has_more</code> until false. Use <code>filter_field=order_id&amp;filter_value=1</code> to retrieve child rows. Reports accept the parameters shown in their example; date ranges are limited to 366 days.</p>
<h2>Authentication</h2>
<p>{{ $catalog['authentication']['api'] }}</p>
<p>{{ $catalog['authentication']['mcp'] }}</p>
<h2>Requests and responses</h2>
<ul>
@foreach ($catalog['examples'] as $example)
    <li><strong>{{ $example['id'] }}</strong><br>
        <code>GET {{ $example['path'] }}</code><br>
        Roles: {{ implode(', ', $example['roles']) }} ·
        <a href="{{ $example['example_url'] }}">Request / response</a> · <a href="{{ $example['mock_url'] }}">Mock response</a>
    </li>
@endforeach
</ul>
@endsection
