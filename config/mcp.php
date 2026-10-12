<?php

return [
    'openai_verification_token' => env('OPENAI_PLUGIN_VERIFICATION_TOKEN'),
    // Hosted assistants and local OAuth callbacks used by Gemini CLI.
    'redirect_domains' => ['https://chatgpt.com', 'https://chat.openai.com', 'https://claude.ai', 'http://localhost'],
    'custom_schemes' => [],
];
