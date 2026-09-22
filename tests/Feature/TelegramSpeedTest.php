<?php

namespace Tests\Feature;

use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class TelegramSpeedTest extends TestCase
{
    public function test_speed_report_compares_default_and_ipv4_routing_without_leaking_the_token(): void
    {
        config(['telegram.token' => '123456:SECRET-TOKEN']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'bot']])]);

        $status = Artisan::call('telegram:speed', ['--samples' => 2]);
        $output = Artisan::output();

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Default routing', $output);
        $this->assertStringContainsString('IPv4 only', $output);
        $this->assertStringContainsString('PostgreSQL round trip', $output);
        $this->assertStringNotContainsString('SECRET-TOKEN', $output);
    }

    public function test_speed_report_needs_a_token(): void
    {
        config(['telegram.token' => '']);

        $this->assertSame(1, Artisan::call('telegram:speed'));
        $this->assertStringContainsString('TELEGRAM_BOT_TOKEN is not set', Artisan::output());
    }

    public function test_a_slow_telegram_call_is_logged_with_its_stage(): void
    {
        config(['telegram.token' => 'token']);
        Log::spy();
        Http::fake(['api.telegram.org/*' => function () {
            usleep(1_600_000);

            return Http::response(['ok' => true, 'result' => []]);
        }]);

        app(TelegramClient::class)->call('sendMessage', ['chat_id' => 1, 'text' => 'x']);

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === 'telegram.slow_call' && $context['call'] === 'sendMessage' && $context['ms'] >= 1500)->once();
    }

    public function test_a_fast_call_is_not_logged_as_slow(): void
    {
        config(['telegram.token' => 'token']);
        Log::spy();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        app(TelegramClient::class)->call('sendMessage', ['chat_id' => 1, 'text' => 'x']);

        Log::shouldNotHaveReceived('warning', ['telegram.slow_call', \Mockery::any()]);
    }

    private function cpuinfo(int $cpus): string
    {
        return implode("\n", array_map(fn ($i) => "processor\t: $i\nmodel name\t: test", range(0, $cpus - 1)))."\n";
    }

    private function meminfo(int $totalMb, int $availableMb, int $swapUsedMb = 0): string
    {
        return sprintf("MemTotal: %d kB\nMemAvailable: %d kB\nSwapTotal: 2097152 kB\nSwapFree: %d kB\n", $totalMb * 1024, $availableMb * 1024, (2048 - $swapUsedMb) * 1024);
    }

    public function test_load_far_above_the_cpu_count_is_reported_as_overload(): void
    {
        // Load 14.4 on a 2-CPU server: the CPU is starved.
        $lines = collect((new \App\Console\Commands\TelegramSpeed)->assessHost([14.45, 14.33, 14.25], $this->cpuinfo(2), $this->meminfo(2048, 900)));

        $this->assertTrue($lines->contains(fn ($l) => $l[0] === 'error' && str_contains($l[1], 'SERVER OVERLOADED') && str_contains($l[1], '7.2x')));
    }

    public function test_a_healthy_server_produces_no_warning(): void
    {
        $lines = collect((new \App\Console\Commands\TelegramSpeed)->assessHost([1.2, 1.0, 0.9], $this->cpuinfo(4), $this->meminfo(4096, 2500)));

        $this->assertSame(['line'], $lines->pluck(0)->unique()->all());
    }

    public function test_low_memory_and_heavy_swap_are_reported(): void
    {
        $lines = collect((new \App\Console\Commands\TelegramSpeed)->assessHost([0.5, 0.5, 0.5], $this->cpuinfo(2), $this->meminfo(2048, 100, 700)));

        $this->assertTrue($lines->contains(fn ($l) => $l[0] === 'error' && str_contains($l[1], 'LOW MEMORY')));
        $this->assertTrue($lines->contains(fn ($l) => $l[0] === 'warn' && str_contains($l[1], 'Swap in use is high')));
    }

    public function test_non_linux_hosts_are_handled(): void
    {
        $this->assertSame([['line', 'CPU/memory details are only available on Linux hosts.']], (new \App\Console\Commands\TelegramSpeed)->assessHost([1.0], '', ''));
    }
}
