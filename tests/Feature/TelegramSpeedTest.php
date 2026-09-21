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
}
