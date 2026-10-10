<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\GalatApi;
use App\Modules\Akses\Application\AlasanGagalMasuk;
use App\Modules\Akses\Application\GagalMasuk;
use App\Modules\Akses\Application\MasukPengguna;
use App\Modules\Akses\Application\PendaftaranPerangkat;
use App\Modules\Akses\Http\Middleware\SesiPengguna;
use App\Modules\Akses\Http\Requests\MasukRequest;
use App\Modules\Akses\Http\Resources\PenggunaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

final class AutentikasiController
{
    public function masuk(MasukRequest $request, MasukPengguna $masuk, PendaftaranPerangkat $perangkat): JsonResponse
    {
        // Sesi hanya dimulai Sanctum untuk permintaan dari domain SPA (SANCTUM_STATEFUL_DOMAINS).
        // Klien lain tidak melewati CSRF, jadi ditolak sebelum sandi diperiksa.
        if (! $request->hasSession()) {
            throw new GalatApi(403, 'asal_tidak_diizinkan', 'Masuk hanya bisa dari aplikasi SIMRS.');
        }

        try {
            $pengguna = $masuk->jalankan($request->validated('nama_pengguna'), $request->validated('sandi'));
        } catch (GagalMasuk $e) {
            throw match ($e->alasan) {
                AlasanGagalMasuk::KredensialSalah => new GalatApi(401, $e->alasan->value, 'Nama pengguna atau sandi salah.'),
                AlasanGagalMasuk::AkunTerkunci => new GalatApi(403, $e->alasan->value, 'Akun terkunci sementara karena terlalu banyak percobaan gagal. Coba lagi nanti atau hubungi admin.'),
                AlasanGagalMasuk::AkunNonaktif => new GalatApi(403, $e->alasan->value, 'Akun tidak aktif. Hubungi admin.'),
            };
        }

        Auth::guard('web')->login($pengguna);
        $request->session()->regenerate();
        SesiPengguna::mulai($request, $perangkat->terdaftar($request));

        return (new PenggunaResource($pengguna))->response();
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
