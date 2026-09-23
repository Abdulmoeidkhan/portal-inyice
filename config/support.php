<?php

return [
    'email' => env('SUPPORT_EMAIL', 'info@inyice.com'),
    // Use a delivering transport, never the application's log/failover mailer.
    'mailer' => env('SUPPORT_MAILER', 'smtp'),
];
