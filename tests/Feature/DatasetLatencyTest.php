<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramUpdate;
use App\Jobs\SendTelegramReply;
use App\Models\TelegramOutbox;
use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DatasetLatencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_latency_report_shows_stage_timings_and_failing_updates(): void
    {
        $now = now();
        foreach ([2, 4, 6] as $i => $seconds) {
            DB::table('telegram_updates')->insert(['id' => 100 + $i, 'telegram_user_id' => 1, 'created_at' => $now->copy()->subMinutes(5), 'updated_at' => $now,
                'processed_at' => $now->copy()->subMinutes(5)->addSeconds($seconds)]);
        }
        DB::table('telegram_updates')->insert(['id' => 200, 'telegram_user_id' => 1, 'attempts' => 3, 'last_error' => 'ErrorException: mkdir(): Permission denied', 'created_at' => $now, 'updated_at' => $now]);

        $this->artisan('dataset:latency', ['--minutes' => 30])
            // One expectation per output line: Laravel consumes a single expectation for each line it sees.
            ->expectsOutputToContain('avg 4.0s, p95 4.0s, max 6.0s')
            ->expectsOutputToContain('mkdir(): Permission denied')
            ->assertSuccessful();
    }

    public function test_failed_processing_is_retried_when_due_not_only_at_the_next_scheduler_sweep(): void
    {
        Queue::fake();
        $update = TelegramUpdate::create(['id' => 300, 'telegram_user_id' => 7, 'payload' => ['message' => ['message_id' => 1, 'chat' => ['id' => 7, 'type' => 'private'], 'from' => ['id' => 7, 'is_bot' => false], 'voice' => ['file_id' => 'f']]]]);
        $this->mock(\App\Services\Telegram\BotFlow::class, fn ($m) => $m->shouldReceive('process')->andThrow(new \RuntimeException('boom')));

        app()->call([new ProcessTelegramUpdate($update->id), 'handle']);

        $this->assertTrue($update->fresh()->available_at->isFuture());
        Queue::assertPushed(ProcessTelegramUpdate::class, fn ($job) => $job->updateId === 300 && $job->delay !== null);
    }

    public function test_failed_reply_is_retried_when_due(): void
    {
        Queue::fake();
        $out = TelegramOutbox::create(['update_id' => 1, 'kind' => 'reply', 'chat_id' => 5, 'payload' => ['text' => 'x']]);
        $client = $this->mock(TelegramClient::class, fn ($m) => $m->shouldReceive('call')->once()->andThrow(new TelegramApiException(5, 500)));

        (new SendTelegramReply($out->id))->handle($client);

        Queue::assertPushed(SendTelegramReply::class, fn ($job) => $job->outboxId === $out->id && $job->delay !== null);
    }
}
