<?php

namespace Tests\Feature;

use App\Jobs\BackupRecording;
use App\Jobs\SendTelegramReply;
use App\Models\Participant;
use App\Models\TelegramOutbox;
use App\Models\VoiceRecording;
use App\Services\Backup\GoogleDriveDestination;
use App\Services\OriginalStorage;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupAndRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['dataset.minimum_free_bytes' => 0, 'dataset.drive_enabled' => true]);
    }

    public function test_drive_upload_preallocates_id_and_verifies_remote_checksum(): void
    {
        $r = VoiceRecording::factory()->create();
        Storage::disk('local')->put($r->relative_storage_path, 'fixture');
        config(['dataset.drive_folder' => 'root-folder']);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token']),
            'www.googleapis.com/drive/v3/files/generateIds*' => Http::response(['ids' => ['remote-1']]),
            'www.googleapis.com/drive/v3/files/remote-1*' => Http::sequence()->push([], 404)->push(['id' => 'remote-1', 'size' => '7', 'sha256Checksum' => $r->sha256_checksum]),
            'www.googleapis.com/drive/v3/files?*' => Http::response(['files' => [['id' => 'folder-1']]]),
            'www.googleapis.com/upload/*' => Http::response(['id' => 'remote-1']),
        ]);
        (new BackupRecording($r->id))->handle(app(GoogleDriveDestination::class));
        $this->assertDatabaseHas('voice_recordings', ['id' => $r->id, 'backup_status' => 'COMPLETED', 'google_drive_file_id' => 'remote-1']);
        Storage::disk('local')->assertExists($r->relative_storage_path);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'upload/') && str_contains($request->body(), 'fixture'));
    }

    public function test_remote_integrity_failure_never_deletes_server_original(): void
    {
        $r = VoiceRecording::factory()->create(['google_drive_file_id' => 'remote-2']);
        Storage::disk('local')->put($r->relative_storage_path, 'fixture');
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'test']), 'www.googleapis.com/drive/v3/files/remote-2*' => Http::response(['size' => '7', 'sha256Checksum' => str_repeat('0', 64)])]);
        try {
            (new BackupRecording($r->id))->handle(app(GoogleDriveDestination::class));
            $this->fail('Expected failure');
        } catch (\RuntimeException) {
        }
        $this->assertDatabaseHas('voice_recordings', ['id' => $r->id, 'backup_status' => 'FAILED']);
        Storage::disk('local')->assertExists($r->relative_storage_path);
    }

    public function test_crash_recovery_adopts_only_a_matching_existing_original(): void
    {
        $p = Participant::factory()->create();
        $message = ['message_id' => 1, 'date' => time(), 'voice' => []];
        $voice = ['file_id' => 'id', 'file_size' => 7];
        $this->mock(TelegramClient::class, fn ($m) => $m->shouldReceive('download')->andReturnUsing(function ($id, $path) {
            file_put_contents($path, 'fixture');

            return ['file_size' => 7];
        }));
        $storage = app(OriginalStorage::class);
        $first = $storage->save($p, $message, $voice);
        $second = $storage->save($p, $message, $voice);
        $this->assertSame($first['relative_storage_path'], $second['relative_storage_path']);
        $path = $storage->path($first['relative_storage_path']);
        chmod($path, 0600);
        file_put_contents($path, 'changed');
        try {
            $storage->save($p, $message, $voice);
            $this->fail('Expected conflict');
        } catch (\RuntimeException $e) {
            $this->assertSame('Original integrity conflict', $e->getMessage());
        }
        $this->assertSame('changed', file_get_contents($path));
    }

    public function test_failed_reply_is_retried_without_reprocessing_audio(): void
    {
        $out = TelegramOutbox::create(['update_id' => 1, 'chat_id' => 1, 'payload' => ['text' => 'saved']]);
        $client = $this->mock(TelegramClient::class, fn ($m) => $m->shouldReceive('call')->once()->andThrow(new TelegramApiException(60, 429)));
        (new SendTelegramReply($out->id))->handle($client);
        $this->assertNull($out->fresh()->sent_at);
        $this->assertTrue($out->fresh()->available_at->isFuture());
    }

    public function test_telegram_rate_limit_is_redacted_and_retry_after_preserved(): void
    {
        config(['telegram.token' => 'secret-test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 45]], 429)]);
        try {
            app(TelegramClient::class)->call('getUpdates');
            $this->fail('Expected rate limit');
        } catch (TelegramApiException $e) {
            $this->assertSame(45, $e->retryAfter);
            $this->assertStringNotContainsString('secret-test-token', $e->getMessage());
        }
    }
}
