<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Core\Http\ApiError;
use App\Modules\Akses\Application\KebijakanMfa;
use App\Modules\Akses\Application\MasukPengguna;
use App\Modules\Akses\Application\PendaftaranPerangkat;
use App\Modules\Akses\Http\Middleware\SesiPengguna;
use App\Modules\Akses\Http\Resources\PenggunaResource;
use App\Modules\Akses\Models\Pengguna;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Langkah terakhir masuk, dipakai langkah sandi dan langkah MFA (PRD 10.3.2). */
final class PenyelesaiMasuk
{
    public function __construct(
        private readonly MasukPengguna $masuk,
        private readonly PendaftaranPerangkat $perangkat,
        private readonly KebijakanMfa $kebijakanMfa,
    ) {}

    /** @return array{status: 'masuk', pengguna: array<string, mixed>} */
    public function selesaikan(Request $request, Pengguna $pengguna): array
    {
        $this->masuk->selesaikan($pengguna, $this->kebijakanMfa->dariJaringanRs($request->ip()));

        Auth::guard('web')->login($pengguna);
        $request->session()->regenerate();
        SesiPengguna::mulai($request, $this->perangkat->terdaftar($request));
        $request->session()->put(PenggunaResource::LOGIN_SESI_INI, $pengguna->login_terakhir_pada?->toIso8601String());

        return ['status' => 'masuk', 'pengguna' => (new PenggunaResource($pengguna))->resolve($request)];
    }

    /**
     * Sesi hanya dimulai Sanctum untuk permintaan dari domain SPA (SANCTUM_STATEFUL_DOMAINS).
     * Klien lain tidak melewati CSRF, jadi ditolak sebelum sandi atau kode diperiksa.
     */
    public static function pastikanDariSpa(Request $request): void
    {
        if (! $request->hasSession()) {
            throw new ApiError(403, 'asal_tidak_diizinkan', 'Masuk hanya bisa dari aplikasi SIMRS.');
        }
    }

    public static function errorTerkunci(?CarbonInterface $sampai): ApiError
    {
        // Sisa waktu kunci lewat Retry-After (detik), sama seperti respons 429 rate limit.
        return new ApiError(403, 'akun_terkunci', 'Akun terkunci sementara karena terlalu banyak percobaan gagal. Coba lagi nanti atau hubungi admin.', [], [
            'Retry-After' => max(1, ($sampai?->getTimestamp() ?? now()->getTimestamp()) - now()->getTimestamp()),
        ]);
    }
}
