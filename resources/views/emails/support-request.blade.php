<!doctype html>
<html lang="en">
<body>
    <h1>InYice support request</h1>
    <p>Reference: {{ $reference }}</p>
    <p>From: {{ $senderName }} ({{ $senderEmail }})</p>
    <p>Topic: {{ $topic }}</p>
    <p>This message was submitted through the public support form. The sender's email address has not been verified; verify account ownership before disclosing or changing account information.</p>
    <div style="white-space: pre-wrap">{{ $requestBody }}</div>
</body>
</html>
