<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurriculaAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app_can_access_curriculums($request->user()), 403);

        return $next($request);
    }
}
