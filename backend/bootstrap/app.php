<?php

use App\Core\Http\PemetaGalat;
use App\Core\Http\SesiSpaStateful;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sesi + CSRF untuk permintaan dari SPA (SANCTUM_STATEFUL_DOMAINS) di grup `api`.
        // Pengganti statefulApi(): versi Sanctum memaksa SameSite=Lax.
        $middleware->api(prepend: [SesiSpaStateful::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['sandi', 'sandi_konfirmasi', 'sandi_lama']);

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*', 'sanctum/*')) {
                return PemetaGalat::render($e);
            }
        });
    })->create();
