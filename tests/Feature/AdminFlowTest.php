<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramUpdate;
use App\Jobs\SendBroadcastMessage;
use App\Models\Participant;
use App\Models\TelegramOutbox;
use App\Services\Telegram\BotFlow;
use App\Services\Telegram\Inbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['telegram.admin_user_id' => 999999, 'dataset.target' => 2]);
    }

    private function update(int $id, array $content, int $fromId = 999999): void
    {
        app(Inbox::class)->ingest([['update_id' => $id, 'message' => ['message_id' => $id, 'date' => time(), 'chat' => ['id' => $fromId, 'type' => 'private'], 'from' => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Admin']] + $content]]);
        (new ProcessTelegramUpdate($id))->handle(app(BotFlow::class));
    }

    private function reply(int $updateId): array
    {
        return TelegramOutbox::where('update_id', $updateId)->where('kind', 'reply')->firstOrFail()->payload;
    }

    public function test_admin_sees_the_admin_menu_and_is_never_stored_as_a_participant(): void
    {
        $this->update(1, ['text' => '/start']);
        $buttons = collect($this->reply(1)['reply_markup']['keyboard'])->flatten()->all();
        $this->assertSame(['📊 Statistika', '📢 Eslatmalar'], $buttons);
        $this->assertDatabaseCount('participants', 0);
    }

    public function test_stats_button_reports_totals(): void
    {
        Participant::factory()->create(['telegram_user_id' => 1, 'recording_count' => 2, 'hard_negative_count' => 1]);
        Participant::factory()->create(['telegram_user_id' => 2, 'recording_count' => 0, 'hard_negative_count' => 0]);
        $this->update(2, ['text' => '📊 Statistika']);
        $text = $this->reply(2)['text'];
        $this->assertStringContainsString('Ishtirokchilar: 2', $text);
        $this->assertStringContainsString('Target’ga yetganlar: 1 / 2', $text);
        $this->assertStringContainsString('Hali yubormaganlar: 1 / 2', $text);
    }

    public function test_selecting_a_segment_asks_for_content_before_sending_anything(): void
    {
        $this->update(3, ['text' => '🎙 Hoy, Malika kam yuborganlar']);
        $reply = $this->reply(3);
        $this->assertStringContainsString('xabaringizni', $reply['text']);
        $this->assertSame(['📨 Standart xabar', '❌ Bekor qilish'], collect($reply['reply_markup']['keyboard'])->flatten()->all());
        Queue::assertNotPushed(SendBroadcastMessage::class);
    }

    public function test_default_message_button_sends_the_built_in_wording_to_the_wake_word_segment(): void
    {
        $needsWakeWord = Participant::factory()->create(['telegram_user_id' => 2, 'recording_count' => 0, 'hard_negative_count' => 0]);
        Participant::factory()->create(['telegram_user_id' => 1, 'recording_count' => 2, 'hard_negative_count' => 1]);

        $this->update(4, ['text' => '🎙 Hoy, Malika kam yuborganlar']);
        $this->update(5, ['text' => '📨 Standart xabar']);

        Queue::assertPushed(SendBroadcastMessage::class, 1);
        Queue::assertPushed(SendBroadcastMessage::class, fn ($job) => $job->chatId === $needsWakeWord->telegram_user_id && $job->method === 'sendMessage' && str_contains($job->params['text'], 'Hoy, Malika'));
        $this->assertStringContainsString('1 ta ishtirokchiga', $this->reply(5)['text']);
    }

    public function test_custom_text_is_broadcast_verbatim_to_the_chosen_segment(): void
    {
        $needsHardNegative = Participant::factory()->create(['telegram_user_id' => 3, 'recording_count' => 2, 'hard_negative_count' => 0]);
        Participant::factory()->create(['telegram_user_id' => 1, 'recording_count' => 2, 'hard_negative_count' => 1]);

        $this->update(6, ['text' => '🔀 O‘xshash so‘z yubormaganlar']);
        $this->update(7, ['text' => 'Assalomu alaykum, iltimos yana ovoz yuboring!']);

        Queue::assertPushed(SendBroadcastMessage::class, 1);
        Queue::assertPushed(SendBroadcastMessage::class, fn ($job) => $job->chatId === $needsHardNegative->telegram_user_id && $job->method === 'sendMessage' && $job->params['text'] === 'Assalomu alaykum, iltimos yana ovoz yuboring!');
    }

    public function test_a_video_note_is_broadcast_by_file_id_to_the_chosen_segment(): void
    {
        $needsEither = Participant::factory()->create(['telegram_user_id' => 4, 'recording_count' => 0, 'hard_negative_count' => 0]);
        Participant::factory()->create(['telegram_user_id' => 1, 'recording_count' => 2, 'hard_negative_count' => 1]);

        $this->update(8, ['text' => '📣 Ikkalasi ham (birortasi kam bo‘lganlar)']);
        $this->update(9, ['video_note' => ['file_id' => 'vn-123', 'file_unique_id' => 'u-vn-123', 'duration' => 5, 'length' => 240]]);

        Queue::assertPushed(SendBroadcastMessage::class, 1);
        Queue::assertPushed(SendBroadcastMessage::class, fn ($job) => $job->chatId === $needsEither->telegram_user_id && $job->method === 'sendVideoNote' && $job->params['video_note'] === 'vn-123');
    }

    public function test_cancel_clears_the_pending_segment_without_sending_anything(): void
    {
        $this->update(10, ['text' => '🎙 Hoy, Malika kam yuborganlar']);
        $this->update(11, ['text' => '❌ Bekor qilish']);
        $this->update(12, ['text' => 'this should not be broadcast to anyone']);

        Queue::assertNotPushed(SendBroadcastMessage::class);
    }

    public function test_hard_negative_reminder_only_notifies_participants_with_zero(): void
    {
        Participant::factory()->create(['telegram_user_id' => 1, 'recording_count' => 2, 'hard_negative_count' => 1]);
        Participant::factory()->create(['telegram_user_id' => 2, 'recording_count' => 2, 'hard_negative_count' => 0]);
        Participant::factory()->create(['telegram_user_id' => 3, 'recording_count' => 0, 'hard_negative_count' => 0]);

        $this->update(13, ['text' => '🔀 O‘xshash so‘z yubormaganlar']);
        $this->update(14, ['text' => '📨 Standart xabar']);

        Queue::assertPushed(SendBroadcastMessage::class, 2);
        $this->assertStringContainsString('2 ta ishtirokchiga', $this->reply(14)['text']);
    }

    public function test_either_reminder_skips_only_fully_completed_participants(): void
    {
        Participant::factory()->create(['telegram_user_id' => 1, 'recording_count' => 2, 'hard_negative_count' => 1]);
        Participant::factory()->create(['telegram_user_id' => 2, 'recording_count' => 2, 'hard_negative_count' => 0]);
        Participant::factory()->create(['telegram_user_id' => 3, 'recording_count' => 0, 'hard_negative_count' => 5]);
        Participant::factory()->create(['telegram_user_id' => 4, 'recording_count' => 0, 'consent_given' => false]);
        Participant::factory()->create(['telegram_user_id' => 5, 'recording_count' => 0, 'is_blocked' => true]);

        $this->update(15, ['text' => '📣 Ikkalasi ham (birortasi kam bo‘lganlar)']);
        $this->update(16, ['text' => '📨 Standart xabar']);

        Queue::assertPushed(SendBroadcastMessage::class, 2);
    }

    public function test_a_regular_participant_never_sees_the_admin_menu(): void
    {
        $this->update(17, ['text' => '/start'], fromId: 555);
        $buttons = collect($this->reply(17)['reply_markup']['keyboard'])->flatten()->all();
        $this->assertSame(array_keys(config('dataset.age_ranges')), $buttons);
    }
}
