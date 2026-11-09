<?php

namespace App\Modules\Antrean\Http\Middleware;

use App\Core\Http\ApiError;
use App\Modules\Akses\Models\TokenAksesPribadi;
use App\Modules\Antrean\Models\Anjungan;
use App\Modules\Antrean\Models\DisplayAntrean;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentikasi token perangkat display (tiket 2.8) dan anjungan (tiket 2.20) (PRD 7.01.1, 10.3.1), bukan sesi cookie.
 * Pemakaian: `TokenPerangkat::class.':display'` atau `':anjungan'`. Tanpa token, token tidak dikenal, dicabut, kedaluwarsa,
 * atau perangkatnya nonaktif → 401; token tanpa kemampuan rute ini (mis. token anjungan di display) atau area display di
 * luar daftar perangkat → 403. Perangkat yang lolos disimpan di atribut permintaan `perangkat`.
 */
final class TokenPerangkat
{
    public function handle(Request $request, Closure $next, string $kemampuan): Response
    {
        $nilai = (string) $request->bearerToken();
        // Format Sanctum "<id>|<rahasia>"; id bukan UUID ditolak sebelum menyentuh kolom uuid.
        $token = Str::isUuid(Str::before($nilai, '|')) ? TokenAksesPribadi::findToken($nilai) : null;
        $perangkat = $token?->tokenable;
        if (! ($perangkat instanceof DisplayAntrean || $perangkat instanceof Anjungan) || $perangkat->status !== 'aktif' || $token->expires_at?->isPast()) {
            throw new AuthenticationException;
        }
        if (! $token->can($kemampuan) || $perangkat::KEMAMPUAN !== $kemampuan) {
            throw new ApiError(403, 'akses_ditolak', 'Perangkat ini tidak boleh membuka layanan tersebut.');
        }
        if ($perangkat instanceof DisplayAntrean && $perangkat->area !== [] && ! in_array((string) $request->route('area'), $perangkat->area, true)) {
            throw new ApiError(403, 'akses_ditolak', 'Perangkat ini tidak boleh menampilkan area tersebut.');
        }
        $request->attributes->set('perangkat', $perangkat);

        return $next($request);
    }
}
