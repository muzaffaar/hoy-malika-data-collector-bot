<?php

return [
    'target' => (int) env('TARGET_RECORDINGS_PER_USER', 20),
    'min_age' => (int) env('MIN_PARTICIPANT_AGE', 18),
    'age_ranges' => ['18–24' => 18, '25–34' => 25, '35–44' => 35, '45–54' => 45, '55–64' => 55, '65+' => 65],
    'consent_version' => '2026-09-v1',
    'min_duration' => (float) env('VOICE_MIN_DURATION_SECONDS', 0.5),
    'max_duration' => (float) env('VOICE_MAX_DURATION_SECONDS', 6),
    'max_bytes' => (int) env('VOICE_MAX_BYTES', 20971520),
    'accept_audio' => (bool) env('ACCEPT_AUDIO_MESSAGES', false),
    'disk' => env('DATASET_DISK', 'local'),
    'base_path' => 'dataset/original',
    'hard_negative_base_path' => 'dataset/hard_negative',
    'display_timezone' => env('APP_TIMEZONE', 'Asia/Tashkent'),
    'minimum_free_bytes' => (int) env('MINIMUM_FREE_DISK_BYTES', 1073741824),
    'local_sync' => (bool) env('LOCAL_SYNC_ENABLED', true),
    'sync_token' => env('LOCAL_SYNC_API_TOKEN'),
    'drive_enabled' => (bool) env('GOOGLE_DRIVE_BACKUP_ENABLED', false),
    'drive_folder' => env('GOOGLE_DRIVE_FOLDER_ID'),
    'drive_client_id' => env('GOOGLE_DRIVE_CLIENT_ID'),
    'drive_client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
    'drive_refresh_token' => env('GOOGLE_DRIVE_REFRESH_TOKEN'),
];
