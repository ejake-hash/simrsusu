<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Core\Http\ApiError;
use App\Modules\Akses\Application\AlasanGagalMfa;
use App\Modules\Akses\Application\GagalMfa;
use App\Modules\Akses\Application\MfaUlang;
use App\Modules\Akses\Application\VerifikasiMfa;
use App\Modules\Akses\Models\Pengguna;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /saya/mfa/verifikasi-ulang (K11, PRD 10.3.2 langkah 3; tiket 1.5). Sesi tidak diganti. Kode salah = 422, bukan
 * 401: pengguna tetap masuk, dan klien memperlakukan 401 sebagai sesi berakhir. Penghitung gagal sama dengan masuk.
 */
final class MfaUlangController
{
    public function __construct(private readonly VerifikasiMfa $mfa) {}

    public function __invoke(Request $request): JsonResponse
    {
        $isi = $request->validate([
            'metode' => ['required', Rule::in(['totp', 'kode_cadangan'])],
            'kode' => ['required', 'string', 'max:20'],
        ]);
        /** @var Pengguna $pengguna */
        $pengguna = $request->user();
        if (! $pengguna->mfaAktif()) {
            throw new ApiError(403, 'mfa_belum_terdaftar', 'Akun Anda belum punya MFA. Daftarkan MFA dari jaringan RS atau hubungi admin.');
        }

        try {
            $this->mfa->verifikasi($pengguna, $isi['metode'], $isi['kode'], 'autentikasi.mfa_ulang_berhasil');
        } catch (GagalMfa $e) {
            throw $e->alasan === AlasanGagalMfa::AkunTerkunci
                ? PenyelesaiMasuk::errorTerkunci($e->terkunciSampai)
                : new ApiError(422, 'kode_mfa_salah', 'Kode verifikasi salah.', [['kolom' => 'kode', 'pesan' => 'Periksa kode di aplikasi autentikator.']]);
        }
        $pada = MfaUlang::catat($request->session());

        return new JsonResponse(['data' => [
            'mfa_terverifikasi_pada' => $pada->toIso8601String(),
            'berlaku_sampai' => MfaUlang::berlakuSampai($pada)->toIso8601String(),
        ]]);
    }
}
