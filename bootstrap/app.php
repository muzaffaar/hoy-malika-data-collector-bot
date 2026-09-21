<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Laravel only recognises the '*' wildcard as a plain string; inside an array it is a literal address that matches nothing.
        $raw = trim((string) env('TRUSTED_PROXIES', ''));
        $proxies = in_array($raw, ['*', '**'], true) ? $raw : array_filter(array_map('trim', explode(',', $raw)));
        if ($proxies) {
            $middleware->trustProxies(at: $proxies);
        }
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
