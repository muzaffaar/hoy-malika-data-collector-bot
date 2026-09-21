<?php

namespace Database\Factories;

use App\Models\Participant;
use App\Models\VoiceRecording;
use Illuminate\Database\Eloquent\Factories\Factory;

class VoiceRecordingFactory extends Factory
{
    protected $model = VoiceRecording::class;

    public function definition(): array
    {
        $id = fake()->uuid();

        return ['id' => $id, 'participant_id' => Participant::factory(), 'telegram_message_id' => fake()->unique()->numberBetween(1, 2000000000), 'telegram_chat_id' => 12345,
            'telegram_file_id' => 'fixture-'.$id, 'telegram_file_unique_id' => 'unique-'.$id, 'stored_filename' => $id.'.ogg', 'relative_storage_path' => 'dataset/original/fixtures/'.$id.'.ogg',
            'mime_type' => 'audio/ogg', 'original_extension' => 'ogg', 'duration_seconds' => 2, 'file_size_bytes' => 7, 'sha256_checksum' => hash('sha256', 'fixture'),
            'telegram_received_at' => now(), 'downloaded_at' => now(), 'backup_status' => 'PENDING', 'local_sync_status' => 'PENDING'];
    }
}
