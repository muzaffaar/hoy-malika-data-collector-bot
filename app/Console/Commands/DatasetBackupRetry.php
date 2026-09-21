<?php

namespace App\Console\Commands;

use App\Jobs\BackupRecording;
use App\Models\VoiceRecording;
use Illuminate\Console\Command;

class DatasetBackupRetry extends Command
{
    protected $signature = 'dataset:backup-retry';

    protected $description = 'Queue pending Drive copies without touching server originals';

    public function handle(): int
    {
        if (! config('dataset.drive_enabled')) {
            $this->error('Google Drive is disabled');

            return 1;
        }
        VoiceRecording::whereNull('deletion_requested_at')->where('backup_status', '!=', 'COMPLETED')->chunkById(200, function ($records) {
            foreach ($records as $r) {
                BackupRecording::dispatch($r->id);
            }
        });
        $this->info('Pending backups queued.');

        return 0;
    }
}
