<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SyncToken
{
    public function handle(Request $request, Closure $next)
    {
        $token = (string) config('dataset.sync_token');
        abort_unless(strlen($token) >= 32 && hash_equals($token, (string) $request->bearerToken()), 401);
        abort_unless($request->secure() || app()->environment(['local', 'testing']), 403, 'HTTPS required');

        return $next($request);
    }
}
