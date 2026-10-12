<?php

namespace App\Modules\Akses\Http\Middleware;

use App\Modules\Akses\Domain\Cakupan;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * `can.rs:<izin>`: izin itu harus dimiliki dengan cakupan seluruh RS, bukan cakupan mana pun seperti `can:` (PRD 9.2.4,
 * 9.2.5; temuan ASVS 4.1.3 tiket 1.12). Dipakai pengelolaan peran dan akun: datanya milik seluruh RS, dan admin
 * delegasi per unit (PRD 9.2.4) belum dibangun, jadi pemegang bercakupan unit tidak boleh mengelola akun RS lain.
 */
final class IzinSeluruhRs
{
    public function handle(Request $request, Closure $next, string $izin): Response
    {
        Gate::authorize($izin, Cakupan::rs());

        return $next($request);
    }
}
