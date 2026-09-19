<?php

return [
    'openai_verification_token' => env('OPENAI_PLUGIN_VERIFICATION_TOKEN'),
    // Only ChatGPT web callbacks can register clients for this connection.
    'redirect_domains' => ['https://chatgpt.com', 'https://chat.openai.com'],
    'custom_schemes' => [],
];
