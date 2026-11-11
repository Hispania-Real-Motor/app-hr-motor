<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MotorflashCommercialErrorsSecretMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedSecret = (string) config('internal.motorflash_commercial_errors.secret');
        $providedSecret = (string) $request->header('X-Motorflash-Commercial-Errors-Secret', '');

        if ($expectedSecret === '' || $providedSecret === '' || ! hash_equals($expectedSecret, $providedSecret)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
