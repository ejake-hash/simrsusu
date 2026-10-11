<?php

namespace App\Modules\Akses\Http\Middleware;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Pengguna;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dipasang sesudah auth:web pada semua rute yang butuh sesi (PRD 14.2, ADR 0002):
 * - sesi dicabut bila akun sudah dinonaktifkan (pengguna dibaca ulang dari DB tiap permintaan), atau
 *   bila sesi dimulai sebelum akses.pengguna.sesi_dicabut_pada (reset MFA, wajib MFA baru; tiket 1.2c);
 * - sesi berakhir bila diam lebih lama dari batasnya (15 menit, atau 60 di perangkat terdaftar).
 */
final class SesiPengguna
{
    private const TERAKHIR_AKTIF = 'autentikasi.terakhir_aktif';

    private const BATAS_DIAM = 'autentikasi.batas_diam_menit';

    /** Waktu sesi dimulai, mikrodetik sejak epoch (dibandingkan dengan sesi_dicabut_pada). */
    private const MASUK_PADA = 'autentikasi.masuk_pada';

    public function __construct(private readonly CatatAktivitas $audit) {}

    public static function mulai(Request $request, bool $perangkatTerdaftar): void
    {
        $request->session()->put([
            self::BATAS_DIAM => (int) config($perangkatTerdaftar
                ? 'autentikasi.sesi.batas_diam_perangkat_terdaftar_menit'
                : 'autentikasi.sesi.batas_diam_menit'),
            self::TERAKHIR_AKTIF => now()->getTimestamp(),
            self::MASUK_PADA => (int) now()->getPreciseTimestamp(6),
        ]);
    }

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Pengguna $pengguna */
        $pengguna = $request->user();

        match (self::alasanBerakhir($request, $pengguna)) {
            'nonaktif' => $this->akhiri($request, $pengguna, 'sesi_dicabut', 'Akun Anda sudah tidak aktif. Hubungi admin.'),
            'dicabut' => $this->akhiri($request, $pengguna, 'sesi_dicabut', 'Sesi Anda diakhiri oleh admin. Silakan masuk lagi.'),
            'diam' => $this->akhiri($request, $pengguna, 'sesi_kedaluwarsa', 'Sesi berakhir karena tidak ada aktivitas. Silakan masuk lagi.'),
            null => null,
        };

        $request->session()->put(self::TERAKHIR_AKTIF, now()->getTimestamp());

        return $next($request);
    }

    /**
     * Alasan sesi ini harus berakhir (akun nonaktif, dicabut admin, melewati batas diam), atau null bila masih sah.
     *
     * @return 'nonaktif'|'dicabut'|'diam'|null
     */
    public static function alasanBerakhir(Request $request, Pengguna $pengguna): ?string
    {
        $sesi = $request->session();
        $masukPada = $sesi->get(self::MASUK_PADA);
        $terakhir = $sesi->get(self::TERAKHIR_AKTIF);
        $batasDetik = (int) $sesi->get(self::BATAS_DIAM, config('autentikasi.sesi.batas_diam_menit')) * 60;

        return match (true) {
            ! $pengguna->aktif() => 'nonaktif',
            $pengguna->sesi_dicabut_pada !== null
                && (! is_int($masukPada) || $masukPada < (int) $pengguna->sesi_dicabut_pada->getPreciseTimestamp(6)) => 'dicabut',
            ! is_int($terakhir) || now()->getTimestamp() - $terakhir > $batasDetik => 'diam',
            default => null,
        };
    }

    /** @return never */
    private function akhiri(Request $request, Pengguna $pengguna, string $kode, string $pesan): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $this->audit->catat('autentikasi.sesi_berakhir', $pengguna->id, 'akses.pengguna', $pengguna->id, ['alasan' => $kode]);

        throw new ApiError(401, $kode, $pesan);
    }
}
