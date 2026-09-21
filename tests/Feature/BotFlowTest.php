<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramOutbox;
use App\Models\TelegramUpdate;
use App\Models\VoiceRecording;
use App\Services\Telegram\BotFlow;
use App\Services\Telegram\Inbox;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BotFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        config(['dataset.minimum_free_bytes' => 0, 'dataset.target' => 2]);
    }

    private function update(int $id, array $content): void
    {
        app(Inbox::class)->ingest([['update_id' => $id, 'message' => ['message_id' => $id, 'date' => time(), 'chat' => ['id' => 123, 'type' => 'private'], 'from' => ['id' => 123, 'is_bot' => false, 'first_name' => 'Aziza']] + $content]]);
        (new ProcessTelegramUpdate($id))->handle(app(BotFlow::class));
    }

    private function onboard(): void
    {
        $this->update(1, ['text' => '/start']);
        $this->update(2, ['text' => '✅ Roziman']);
        $this->update(3, ['text' => '18–24']);
        $this->update(4, ['text' => '👩 Ayol']);
    }

    private function voice(): array
    {
        return ['voice' => ['file_id' => 'f1', 'file_unique_id' => 'unique1', 'duration' => 2, 'file_size' => 11]];
    }

    private function mockDownload(): void
    {
        $this->mock(TelegramClient::class, function ($m) {
            $m->shouldReceive('download')->andReturnUsing(function ($id, $path) {
                file_put_contents($path, "OggS\x00\xffvoice");

                return ['file_size' => 11];
            });
        });
    }

    public function test_onboarding_is_persistent_and_incorrect_input_does_not_reset_it(): void
    {
        $this->update(1, ['text' => '/start']);
        $this->assertDatabaseHas('participants', ['consent_given' => false, 'onboarding_state' => 'AWAITING_CONSENT']);
        $this->update(2, ['text' => '✅ Roziman']);
        $this->update(3, ['text' => 'nonsense']);
        $this->update(4, ['text' => '/start']);
        $this->assertDatabaseHas('participants', ['onboarding_state' => 'AWAITING_AGE', 'consent_given' => true]);
        $this->update(5, ['text' => '18–24']);
        $this->update(6, ['text' => '👩 Ayol']);
        $this->update(7, ['photo' => []]);
        $this->assertDatabaseCount('participants', 1);
        $this->assertDatabaseHas('participants', ['gender' => 'FEMALE', 'onboarding_state' => 'READY_FOR_RECORDINGS']);
    }

    public function test_original_is_preserved_duplicates_are_idempotent_and_extra_voices_are_accepted(): void
    {
        $this->onboard();
        $this->mockDownload();
        $this->update(5, $this->voice());
        $this->update(5, $this->voice());
        $this->assertDatabaseCount('voice_recordings', 1);
        $r = VoiceRecording::first();
        $this->assertSame("OggS\x00\xffvoice", Storage::disk('local')->get($r->relative_storage_path));
        $this->assertSame(hash('sha256', "OggS\x00\xffvoice"), $r->sha256_checksum);
        $this->assertStringContainsString('✅ Ovozli xabaringiz qabul qilindi.', TelegramOutbox::where('update_id', 5)->first()->payload['text']);
        $this->update(6, $this->voice());
        $this->update(7, $this->voice());
        $this->update(8, ['text' => '/start']);
        $this->assertDatabaseCount('voice_recordings', 3);
        $this->assertDatabaseHas('participants', ['recording_count' => 3, 'onboarding_state' => 'COMPLETED']);
    }

    public function test_failed_download_stays_pending_and_is_not_acknowledged(): void
    {
        $this->onboard();
        $this->mock(TelegramClient::class, fn ($m) => $m->shouldReceive('download')->andThrow(new TelegramApiException));
        $this->update(5, $this->voice());
        $this->assertDatabaseCount('voice_recordings', 0);
        $this->assertNull(TelegramUpdate::find(5)->processed_at);
        $this->assertDatabaseMissing('telegram_outbox', ['update_id' => 5, 'kind' => 'reply']);
        $this->assertDatabaseHas('telegram_outbox', ['update_id' => 5, 'kind' => 'retry_notice']);
        $this->assertSame(1, TelegramUpdate::find(5)->attempts);
    }

    public function test_later_updates_wait_for_earlier_participant_messages(): void
    {
        app(Inbox::class)->ingest([['update_id' => 1, 'message' => ['from' => ['id' => 123]]], ['update_id' => 2, 'message' => ['from' => ['id' => 123]]]]);
        (new ProcessTelegramUpdate(2))->handle(app(BotFlow::class));
        $this->assertNull(TelegramUpdate::find(2)->processed_at);
    }

    public function test_voice_before_consent_forwarded_voice_and_long_voice_are_not_saved(): void
    {
        $this->update(1, $this->voice());
        $this->assertDatabaseCount('voice_recordings', 0);
        $this->onboard();
        $this->update(5, $this->voice() + ['forward_origin' => ['type' => 'user']]);
        $v = $this->voice();
        $v['voice']['duration'] = 30;
        $this->update(6, $v);
        $this->assertDatabaseCount('voice_recordings', 0);
    }

    public function test_declining_consent_does_not_advance(): void
    {
        $this->update(1, ['text' => '/start']);
        $this->update(2, ['text' => '❌ Rozimasman']);
        $this->assertDatabaseHas('participants', ['consent_given' => false, 'onboarding_state' => 'AWAITING_CONSENT']);
    }
}
