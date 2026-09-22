<?php

namespace App\Console\Commands;

use App\Models\Participant;
use App\Models\VoiceRecording;
use Illuminate\Console\Command;

class DatasetStats extends Command
{
    protected $signature = 'dataset:stats';

    protected $description = 'Show dataset totals';

    public function handle(): int
    {
        $this->table(['Metric', 'Value'], [['Participants', Participant::count()], ['Recordings', VoiceRecording::count()], ['Wake-word recordings', VoiceRecording::where('sample_type', 'WAKE_WORD')->count()], ['Hard-negative recordings', VoiceRecording::where('sample_type', 'HARD_NEGATIVE')->count()], ['Bytes', VoiceRecording::sum('file_size_bytes')], ['Drive pending', VoiceRecording::whereNotIn('backup_status', ['COMPLETED', 'DISABLED'])->count()], ['Local pending', VoiceRecording::whereNotIn('local_sync_status', ['COMPLETED', 'DISABLED'])->count()]]);

        return 0;
    }
}
