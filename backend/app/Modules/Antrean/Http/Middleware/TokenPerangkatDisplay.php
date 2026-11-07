<?php

namespace App\Modules\Antrean\Http\Middleware;

use App\Core\Http\ApiError;
use App\Modules\Akses\Models\TokenAksesPribadi;
use App\Modules\Antrean\Models\DisplayAntrean;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentikasi token perangkat display (tiket 2.8; PRD 7.01.1, 10.3.1), bukan sesi cookie. Token tidak dikenal, dicabut,
 * kedaluwarsa, atau perangkatnya nonaktif → 401; token tanpa kemampuan `display` atau area di luar daftar perangkat → 403.
 * Perangkat yang lolos disimpan di atribut permintaan `perangkat_display`.
 */
final class TokenPerangkatDisplay
{
    public function handle(Request $request, Closure $next): Response
    {
        $nilai = (string) $request->bearerToken();
        // Format Sanctum "<id>|<rahasia>"; id bukan UUID ditolak sebelum menyentuh kolom uuid.
        $token = Str::isUuid(Str::before($nilai, '|')) ? TokenAksesPribadi::findToken($nilai) : null;
        $perangkat = $token?->tokenable;
        if (! $perangkat instanceof DisplayAntrean || $perangkat->status !== 'aktif' || $token->expires_at?->isPast()) {
            throw new AuthenticationException;
        }
        $area = (string) $request->route('area');
        if (! $token->can(DisplayAntrean::KEMAMPUAN) || ($perangkat->area !== [] && ! in_array($area, $perangkat->area, true))) {
            throw new ApiError(403, 'akses_ditolak', 'Perangkat ini tidak boleh menampilkan area tersebut.');
        }
        $request->attributes->set('perangkat_display', $perangkat);

        return $next($request);
    }
}
