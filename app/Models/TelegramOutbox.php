<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramOutbox extends Model
{
    protected $table = 'telegram_outbox';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'sent_at' => 'datetime', 'available_at' => 'datetime'];
    }
}
