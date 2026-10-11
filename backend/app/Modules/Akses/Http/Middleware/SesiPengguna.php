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

    /** Sesi ini dianggap dimulai sekarang (sesudah ganti sandi memutus sesi lain), batas diamnya tetap. */
    public static function mulaiUlang(Request $request): void
    {
        $request->session()->put(self::MASUK_PADA, (int) now()->getPreciseTimestamp(6) + 1);
    }

    /**
     * Rute yang tetap boleh selama akun wajib ganti sandi (tiket 1.8d): metode => pola path.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const BOLEH_SAAT_WAJIB_GANTI_SANDI = [
        ['GET', 'api/v1/saya'], ['GET', 'api/v1/saya/menu'], ['POST', 'api/v1/saya/sandi'], ['POST', 'api/v1/autentikasi/keluar'],
        ['GET', 'api/v1/profil-rs'], ['GET', 'api/v1/profil-rs/berkas/*'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Pengguna $pengguna */
        $pengguna = $request->user();

        match (self::alasanBerakhir($request, $pengguna)) {
            'nonaktif' => $this->akhiri($request, $pengguna, 'sesi_dicabut', 'Akun Anda sudah tidak aktif. Hubungi admin.'),
            'dicabut' => $this->akhiri($request, $pengguna, 'sesi_dicabut', 'Sesi Anda sudah diakhiri (mis. sandi diganti atau akses akun diubah). Silakan masuk lagi.'),
            'diam' => $this->akhiri($request, $pengguna, 'sesi_kedaluwarsa', 'Sesi berakhir karena tidak ada aktivitas. Silakan masuk lagi.'),
            null => null,
        };

        // Polling otomatis (X-Permintaan-Latar: 1, tiket 1.7) tidak dihitung aktivitas: sesi di komputer bersama tetap
        // berakhir sesudah batas diam (PRD 14.2, ADR 0002).
        if ($request->header('X-Permintaan-Latar') !== '1') {
            $request->session()->put(self::TERAKHIR_AKTIF, now()->getTimestamp());
        }

        if ($pengguna->getAttribute('wajib_ganti_sandi') === true && ! self::bolehSaatWajibGanti($request)) {
            throw new ApiError(403, 'wajib_ganti_sandi', 'Ganti sandi awal Anda lebih dulu.');
        }

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

    private static function bolehSaatWajibGanti(Request $request): bool
    {
        foreach (self::BOLEH_SAAT_WAJIB_GANTI_SANDI as [$metode, $pola]) {
            if ($request->isMethod($metode) && $request->is($pola)) {
                return true;
            }
        }

        return false;
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
