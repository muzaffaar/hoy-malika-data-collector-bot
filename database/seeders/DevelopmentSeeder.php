<?php

namespace Database\Seeders;

use App\Models\Participant;
use App\Models\VoiceRecording;
use Illuminate\Database\Seeder;

class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Development data cannot be seeded in production');
        }
        for ($i = 0; $i < 90; $i++) {
            $at = now()->subDays(random_int(0, 29))->subHours(random_int(0, 12));
            $count = random_int(0, 35);
            $p = Participant::factory()->create(['created_at' => $at, 'first_interaction_at' => $at, 'recording_count' => $count, 'onboarding_state' => $count >= config('dataset.target') ? 'COMPLETED' : 'COLLECTING']);
            for ($n = 0; $n < $count; $n++) {
                VoiceRecording::factory()->create(['participant_id' => $p->id, 'telegram_chat_id' => $p->telegram_user_id, 'telegram_received_at' => $at->copy()->addMinutes($n), 'created_at' => $at, 'validation_status' => 'DEMO_NO_AUDIO', 'backup_status' => 'DISABLED', 'local_sync_status' => 'DISABLED']);
            }
        }
    }
}
