<?php

return [
    'token' => env('TELEGRAM_BOT_TOKEN'),
    'timeout' => (int) env('TELEGRAM_POLLING_TIMEOUT', 30),
    'retry_seconds' => (int) env('TELEGRAM_POLLING_RETRY_SECONDS', 3),
];
