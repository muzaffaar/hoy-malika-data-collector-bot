<?php

namespace App\Jobs;

use App\Enums\BackupStatus;
use App\Models\VoiceRecording;
use App\Services\Backup\GoogleDriveDestination;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BackupRecording implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 300;

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function __construct(public string $recordingId)
    {
        $this->onQueue('google-drive');
    }

    public function handle(GoogleDriveDestination $drive): void
    {
        if (! config('dataset.drive_enabled')) {
            return;
        }
        $lock = Cache::lock('recording:'.$this->recordingId, 360);
        if (! $lock->get()) {
            return;
        }
        try {
            $r = VoiceRecording::find($this->recordingId);
            if (! $r || $r->deletion_requested_at || $r->backup_status === BackupStatus::COMPLETED) {
                return;
            }
            $r->update(['backup_status' => BackupStatus::UPLOADING]);
            try {
                $id = $drive->backup($r);
                $r->update(['backup_status' => BackupStatus::COMPLETED, 'backup_destination' => 'google-drive', 'google_drive_file_id' => $id, 'backup_completed_at' => now(), 'backup_error' => null]);
                Log::info('drive.completed', ['recording_id' => $r->id]);
            } catch (\Throwable $e) {
                $r->update(['backup_status' => BackupStatus::FAILED, 'backup_error' => 'Upload or integrity verification failed']);
                Log::error('drive.failed', ['recording_id' => $r->id, 'exception_type' => get_class($e), 'message' => $e->getMessage()]);
                throw new \RuntimeException('Drive backup failed; server original retained');
            }
        } finally {
            $lock->release();
        }
    }
}
