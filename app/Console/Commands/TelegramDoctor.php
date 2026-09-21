<?php

namespace App\Console\Commands;

use App\Models\TelegramOutbox;
use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TelegramDoctor extends Command
{
    protected $signature = 'telegram:doctor';
    protected $description = 'Check Telegram, polling, inbox, queue and reply pipeline health without exposing secrets';

    public function handle(TelegramClient $telegram): int
    {
        $ok = true;
        $rows = [];
        try { DB::select('select 1'); $rows[] = ['PostgreSQL', 'OK']; } catch (\Throwable $e) { $rows[] = ['PostgreSQL', 'FAIL']; $ok = false; }
        try { Cache::put('doctor:probe', 1, 10); $rows[] = ['Redis/cache', (int) Cache::get('doctor:probe') === 1 ? 'OK' : 'FAIL']; } catch (\Throwable $e) { $rows[] = ['Redis/cache', 'FAIL']; $ok = false; }
        try {
            $me = $telegram->call('getMe');
            $rows[] = ['Telegram API', 'OK @'.($me['username'] ?? 'unknown')];
            $hook = $telegram->call('getWebhookInfo');
            $rows[] = ['Webhook', empty($hook['url']) ? 'none (polling ready)' : 'ACTIVE - polling blocked'];
            if (! empty($hook['url'])) $ok = false;
        } catch (\Throwable $e) { $rows[] = ['Telegram API', 'FAIL']; $ok = false; }
        $rows[] = ['Poll heartbeat', $this->heartbeat('poll')];
        $rows[] = ['Processing queue heartbeat', $this->heartbeat('queue')];
        $rows[] = ['Reply queue heartbeat', $this->heartbeat('replies')];
        $rows[] = ['Unprocessed inbox', (string) TelegramUpdate::whereNull('processed_at')->count()];
        $rows[] = ['Unsent replies', (string) TelegramOutbox::whereNull('sent_at')->count()];
        $cursor = DB::table('telegram_cursors')->where('id', 1)->first();
        $rows[] = ['Last ingested update', (string) ($cursor->last_ingested_id ?? -1)];
        $rows[] = ['Last processed update', (string) ($cursor->last_processed_id ?? -1)];
        $this->table(['Check', 'Result'], $rows);
        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function heartbeat(string $name): string
    {
        $at = (int) Cache::get('heartbeat:'.$name, 0);
        return $at > time() - 180 ? 'OK ('.now()->setTimestamp($at)->diffForHumans().')' : 'STALE / missing';
    }
}
