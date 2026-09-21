<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DriveTokenCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['dataset.drive_client_id' => 'my-client.apps.googleusercontent.com', 'dataset.drive_client_secret' => 'my-secret']);
    }

    private function run_command(array $args = []): string
    {
        Artisan::call('dataset:drive-token', $args);

        return Artisan::output();
    }

    public function test_without_a_code_it_prints_a_consent_url_for_the_env_client(): void
    {
        $output = $this->run_command();

        $this->assertStringContainsString('client_id=my-client.apps.googleusercontent.com', $output);
        $this->assertStringContainsString('access_type=offline', $output);
        $this->assertStringContainsString('prompt=consent', $output);
        $this->assertStringContainsString(urlencode('https://www.googleapis.com/auth/drive'), $output);
    }

    public function test_it_exchanges_a_pasted_redirect_address_using_the_env_client(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['refresh_token' => '1//new-token', 'scope' => 'https://www.googleapis.com/auth/drive'])]);

        $output = $this->run_command(['--code' => 'http://localhost/?code=4%2F0AbCd&scope=https://www.googleapis.com/auth/drive']);

        $this->assertStringContainsString('GOOGLE_DRIVE_REFRESH_TOKEN=1//new-token', $output);
        Http::assertSent(fn ($r) => $r['code'] === '4/0AbCd' && $r['client_id'] === 'my-client.apps.googleusercontent.com' && $r['client_secret'] === 'my-secret' && $r['grant_type'] === 'authorization_code');
    }

    public function test_google_errors_are_explained(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Bad Request'], 400)]);

        $output = $this->run_command(['--code' => 'stale']);

        $this->assertStringContainsString('invalid_grant', $output);
        $this->assertStringContainsString('single-use', $output);
    }

    public function test_a_narrow_scope_is_flagged(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['refresh_token' => '1//t', 'scope' => 'https://www.googleapis.com/auth/drive.file'])]);

        $this->assertStringContainsString('cannot write into your folder', $this->run_command(['--code' => 'abc']));
    }
}
