<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class WorkerHeartbeat implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $service = 'queue') {}

    public function handle(): void
    {
        Cache::put('heartbeat:'.$this->service, now()->timestamp, 300);
    }
}
