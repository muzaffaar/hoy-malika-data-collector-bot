<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class HttpsUrlTest extends TestCase
{
    private function boot_with_app_url(string $url): void
    {
        config(['app.url' => $url]);
        URL::forceScheme(null);
        (new \App\Providers\AppServiceProvider($this->app))->boot();
    }

    public function test_urls_use_https_when_app_url_is_https_even_if_proxy_headers_are_untrusted(): void
    {
        $this->boot_with_app_url('https://data.example.com');

        // A TLS-terminating proxy forwards plain HTTP, which is all Laravel sees without trusted proxies.
        $this->get('http://data.example.com/admin/login');

        $this->assertSame('https://data.example.com/build/assets/app.css', asset('build/assets/app.css'));
        $this->assertStringStartsWith('https://', route('login'));
    }

    public function test_urls_keep_the_request_scheme_when_app_url_is_http(): void
    {
        $this->boot_with_app_url('http://localhost');

        $this->assertStringStartsWith('http://', asset('build/assets/app.css'));
    }
}
