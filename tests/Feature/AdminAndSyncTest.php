<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\User;
use App\Models\VoiceRecording;
use App\Services\ParticipantDeletion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminAndSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['dataset.sync_token' => str_repeat('a', 40)]);
    }

    public function test_admin_requires_authentication_and_login_logout_work(): void
    {
        $this->get('/admin/dashboard')->assertRedirect('/admin/login');
        $this->get('/admin/recordings')->assertRedirect('/admin/login');
        $u = User::factory()->create(['password' => Hash::make('a-long-test-password')]);
        $this->post('/admin/login', ['email' => $u->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/admin/login', ['email' => $u->email, 'password' => 'a-long-test-password'])->assertRedirect('/admin/recordings');
        $this->assertAuthenticatedAs($u);
        $this->get('/admin/dashboard')->assertOk()->assertSee('Collection insights');
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_admin_pages_filters_and_validation(): void
    {
        $this->actingAs(User::factory()->create());
        $p = Participant::factory()->create(['first_name' => 'Aziza', 'gender' => 'FEMALE']);
        $r = VoiceRecording::factory()->create(['participant_id' => $p->id]);
        $this->get('/admin/participants?gender=FEMALE')->assertOk()->assertSee('Aziza');
        $this->get('/admin/participants?gender=MALE')->assertOk()->assertDontSee('Aziza');
        $this->get('/admin/participants/'.$p->id)->assertOk();
        $this->get('/admin/recordings')->assertOk();
        $this->get('/admin/recordings/'.$r->id)->assertOk();
        $this->get('/admin/system')->assertOk();
        $this->get('/admin/dashboard?from=bad')->assertSessionHasErrors('from');
    }

    public function test_sync_requires_token_and_matching_checksum(): void
    {
        $r = VoiceRecording::factory()->create();
        Storage::disk('local')->put($r->relative_storage_path, 'fixture');
        $this->getJson('/api/dataset-sync/pending')->assertUnauthorized();
        $this->withToken(str_repeat('a', 40))->getJson('/api/dataset-sync/pending')->assertOk()->assertJsonFragment(['id' => $r->id]);
        $this->get('/api/dataset-sync/'.$r->id.'/download')->assertOk();
        $this->postJson('/api/dataset-sync/'.$r->id.'/complete', ['sha256' => str_repeat('0', 64), 'size' => 7])->assertUnprocessable();
        $this->assertDatabaseHas('voice_recordings', ['id' => $r->id, 'local_sync_status' => 'PENDING']);
        $this->postJson('/api/dataset-sync/'.$r->id.'/complete', ['sha256' => $r->sha256_checksum, 'size' => 7])->assertNoContent();
        $this->assertDatabaseHas('voice_recordings', ['id' => $r->id, 'local_sync_status' => 'COMPLETED']);
    }

    public function test_corrupt_server_copy_cannot_be_downloaded_or_pass_verification(): void
    {
        $r = VoiceRecording::factory()->create();
        Storage::disk('local')->put($r->relative_storage_path, 'corrupt');
        $this->withToken(str_repeat('a', 40))->get('/api/dataset-sync/'.$r->id.'/download')->assertStatus(409);
        $this->artisan('dataset:verify')->assertExitCode(1);
    }

    public function test_deletion_waits_for_local_ack_and_is_audited(): void
    {
        $u = User::factory()->create();
        $r = VoiceRecording::factory()->create();
        Storage::disk('local')->put($r->relative_storage_path, 'fixture');
        $this->actingAs($u)->delete('/admin/participants/'.$r->participant_id, ['confirmation' => 'DELETE '.$r->participant_id])->assertRedirect();
        app(ParticipantDeletion::class)->purge();
        $this->assertDatabaseCount('voice_recordings', 1);
        $this->withToken(str_repeat('a', 40))->getJson('/api/dataset-sync/deletions')->assertJsonFragment(['id' => $r->id]);
        $this->postJson('/api/dataset-sync/'.$r->id.'/deleted', [])->assertNoContent();
        app(ParticipantDeletion::class)->purge();
        $this->assertDatabaseCount('voice_recordings', 0);
        Storage::disk('local')->assertMissing($r->relative_storage_path);
        $this->assertDatabaseHas('audit_events', ['action' => 'recording.purged']);
    }
}
