<?php

namespace App\Console\Commands;

use App\Models\TelegramOutbox;
use App\Models\TelegramUpdate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class DatasetLatency extends Command
{
    protected $signature = 'dataset:latency {--minutes=60 : Look back this many minutes}';

    protected $description = 'Show where time goes between a Telegram message and the bot\'s reply, plus queue backlog';

    public function handle(): int
    {
        $since = now()->subMinutes(max(1, (int) $this->option('minutes')));
        $this->info('Window: last '.$this->option('minutes').' minutes');

        $updates = TelegramUpdate::where('created_at', '>=', $since);
        $this->line(sprintf('Updates received: %d, processed: %d, waiting now: %d, retried at least once: %d',
            (clone $updates)->count(), (clone $updates)->whereNotNull('processed_at')->count(),
            TelegramUpdate::whereNull('processed_at')->count(), (clone $updates)->where('attempts', '>', 0)->count()));
        $this->line('  '.$this->summary('Received -> processed (queue wait + download + save)',
            (clone $updates)->whereNotNull('processed_at')->latest('id')->limit(500)->get(['created_at', 'processed_at'])->map(fn ($u) => $u->created_at->diffInMilliseconds($u->processed_at) / 1000)));

        $outbox = TelegramOutbox::where('created_at', '>=', $since)->whereNotNull('sent_at');
        $this->line('  '.$this->summary('Reply queued -> delivered to Telegram (reply queue + sendMessage)',
            $outbox->latest('id')->limit(500)->get(['created_at', 'sent_at'])->map(fn ($o) => $o->created_at->diffInMilliseconds($o->sent_at) / 1000)));
        $this->line(sprintf('Replies not yet delivered: %d', TelegramOutbox::whereNull('sent_at')->count()));

        $retries = TelegramUpdate::whereNull('processed_at')->where('attempts', '>', 0)->orderByDesc('attempts')->limit(3)->get(['id', 'attempts', 'last_error']);
        foreach ($retries as $u) {
            $this->warn(sprintf('  Update %d is failing (attempt %d): %s', $u->id, $u->attempts, mb_substr((string) $u->last_error, 0, 160)));
        }

        foreach (['telegram-processing', 'telegram-replies', 'google-drive'] as $queue) {
            try {
                $size = Queue::connection('redis')->size($queue);
            } catch (\Throwable) {
                $size = 'unavailable';
            }
            $this->line(sprintf('Queue %-20s waiting/delayed/running: %s', $queue, $size));
        }
        try {
            $this->line('Failed jobs on record: '.DB::table('failed_jobs')->count());
        } catch (\Throwable) {
        }

        return self::SUCCESS;
    }

    private function summary(string $label, $seconds): string
    {
        $values = $seconds->sort()->values();
        if ($values->isEmpty()) {
            return $label.': no data';
        }
        $p95 = $values[(int) floor(0.95 * ($values->count() - 1))];

        return sprintf('%s: avg %.1fs, p95 %.1fs, max %.1fs (n=%d)', $label, $values->avg(), $p95, $values->last(), $values->count());
    }
}
