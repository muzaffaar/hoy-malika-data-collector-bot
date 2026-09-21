<?php

namespace App\Services\Telegram;

class TelegramApiException extends \RuntimeException
{
    public function __construct(public readonly int $retryAfter = 3, public readonly int $apiCode = 0)
    {
        parent::__construct('Telegram request failed (details redacted).');
    }
}
