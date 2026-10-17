<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Application\AlasanGagalMasuk;
use App\Modules\Akses\Application\GagalMasuk;
use App\Modules\Akses\Application\KebijakanMfa;
use App\Modules\Akses\Application\KelolaPerangkat;
use App\Modules\Akses\Application\MasukPengguna;
use App\Modules\Akses\Application\MfaChallenge;
use App\Modules\Akses\Application\VerifikasiMfa;
use App\Modules\Akses\Http\Requests\MasukRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

final class AutentikasiController
{
    /**
     * Langkah 1 kontrak PRD 10.3.2: {status: masuk | perlu_mfa | perlu_daftar_mfa, …}.
     */
    public function masuk(
        MasukRequest $request,
        MasukPengguna $masuk,
        KebijakanMfa $kebijakan,
        MfaChallenge $challenge,
        VerifikasiMfa $mfa,
        PenyelesaiMasuk $penyelesai,
        CatatAktivitas $audit,
        KelolaPerangkat $perangkat,
    ): JsonResponse {
        PenyelesaiMasuk::pastikanDariSpa($request);
        $challenge->hapus($request->session());

        // Sebelum sandi diperiksa: tanpa daftar jaringan RS tidak ada yang bisa mendaftarkan MFA,
        // jadi instalasi belum boleh dipakai (FR-54-08). Superadmin pertama lewat konsol.
        if (! $kebijakan->jaringanRsDiisi()) {
            throw new ApiError(503, 'konfigurasi_belum_lengkap', 'SIMRS belum selesai disiapkan: daftar jaringan RS belum diisi. Hubungi admin.');
        }

        try {
            $pengguna = $masuk->jalankan($request->validated('nama_pengguna'), $request->validated('sandi'));
        } catch (GagalMasuk $e) {
            throw match ($e->alasan) {
                AlasanGagalMasuk::KredensialSalah => new ApiError(401, $e->alasan->value, 'Nama pengguna atau sandi salah.'),
                AlasanGagalMasuk::AkunTerkunci => PenyelesaiMasuk::errorTerkunci($e->terkunciSampai),
                AlasanGagalMasuk::AkunNonaktif => new ApiError(403, $e->alasan->value, 'Akun tidak aktif. Hubungi admin.'),
            };
        }

        if (! $kebijakan->wajib($pengguna, $request->ip())) {
            return response()->json($penyelesai->selesaikan($request, $pengguna));
        }

        // Sesi belum masuk sampai langkah MFA lolos; penghitung gagal juga belum direset.
        if ($pengguna->mfaAktif()) {
            // Perangkat tepercaya dari jaringan RS: MFA dilewati (tiket 1.13). Dari luar jaringan RS cocokkan() selalu null.
            $tepercaya = $perangkat->cocokkan($request, $pengguna);
            if ($tepercaya !== null) {
                $perangkat->pakai($request, $tepercaya);

                return response()->json($penyelesai->selesaikan($request, $pengguna));
            }

            $t = $challenge->buat($request->session(), $pengguna, 'mfa');

            return response()->json([
                'status' => 'perlu_mfa',
                'challenge_id' => $t['id'],
                'metode' => $mfa->punyaKodeCadangan($pengguna) ? ['totp', 'kode_cadangan'] : ['totp'],
                'kedaluwarsa_pada' => $t['kedaluwarsa_pada']->toIso8601String(),
                'percayai_perangkat' => $perangkat->tawaran($request, $pengguna),
            ]);
        }

        // Pendaftaran MFA hanya dari jaringan RS (PRD 10.3.2). Penghitung gagal tidak berubah:
        // sandinya benar, tetapi sesi juga tidak dimulai.
        if (! $kebijakan->dariJaringanRs($request->ip())) {
            $audit->catat('autentikasi.gagal', $pengguna->id, 'akses.pengguna', $pengguna->id, ['alasan' => 'mfa_belum_terdaftar']);

            throw new ApiError(403, 'mfa_belum_terdaftar', 'Verifikasi dua langkah belum aktif. Aktifkan dari jaringan RS atau minta admin mereset.');
        }

        $t = $challenge->buat($request->session(), $pengguna, 'daftar');

        return response()->json([
            'status' => 'perlu_daftar_mfa',
            'challenge_id' => $t['id'],
            'kedaluwarsa_pada' => $t['kedaluwarsa_pada']->toIso8601String(),
            'percayai_perangkat' => $perangkat->tawaran($request, $pengguna),
        ]);
    }

    public function keluar(Request $request, CatatAktivitas $audit): Response
    {
        $id = $request->user()->getAuthIdentifier();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $audit->catat('autentikasi.keluar', $id, 'akses.pengguna', $id);

        return response()->noContent();
    }
}
