<?php

use App\Core\Http\ErrorHandler;
use App\Core\Http\SesiSpaStateful;
use App\Core\Http\TrustedProxies;
use App\Core\Log\SqlMasker;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([__DIR__.'/../app/Core/Audit/Console'])
    ->withMiddleware(function (Middleware $middleware): void {
        // Sesi + CSRF untuk permintaan dari SPA (SANCTUM_STATEFUL_DOMAINS) di grup `api`.
        // Pengganti statefulApi(): versi Sanctum memaksa SameSite=Lax.
        $middleware->api(prepend: [SesiSpaStateful::class]);

        // Rate limit per pengguna untuk semua rute api/v1 (PRD 14.1.1 butir 7); limiter di AppServiceProvider.
        $middleware->throttleApi('api');

        // Trusted proxy dari config (TRUSTED_PROXIES), bukan nilai tetap di kode.
        $middleware->replace(TrustProxies::class, TrustedProxies::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['sandi', 'sandi_konfirmasi', 'sandi_lama']);

        // Nilai dibuang dari error basis data sebelum dilaporkan atau dirender (SqlMasker).
        $exceptions->map(function (QueryException $e) {
            SqlMasker::samarkanException($e);

            return $e;
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*', 'sanctum/*')) {
                return ErrorHandler::render($e);
            }
        });
    })->create();
