<?php

namespace App\Jobs;

use App\Models\Participant;
use App\Models\TelegramOutbox;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SendTelegramReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $timeout = 70;

    public function __construct(public int $outboxId)
    {
        $this->onQueue('telegram-replies');
    }

    public function handle(TelegramClient $client): void
    {
        $out = TelegramOutbox::find($this->outboxId);
        if (! $out || $out->sent_at || $out->available_at?->isFuture()) {
            return;
        }
        $lock = Cache::lock('reply:'.$out->chat_id, 90);
        if (! $lock->get()) {
            $this->release(1);
            return;
        }
        try {
            $out->refresh();
            if ($out->sent_at) {
                return;
            }
            if (TelegramOutbox::where('chat_id', $out->chat_id)->whereNull('sent_at')->where('id', '<', $out->id)->exists()) {
                $this->release(1);
                return;
            }
            $client->call('sendMessage', ['chat_id' => $out->chat_id] + $out->payload);
            $out->update(['sent_at' => now()]);
            Log::info('telegram.confirmation_sent', ['update_id' => $out->update_id]);
        } catch (TelegramApiException $e) {
            if ($e->apiCode === 403) {
                Participant::where('telegram_user_id', $out->chat_id)->update(['is_blocked' => true]);
                $out->update(['sent_at' => now()]);
            } else {
                $retryAt = now()->addSeconds(max($e->retryAfter, min(3600, 2 ** min(12, $out->attempts + 2))));
                $out->update(['attempts' => $out->attempts + 1, 'available_at' => $retryAt]);
                // Retry exactly when due; the once-a-minute scheduler sweep stays as the safety net.
                self::dispatch($out->id)->delay($retryAt);
            }
        } finally {
            $lock->release();
        }
    }
}
