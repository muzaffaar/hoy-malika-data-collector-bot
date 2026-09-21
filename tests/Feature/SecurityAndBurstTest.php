<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\Participant;
use App\Models\TelegramOutbox;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Models\VoiceRecording;
use App\Services\Telegram\BotFlow;
use App\Services\Telegram\Inbox;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityAndBurstTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
        Storage::fake('local');
        config(['dataset.minimum_free_bytes' => 0]);
    }

    public function test_ten_rapid_voices_are_all_saved_once_in_order(): void
    {
        Participant::factory()->create(['telegram_user_id' => 123]);
        $updates = [];
        for ($id = 1; $id <= 10; $id++) {
            $updates[] = ['update_id' => $id, 'message' => ['message_id' => $id, 'date' => time(), 'chat' => ['id' => 123, 'type' => 'private'], 'from' => ['id' => 123, 'is_bot' => false], 'voice' => ['file_id' => 'f'.$id, 'file_unique_id' => 'u'.$id, 'duration' => 2, 'file_size' => 7]]];
        }
        $this->mock(TelegramClient::class, fn ($m) => $m->shouldReceive('download')->times(10)->andReturnUsing(function ($id, $path) {
            file_put_contents($path, 'fixture');

            return ['file_size' => 7, 'file_path' => 'voice/file.oga'];
        }));
        app(Inbox::class)->ingest($updates);
        app(Inbox::class)->ingest($updates);
        (new ProcessTelegramUpdate(10))->handle(app(BotFlow::class));
        $this->assertDatabaseCount('voice_recordings', 0);
        for ($id = 1; $id <= 10; $id++) {
            (new ProcessTelegramUpdate($id))->handle(app(BotFlow::class));
        }
        $this->assertDatabaseCount('voice_recordings', 10);
        $this->assertDatabaseHas('participants', ['recording_count' => 10]);
        $this->assertSame(0, TelegramUpdate::whereNull('processed_at')->count());
        $this->assertSame(10, TelegramOutbox::count());
        $this->assertSame('oga', VoiceRecording::first()->original_extension);
        for ($id = 1; $id <= 10; $id++) {
            (new ProcessTelegramUpdate($id))->handle(app(BotFlow::class));
        }
        $this->assertDatabaseCount('voice_recordings', 10);
    }

    public function test_admin_download_is_private_and_sessions_expire(): void
    {
        $r = VoiceRecording::factory()->create();
        Storage::disk('local')->put($r->relative_storage_path, 'fixture');
        $this->get('/admin/recordings/'.$r->id.'/download')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->withSession(['admin_last_activity' => time() - 7200])->get('/admin/dashboard')->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_login_is_throttled_and_csrf_is_enforced_outside_test_bypass(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/login', ['email' => 'nobody@example.test', 'password' => 'wrong']);
        }
        $this->post('/admin/login', ['email' => 'nobody@example.test', 'password' => 'wrong'])->assertSessionHasErrors(['email' => 'Too many attempts. Try again in a minute.']);
        $this->app['env'] = 'production';
        $this->post('/admin/login', ['email' => 'nobody@example.test', 'password' => 'wrong'])->assertStatus(419);
    }

    public function test_empty_sync_token_fails_closed(): void
    {
        config(['dataset.sync_token' => '']);
        $this->getJson('/api/dataset-sync/pending')->assertUnauthorized();
    }

    public function test_non_audio_contents_cannot_execute_in_admin_origin(): void
    {
        $bytes = '<script>alert(1)</script>';
        $r = VoiceRecording::factory()->create(['mime_type' => 'text/html', 'file_size_bytes' => strlen($bytes), 'sha256_checksum' => hash('sha256', $bytes)]);
        Storage::disk('local')->put($r->relative_storage_path, $bytes);
        $response = $this->actingAs(User::factory()->create())->get('/admin/recordings/'.$r->id.'/download');
        $response->assertOk()->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
    }
}
