<?php

return [
    'token' => env('TELEGRAM_BOT_TOKEN'),
    'timeout' => (int) env('TELEGRAM_POLLING_TIMEOUT', 30),
    'retry_seconds' => (int) env('TELEGRAM_POLLING_RETRY_SECONDS', 3),
    // Skip IPv6 when the server has a broken/slow IPv6 route to Telegram (see: php artisan telegram:speed).
    'force_ipv4' => (bool) env('TELEGRAM_FORCE_IPV4', false),
    // The Telegram user ID that sees the in-bot admin menu (stats + participant reminders) instead of the participant flow.
    'admin_user_id' => env('ADMIN_TELEGRAM_USER_ID'),
];
