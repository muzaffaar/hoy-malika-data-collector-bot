<?php

namespace App\Console\Commands;

use App\Services\Telegram\Inbox;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TelegramPoll extends Command
{
    protected $signature = 'telegram:poll {--once : Poll Telegram once and exit}';

    protected $description = 'Long poll Telegram into a durable PostgreSQL inbox (no webhook)';

    public function handle(TelegramClient $telegram, Inbox $inbox): int
    {
        $running = true;
        if (extension_loaded('pcntl')) {
            $this->trap([SIGTERM, SIGINT], function () use (&$running): void {
                $running = false;
                $this->line('Telegram polling stopping...');
            });
        }

        // Polling and webhooks are mutually exclusive. Enforce the configured architecture.
        try {
            $webhook = $telegram->call('getWebhookInfo');
            if (! empty($webhook['url'])) {
                $this->warn('An active Telegram webhook was found; removing it so long polling can run.');
                $telegram->call('deleteWebhook', ['drop_pending_updates' => false]);
            }
        } catch (\Throwable $e) {
            $this->error('Telegram startup check failed: '.$e->getMessage());
            Log::error('telegram.startup_failed', ['exception_type' => get_class($e)]);
            return self::FAILURE;
        }

        $this->info('Telegram long polling started.');
        Log::info('telegram.polling_started');
        // Recover anything ingested before a restart; afterwards only new updates are queued (the scheduler sweeps every minute).
        $inbox->dispatchPending();
        $failures = 0;

        do {
            $lock = Cache::lock('telegram:poll', config('telegram.timeout') + 90);
            if (! $lock->get()) {
                if ($this->option('once')) {
                    $this->warn('Another telegram:poll process already owns the polling lock.');
                    return self::FAILURE;
                }
                sleep(3);
                continue;
            }

            try {
                Cache::put('heartbeat:poll', now()->timestamp, 300);
                DB::table('telegram_cursors')->insertOrIgnore(['id' => 1, 'last_ingested_id' => -1, 'last_processed_id' => -1]);
                $lastIngested = (int) (DB::table('telegram_cursors')->where('id', 1)->value('last_ingested_id') ?? -1);
                $offset = $lastIngested + 1;

                $updates = $telegram->call('getUpdates', [
                    'offset' => $offset,
                    'timeout' => config('telegram.timeout'),
                    'allowed_updates' => ['message'],
                    'limit' => 100,
                ]);

                if ($updates !== []) {
                    $this->line(sprintf('Received %d Telegram update(s), starting at offset %d.', count($updates), $offset));
                }

                $new = $inbox->ingest($updates);
                Cache::put('heartbeat:telegram', now()->timestamp, 300);
                $inbox->dispatchNew($new);
                $failures = 0;
            } catch (\Throwable $e) {
                $delay = min(60, config('telegram.retry_seconds') * (2 ** min(++$failures, 5)));
                if ($e instanceof TelegramApiException) {
                    $delay = max($delay, $e->retryAfter);
                }
                $this->error(sprintf('Telegram polling error (%s). Retrying in %ds.', class_basename($e), $delay));
                Log::error('telegram.poll_retry', ['exception_type' => get_class($e), 'retry_seconds' => $delay]);
                for ($i = 0; $i < $delay && $running && ! $this->option('once'); $i++) {
                    sleep(1);
                }
            } finally {
                $lock->release();
            }
        } while ($running && ! $this->option('once'));

        return self::SUCCESS;
    }
}
