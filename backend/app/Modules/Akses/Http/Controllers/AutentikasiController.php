<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\GalatApi;
use App\Modules\Akses\Application\AlasanGagalMasuk;
use App\Modules\Akses\Application\GagalMasuk;
use App\Modules\Akses\Application\KebijakanMfa;
use App\Modules\Akses\Application\MasukPengguna;
use App\Modules\Akses\Application\TantanganMfa;
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
        TantanganMfa $tantangan,
        VerifikasiMfa $mfa,
        PenyelesaiMasuk $penyelesai,
    ): JsonResponse {
        PenyelesaiMasuk::pastikanDariSpa($request);
        $tantangan->hapus($request->session());

        try {
            $pengguna = $masuk->jalankan($request->validated('nama_pengguna'), $request->validated('sandi'));
        } catch (GagalMasuk $e) {
            throw match ($e->alasan) {
                AlasanGagalMasuk::KredensialSalah => new GalatApi(401, $e->alasan->value, 'Nama pengguna atau sandi salah.'),
                AlasanGagalMasuk::AkunTerkunci => PenyelesaiMasuk::galatTerkunci($e->terkunciSampai),
                AlasanGagalMasuk::AkunNonaktif => new GalatApi(403, $e->alasan->value, 'Akun tidak aktif. Hubungi admin.'),
            };
        }

        if (! $kebijakan->wajib($pengguna, $request->ip())) {
            return response()->json($penyelesai->selesaikan($request, $pengguna));
        }

        // Sesi belum masuk sampai langkah MFA lolos; penghitung gagal juga belum direset.
        if ($pengguna->mfaAktif()) {
            $t = $tantangan->buat($request->session(), $pengguna, 'mfa');

            return response()->json([
                'status' => 'perlu_mfa',
                'tantangan_id' => $t['id'],
                'metode' => $mfa->punyaKodeCadangan($pengguna) ? ['totp', 'kode_cadangan'] : ['totp'],
                'kedaluwarsa_pada' => $t['kedaluwarsa_pada']->toIso8601String(),
            ]);
        }

        $t = $tantangan->buat($request->session(), $pengguna, 'daftar');

        return response()->json([
            'status' => 'perlu_daftar_mfa',
            'tantangan_id' => $t['id'],
            'kedaluwarsa_pada' => $t['kedaluwarsa_pada']->toIso8601String(),
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
