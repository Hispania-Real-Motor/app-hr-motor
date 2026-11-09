<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnreachEventsSecretMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedSecret = (string) config('internal.enreach_events.secret');
        $providedSecret = (string) $request->header('X-Enreach-Secret', '');

        if ($expectedSecret === '' || $providedSecret === '' || ! hash_equals($expectedSecret, $providedSecret)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
