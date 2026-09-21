<?php

namespace App\Enums;

enum BackupStatus: string
{
    case PENDING = 'PENDING';
    case UPLOADING = 'UPLOADING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
    case RETRYING = 'RETRYING';
    case DISABLED = 'DISABLED';
}
