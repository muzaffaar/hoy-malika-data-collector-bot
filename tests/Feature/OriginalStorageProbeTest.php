<?php

namespace Tests\Feature;

use App\Services\OriginalStorage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OriginalStorageProbeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_probe_succeeds_and_leaves_nothing_behind(): void
    {
        $storage = app(OriginalStorage::class);

        $this->assertNull($storage->writeProbe());
        $this->assertSame([], glob($storage->path(config('dataset.base_path')).'/.write-probe/*') ?: []);
        $this->assertDirectoryDoesNotExist($storage->path(config('dataset.base_path').'/.write-probe'));
    }

    public function test_probe_reports_why_voices_cannot_be_stored(): void
    {
        $storage = app(OriginalStorage::class);
        // A dedicated path: other tests leave read-only originals under the default one, which Windows cannot clean up.
        config(['dataset.base_path' => 'probe-blocked-'.bin2hex(random_bytes(4))]);
        // A regular file where the original directory should be makes every save fail, like a mount the container user cannot write to.
        Storage::disk('local')->put(config('dataset.base_path'), 'not a directory');

        $error = $storage->writeProbe();

        $this->assertNotNull($error);
        $this->assertStringContainsString('.write-probe', $error);
    }
}
