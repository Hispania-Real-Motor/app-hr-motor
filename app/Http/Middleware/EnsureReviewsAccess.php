<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureReviewsAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app_can_access_reviews($request->user()), 403);

        return $next($request);
    }
}
