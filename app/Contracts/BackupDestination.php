<?php

namespace App\Contracts;

use App\Models\VoiceRecording;

interface BackupDestination
{
    public function backup(VoiceRecording $recording): string;

    public function delete(string $remoteId): void;
}
