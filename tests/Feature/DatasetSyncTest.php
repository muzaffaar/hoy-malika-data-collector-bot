<?php
namespace Tests\Feature;
use App\Models\VoiceRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class DatasetSyncTest extends TestCase {
 use RefreshDatabase;
 private string $token='12345678901234567890123456789012';
 public function test_sync_api_requires_token(): void { $this->getJson('/api/dataset-sync/pending')->assertUnauthorized(); }
 public function test_pending_and_completion_require_matching_integrity(): void {
  $r=VoiceRecording::factory()->create();
  $h=['Authorization'=>'Bearer '.$this->token];
  $this->withHeaders($h)->getJson('/api/dataset-sync/pending')->assertOk()->assertJsonFragment(['id'=>$r->id]);
  $this->withHeaders($h)->postJson("/api/dataset-sync/{$r->id}/complete",['sha256'=>str_repeat('0',64),'size'=>7])->assertStatus(422);
  $this->withHeaders($h)->postJson("/api/dataset-sync/{$r->id}/complete",['sha256'=>$r->sha256_checksum,'size'=>7])->assertNoContent();
  $this->assertDatabaseHas('voice_recordings',['id'=>$r->id,'local_sync_status'=>'COMPLETED']);
 }
}
