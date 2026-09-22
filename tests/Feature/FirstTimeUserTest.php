<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramOutbox;
use App\Services\Telegram\BotFlow;
use App\Services\Telegram\Inbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FirstTimeUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
    }

    /** Runs one message from a brand-new participant and returns the bot's reply payload. */
    private function firstMessage(array $content, int $updateId = 1): array
    {
        app(Inbox::class)->ingest([['update_id' => $updateId, 'message' => ['message_id' => $updateId, 'date' => time(), 'chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555, 'is_bot' => false, 'first_name' => 'Aziza']] + $content]]);
        (new ProcessTelegramUpdate($updateId))->handle(app(BotFlow::class));

        return TelegramOutbox::where('update_id', $updateId)->where('kind', 'reply')->firstOrFail()->payload;
    }

    private function assertAgeButtons(array $reply): void
    {
        $this->assertStringContainsString('yoshda bo‘lishi kerak', $reply['text']);
        $buttons = collect($reply['reply_markup']['keyboard'] ?? [])->flatten()->all();
        $this->assertSame(array_keys(config('dataset.age_ranges')), $buttons, 'A first-time user must always receive the age-range buttons.');
    }

    public function test_first_time_start_sends_the_age_buttons(): void
    {
        $this->assertAgeButtons($this->firstMessage(['text' => '/start']));
        $this->assertDatabaseHas('participants', ['telegram_user_id' => 555, 'onboarding_state' => 'AWAITING_CONSENT', 'consent_given' => false]);
    }

    public function test_first_time_help_sends_the_age_buttons(): void
    {
        $this->assertAgeButtons($this->firstMessage(['text' => '/help']));
    }

    public function test_any_other_first_message_also_gets_the_age_buttons(): void
    {
        $this->assertAgeButtons($this->firstMessage(['text' => 'salom']));
    }

    public function test_a_voice_sent_as_first_message_gets_the_age_buttons_and_is_not_stored(): void
    {
        $reply = $this->firstMessage(['voice' => ['file_id' => 'f', 'file_unique_id' => 'u', 'duration' => 2, 'file_size' => 11]]);

        $this->assertAgeButtons($reply);
        $this->assertDatabaseCount('voice_recordings', 0);
    }

    public function test_status_for_a_new_participant_shows_zero_recordings(): void
    {
        $this->assertStringContainsString('0 ta ovoz', $this->firstMessage(['text' => '/status'])['text']);
    }
}
