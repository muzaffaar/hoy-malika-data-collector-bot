<?php

namespace App\Jobs;

use App\Models\Participant;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class SendBroadcastMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 30;

    /** @param  string  $method  A Telegram Bot API method, e.g. "sendMessage" or "sendVideoNote". */
    public function __construct(public int $chatId, public string $method, public array $params)
    {
        $this->onQueue('telegram-replies');
    }

    public function handle(TelegramClient $client): void
    {
        // Shares the per-chat lock with SendTelegramReply so a broadcast never races a live reply to the same chat.
        $lock = Cache::lock('reply:'.$this->chatId, 90);
        if (! $lock->get()) {
            $this->release(2);

            return;
        }
        try {
            $client->call($this->method, ['chat_id' => $this->chatId] + $this->params);
        } catch (TelegramApiException $e) {
            if ($e->apiCode === 403) {
                Participant::where('telegram_user_id', $this->chatId)->update(['is_blocked' => true]);

                return;
            }
            $this->release(max($e->retryAfter, 5));
        } finally {
            $lock->release();
        }
    }
}
