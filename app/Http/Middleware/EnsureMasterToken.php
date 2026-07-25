<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single shared secret for the master panel. Enough for a 20-minute event on a
 * single droplet — no user table, no login flow to run on stage.
 */
class EnsureMasterToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('live.master_token');
        $given = $request->header('X-Master-Token') ?: $request->input('master_token');

        if (! $expected || ! is_string($given) || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Token do painel inválido.'], 401);
        }

        return $next($request);
    }
}
