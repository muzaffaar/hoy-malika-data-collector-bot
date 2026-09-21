<?php

namespace App\Jobs;

use App\Models\TelegramOutbox;
use App\Models\TelegramUpdate;
use App\Models\VoiceRecording;
use App\Services\Telegram\BotFlow;
use App\Services\Telegram\TelegramApiException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessTelegramUpdate implements ShouldQueue
{
    use Queueable;

    public int $timeout = 110;

    public int $tries = 10;

    public array $backoff = [1, 2, 3, 5, 10, 20, 30, 60];

    public function __construct(public int $updateId)
    {
        $this->onQueue('telegram-processing');
    }

    public function handle(BotFlow $flow): void
    {
        $startedNs = hrtime(true);
        Cache::put('heartbeat:queue', now()->timestamp, 300);
        $update = TelegramUpdate::find($this->updateId);
        if (! $update || $update->processed_at || $update->available_at?->isFuture()) {
            return;
        }
        $lock = Cache::lock('participant:'.($update->telegram_user_id ?? 'unknown'), 150);
        if (! $lock->get()) {
            $this->release(1);
            return;
        }
        try {
            $update->refresh();
            if ($update->processed_at) {
                return;
            }
            if (TelegramUpdate::where('telegram_user_id', $update->telegram_user_id)->whereNull('processed_at')->where('id', '<', $update->id)->exists()) {
                $this->release(1);
                return;
            }
            $messageId = $update->payload['message']['message_id'] ?? 0;
            $chatId = $update->payload['message']['chat']['id'] ?? null;
            $flow->process($update);
            if (config('dataset.drive_enabled')) {
                $message = VoiceRecording::where('telegram_chat_id', $chatId)->where('telegram_message_id', $messageId)->first();
                if ($message) {
                    BackupRecording::dispatch($message->id);
                }
            }
            $outbox = TelegramOutbox::where('update_id', $update->id)->where('kind', 'reply')->first();
            if ($outbox) {
                SendTelegramReply::dispatch($outbox->id);
            }
            $next = TelegramUpdate::where('telegram_user_id', $update->telegram_user_id)->whereNull('processed_at')->orderBy('id')->first();
            if ($next) {
                self::dispatch($next->id);
            }
        } catch (\Throwable $e) {
            $delay = min(3600, 3 * (2 ** min($update->attempts, 10)));
            if ($e instanceof TelegramApiException) {
                $delay = max($delay, $e->retryAfter);
            }
            $retryAt = now()->addSeconds($delay);
            $update->update(['attempts' => $update->attempts + 1, 'last_error' => mb_substr(get_class($e).': '.$e->getMessage(), 0, 1000), 'available_at' => $retryAt]);
            // Retry exactly when due; the once-a-minute scheduler sweep stays as the safety net.
            self::dispatch($update->id)->delay($retryAt);
            Log::error('telegram.processing_retry', [
                'update_id' => $update->id,
                'exception_type' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $m = $update->payload['message'] ?? [];
            if (($m['chat']['type'] ?? '') === 'private' && (isset($m['voice']) || isset($m['audio']))) {
                $notice = TelegramOutbox::firstOrCreate(['update_id' => $update->id, 'kind' => 'retry_notice'], [
                    'chat_id' => $m['chat']['id'], 'payload' => ['text' => '⚠️ Ovozni saqlashda vaqtinchalik muammo. Qayta urinib ko‘ramiz. Qayta yuborishingiz shart emas.'],
                ]);
                SendTelegramReply::dispatch($notice->id);
            }
        } finally {
            $lock->release();
            $ms = (int) ((hrtime(true) - $startedNs) / 1e6);
            if ($ms > 2000) {
                Log::warning('telegram.slow_update', ['update_id' => $this->updateId, 'ms' => $ms]);
            }
        }
    }
}
