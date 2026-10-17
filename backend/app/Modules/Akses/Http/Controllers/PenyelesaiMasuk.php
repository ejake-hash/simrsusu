<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Core\Http\ApiError;
use App\Modules\Akses\Application\KebijakanMfa;
use App\Modules\Akses\Application\KelolaPerangkat;
use App\Modules\Akses\Application\MasukPengguna;
use App\Modules\Akses\Application\MfaUlang;
use App\Modules\Akses\Application\PendaftaranPerangkat;
use App\Modules\Akses\Http\Middleware\SesiPengguna;
use App\Modules\Akses\Http\Resources\PenggunaResource;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PerangkatTepercaya;
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
        private readonly KelolaPerangkat $kelolaPerangkat,
    ) {}

    /**
     * $denganMfa: langkah ini memverifikasi MFA, jadi izin butuh_mfa langsung boleh (K11, MfaUlang). Masuk lewat
     * perangkat tepercaya bukan verifikasi MFA (tiket 1.13): MFA ulang tetap diminta.
     *
     * @return array{status: 'masuk', pengguna: array<string, mixed>, perangkat_tepercaya: array{id: string, berlaku_sampai: string}|null}
     */
    public function selesaikan(Request $request, Pengguna $pengguna, bool $denganMfa = false): array
    {
        /** @var PerangkatTepercaya|null $perangkat dipercaya di langkah MFA ini, atau dipakai melewati MFA di langkah 1 */
        $perangkat = $request->attributes->get(KelolaPerangkat::ATRIBUT);
        $mfaDilewati = $perangkat !== null && ! $denganMfa;
        $this->masuk->selesaikan($pengguna, $this->kebijakanMfa->dariJaringanRs($request->ip()),
            $mfaDilewati ? ['mfa_dilewati' => 'perangkat_tepercaya', 'perangkat_id' => $perangkat->id] : null);

        Auth::guard('web')->login($pengguna);
        $request->session()->regenerate();
        SesiPengguna::mulai($request, $this->perangkat->terdaftar($request));
        // Masuk tanpa MFA tidak boleh mewarisi verifikasi akun sebelumnya di peramban ini (regenerate mempertahankan isi sesi).
        $denganMfa ? MfaUlang::catat($request->session(), $pengguna) : MfaUlang::lupakan($request->session());
        // Dibaca ulang dari DB: nilai pembanding sama persis dengan yang nanti dibaca /saya.
        $request->session()->put(PenggunaResource::LOGIN_SESI_INI, $pengguna->refresh()->login_terakhir_pada?->getTimestamp());
        // `perangkat_ini` di /saya/perangkat: cookie token hanya terkirim ke /api/v1/autentikasi, jadi id disimpan di sesi.
        $perangkat !== null ? $request->session()->put(KelolaPerangkat::SESI, $perangkat->id) : $request->session()->forget(KelolaPerangkat::SESI);

        return [
            'status' => 'masuk',
            'pengguna' => (new PenggunaResource($pengguna))->resolve($request),
            'perangkat_tepercaya' => $perangkat === null ? null
                : ['id' => $perangkat->id, 'berlaku_sampai' => $this->kelolaPerangkat->berlakuSampai($perangkat, $pengguna)->toIso8601String()],
        ];
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
