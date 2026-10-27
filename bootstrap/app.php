<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'superadmin' => \App\Http\Middleware\Superadmin::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function ($response, $e, $request) {
            $status = $response->getStatusCode();

            if (in_array($status, [401, 403, 404, 419, 500, 503])) {
                // Preserve JSON responses for API requests
                if ($request->expectsJson()) {
                    return $response;
                }

                // In local/dev environment, retain detailed debug page for 500 errors unless explicitly testing preview
                if ($status === 500 && app()->hasDebugModeEnabled() && ! $request->has('preview_error')) {
                    return $response;
                }

                return \Inertia\Inertia::render('Error', [
                    'status' => $status,
                    'message' => $e instanceof \Throwable && ! empty($e->getMessage()) ? $e->getMessage() : null,
                ])
                ->toResponse($request)
                ->setStatusCode($status);
            }

            return $response;
        });
    })->create();
