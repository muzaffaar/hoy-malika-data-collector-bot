<?php
namespace Tests\Feature;
use App\Services\Telegram\Inbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
class TelegramInboxTest extends TestCase {
 use RefreshDatabase;
 public function test_ingest_is_idempotent_and_advances_cursor(): void {
  Queue::fake(); $u=['update_id'=>42,'message'=>['from'=>['id'=>7]]];
  app(Inbox::class)->ingest([$u]); app(Inbox::class)->ingest([$u]);
  $this->assertDatabaseCount('telegram_updates',1); $this->assertDatabaseHas('telegram_cursors',['id'=>1,'last_ingested_id'=>42]);
 }
}
