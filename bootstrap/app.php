<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\PostTooLargeException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(
            at: '*',
            headers:
                Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO |
                Request::HEADER_X_FORWARDED_AWS_ELB
        );

        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
            'admin.access' => \App\Http\Middleware\CheckAdminAccess::class,
            'internal.basic_auth' => \App\Http\Middleware\InternalBasicAuthMiddleware::class,
            'enreach.events.secret' => \App\Http\Middleware\EnreachEventsSecretMiddleware::class,
            'motorflash.commercial_errors.secret' => \App\Http\Middleware\MotorflashCommercialErrorsSecretMiddleware::class,
        ]);

        $middleware->appendToGroup('web', [
            \App\Http\Middleware\EnsureActiveUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'El archivo supera el tamano maximo permitido.',
                ], 413);
            }

            return back()
                ->withInput($request->except('avatar'))
                ->withErrors([
                    'avatar' => 'La imagen es demasiado grande. Prueba con un archivo de menos de 2 MB.',
                ]);
        });
    })->create();
