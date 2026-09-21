<?php

namespace App\Services;

use App\Models\Participant;
use App\Models\TelegramOutbox;
use App\Models\TelegramUpdate;
use App\Models\VoiceRecording;
use App\Services\Backup\GoogleDriveDestination;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ParticipantDeletion
{
    public function request(Participant $participant, int $adminId): void
    {
        Cache::lock('participant:'.$participant->telegram_user_id, 150)->block(5, function () use ($participant, $adminId) {
            DB::transaction(function () use ($participant, $adminId) {
                $participant->update(['deletion_requested_at' => now(), 'is_blocked' => true]);
                $participant->recordings()->update(['deletion_requested_at' => now()]);
                TelegramUpdate::where('telegram_user_id', $participant->telegram_user_id)->update(['payload' => null, 'processed_at' => now()]);
                TelegramOutbox::where('chat_id', $participant->telegram_user_id)->delete();
                DB::table('audit_events')->insert(['admin_id' => $adminId, 'action' => 'participant.deletion_requested', 'subject_id' => $participant->id, 'context' => json_encode(['recordings' => $participant->recording_count]), 'created_at' => now()]);
            });
        });
    }

    public function purge(): void
    {
        VoiceRecording::whereNotNull('deletion_requested_at')->chunkById(100, function ($records) {
            foreach ($records as $r) {
                Cache::lock('recording:'.$r->id, 360)->get(function () use ($r) {
                    $r->refresh();
                    // Always require the registered local agent to acknowledge deletion if local sync was enabled for this file.
                    if ($r->local_sync_status->value !== 'DISABLED' && ! $r->local_deleted_at) {
                        return;
                    }
                    if ($r->google_drive_file_id) {
                        app(GoogleDriveDestination::class)->delete($r->google_drive_file_id);
                    }
                    $path = app(OriginalStorage::class)->path($r->relative_storage_path);
                    if (is_file($path)) {
                        chmod($path, 0600);
                        if (! unlink($path)) {
                            throw new \RuntimeException('Local deletion failed');
                        }
                    }
                    DB::transaction(function () use ($r) {
                        DB::table('audit_events')->insert(['action' => 'recording.purged', 'subject_id' => $r->id, 'context' => json_encode(['participant_id' => $r->participant_id]), 'created_at' => now()]);
                        $r->delete();
                    });
                });
            }
        });
        Participant::whereNotNull('deletion_requested_at')->doesntHave('recordings')->get()->each(function ($p) {
            // Retain a minimal blocked tombstone to prevent queued messages resurrecting a deleted dataset.
            $p->update(['first_name' => null, 'telegram_username' => null, 'telegram_language_code' => null, 'gender' => null, 'age_range' => null, 'consent_given' => false, 'consent_at' => null, 'consent_version' => null, 'recording_count' => 0]);
        });
    }
}
