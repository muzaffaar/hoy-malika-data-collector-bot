<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramUpdate;
use App\Services\Telegram\BotFlow;
use App\Services\Telegram\Inbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ConcurrentUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function update(int $id, int $user): array
    {
        return ['update_id' => $id, 'message' => ['message_id' => $id, 'date' => time(), 'chat' => ['id' => $user, 'type' => 'private'], 'from' => ['id' => $user, 'is_bot' => false], 'text' => 'hi']];
    }

    private function queuedIds(): array
    {
        return Queue::pushed(ProcessTelegramUpdate::class)->map(fn ($job) => $job->updateId)->sort()->values()->all();
    }

    public function test_a_burst_from_many_users_queues_one_job_per_user_not_per_update(): void
    {
        $inbox = app(Inbox::class);
        $updates = [];
        for ($user = 1; $user <= 30; $user++) {
            $updates[] = $this->update($user, $user);          // first message
            $updates[] = $this->update(100 + $user, $user);    // second message, same user
        }

        $new = $inbox->ingest($updates);
        $inbox->dispatchNew($new);

        $this->assertCount(60, $new);
        $this->assertSame(range(1, 30), $this->queuedIds(), 'Only each participant\'s oldest update is queued; the rest wait for their predecessor.');
    }

    public function test_ingest_reports_only_new_updates(): void
    {
        $inbox = app(Inbox::class);
        $this->assertSame([5, 6], $inbox->ingest([$this->update(5, 1), $this->update(6, 2)]));
        $this->assertSame([7], $inbox->ingest([$this->update(6, 2), $this->update(7, 2)]));
    }

    public function test_a_finished_update_queues_its_successor_for_the_same_participant(): void
    {
        $inbox = app(Inbox::class);
        $inbox->dispatchNew($inbox->ingest([$this->update(1, 9), $this->update(2, 9), $this->update(3, 9)]));
        $this->assertSame([1], $this->queuedIds());
        $this->mock(BotFlow::class, fn ($m) => $m->shouldReceive('process')->andReturnUsing(fn ($u) => $u->update(['processed_at' => now()])));

        app()->call([new ProcessTelegramUpdate(1), 'handle']);

        $this->assertSame([1, 2], $this->queuedIds());
    }

    public function test_a_failing_update_blocks_only_its_own_participant(): void
    {
        $inbox = app(Inbox::class);
        $inbox->ingest([$this->update(1, 10), $this->update(2, 10), $this->update(3, 11)]);
        TelegramUpdate::find(1)->update(['attempts' => 2, 'available_at' => now()->addMinutes(5)]);   // participant 10's head is in retry backoff

        $inbox->dispatchPending();

        $this->assertSame([3], $this->queuedIds(), 'Participant 11 is served while participant 10 waits; 10\'s later message stays behind its head.');
    }

    public function test_recovery_sweep_requeues_due_heads_once(): void
    {
        $inbox = app(Inbox::class);
        $inbox->ingest([$this->update(1, 20), $this->update(2, 20), $this->update(3, 21), $this->update(4, 21)]);
        TelegramUpdate::find(1)->update(['processed_at' => now()]);   // participant 20: update 2 is now the head

        $inbox->dispatchPending();

        $this->assertSame([2, 3], $this->queuedIds());
    }
}
