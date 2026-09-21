<?php

namespace App\Console\Commands;

use App\Models\VoiceRecording;
use App\Services\OriginalStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DatasetVerify extends Command
{
    protected $signature = 'dataset:verify';

    protected $description = 'Verify original sizes and checksums and report backup status';

    public function handle(OriginalStorage $storage): int
    {
        $bad = 0;
        $total = 0;
        VoiceRecording::chunkById(200, function ($records) use ($storage, &$bad, &$total) {
            foreach ($records as $r) {
                $total++;
                $valid = $storage->valid($r);
                $r->update(['validation_status' => $valid ? 'VALID' : 'CORRUPT', 'validation_failure_reason' => $valid ? null : 'Original missing or checksum/size mismatch']);
                if (! $valid) {
                    $bad++;
                    $this->error('Missing/corrupt: '.$r->id);
                    Log::critical('dataset.integrity_failure', ['recording_id' => $r->id]);
                }
            }
        });
        $this->info("Verified {$total}; integrity failures: {$bad}");
        $this->table(['Drive status', 'Count'], VoiceRecording::selectRaw('backup_status, count(*) as total')->groupBy('backup_status')->get()->map(fn ($r) => [$r->backup_status->value, $r->total]));
        $this->table(['Local status', 'Count'], VoiceRecording::selectRaw('local_sync_status, count(*) as total')->groupBy('local_sync_status')->get()->map(fn ($r) => [$r->local_sync_status->value, $r->total]));

        return $bad ? 1 : 0;
    }
}
