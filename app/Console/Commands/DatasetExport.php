<?php

namespace App\Console\Commands;

use App\Models\VoiceRecording;
use Illuminate\Console\Command;

class DatasetExport extends Command
{
    protected $signature = 'dataset:export';

    protected $description = 'Write private metadata CSV without copying audio';

    public function handle(): int
    {
        $dir = storage_path('app/private/exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $path = $dir.'/metadata_'.now()->format('Ymd_His').'_'.bin2hex(random_bytes(4)).'.csv';
        $handle = fopen($path, 'xb');
        if (! $handle) {
            throw new \RuntimeException('Cannot create export');
        }
        try {
            fputcsv($handle, ['recording_id', 'participant_id', 'sample_type', 'gender', 'age_range', 'relative_audio_path', 'duration', 'sha256', 'recorded_at'], ',', '"', '');
            VoiceRecording::whereNull('deletion_requested_at')->with('participant')->chunkById(200, function ($records) use ($handle) {
                foreach ($records as $r) {
                    fputcsv($handle, [$r->id, $r->participant_id, $r->sample_type?->value, $r->participant->gender?->value, $r->participant->age_range, $r->relative_storage_path, $r->duration_seconds, $r->sha256_checksum, $r->telegram_received_at->toIso8601String()], ',', '"', '');
                }
            });
        } finally {
            fclose($handle);
        }
        chmod($path, 0600);
        $this->info($path);

        return 0;
    }
}
