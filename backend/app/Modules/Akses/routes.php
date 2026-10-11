<?php

use App\Modules\Akses\Http\Controllers\AksesAkunController;
use App\Modules\Akses\Http\Controllers\AkunController;
use App\Modules\Akses\Http\Controllers\AutentikasiController;
use App\Modules\Akses\Http\Controllers\MenuController;
use App\Modules\Akses\Http\Controllers\MenuSayaController;
use App\Modules\Akses\Http\Controllers\MfaController;
use App\Modules\Akses\Http\Controllers\PeranController;
use App\Modules\Akses\Http\Controllers\SayaController;
use App\Modules\Akses\Http\Middleware\SesiPengguna;
use Illuminate\Support\Facades\Route;

// GET /sanctum/csrf-cookie didaftarkan oleh Sanctum. Rute bersesi memakai guard web (cookie),
// bukan token pribadi Sanctum: token hanya untuk integrasi mesin-ke-mesin.
// Id bukan UUID → 404 tidak_ditemukan, bukan error tipe dari PostgreSQL.
Route::prefix('api/v1')->middleware('api')->whereUuid(['peran', 'akun', 'penugasanId', 'izinKhususId'])->group(function () {
    Route::post('autentikasi/masuk', [AutentikasiController::class, 'masuk'])
        ->middleware('throttle:autentikasi-masuk');

    // Langkah MFA (PRD 10.3.2): sesi belum masuk, jadi di luar auth:web; challenge di sesi.
    Route::middleware('throttle:autentikasi-mfa')->group(function () {
        Route::post('autentikasi/mfa', [MfaController::class, 'verifikasi'])->name('autentikasi.mfa');
        Route::post('autentikasi/mfa/daftar', [MfaController::class, 'daftar'])->name('autentikasi.mfa.daftar');
        Route::post('autentikasi/mfa/daftar/konfirmasi', [MfaController::class, 'konfirmasiDaftar'])->name('autentikasi.mfa.daftar.konfirmasi');
    });

    Route::middleware(['auth:web', SesiPengguna::class])->group(function () {
        Route::post('autentikasi/keluar', [AutentikasiController::class, 'keluar']);
        Route::get('saya', SayaController::class);
        Route::get('saya/menu', MenuSayaController::class);

        // Izin diperiksa Gate → AccessEvaluator (ModuleServiceProvider), bukan peran di controller.
        // Pengaturan → Peran & izin, Akun & akses (tiket 1.2c, kontrak docs/api/openapi.yaml).
        Route::get('menu', MenuController::class);

        Route::get('peran', [PeranController::class, 'index'])->middleware('can:pengaturan.peran.lihat');
        Route::post('peran', [PeranController::class, 'store'])->middleware('can:pengaturan.peran.input');
        Route::get('peran/{peran}', [PeranController::class, 'show'])->middleware('can:pengaturan.peran.lihat');
        Route::patch('peran/{peran}', [PeranController::class, 'update'])->middleware('can:pengaturan.peran.perbaiki');
        Route::put('peran/{peran}/izin', [PeranController::class, 'gantiIzin'])->middleware('can:pengaturan.peran.perbaiki');

        Route::get('akun', [AkunController::class, 'index'])->middleware('can:pengaturan.akun.lihat');
        Route::get('akun/{akun}', [AkunController::class, 'show'])->middleware('can:pengaturan.akun.lihat');
        Route::get('akun/{akun}/menu', [AkunController::class, 'menu'])->middleware('can:pengaturan.akun.lihat');
        Route::post('akun/{akun}/penugasan', [AksesAkunController::class, 'tugaskan'])->middleware('can:pengaturan.akun.input');
        Route::post('akun/{akun}/penugasan/{penugasanId}/batal', [AksesAkunController::class, 'batalkanPenugasan'])->middleware('can:pengaturan.akun.perbaiki');
        Route::post('akun/{akun}/izin-khusus', [AksesAkunController::class, 'buatIzinKhusus'])->middleware('can:pengaturan.akun.input');
        Route::post('akun/{akun}/izin-khusus/{izinKhususId}/batal', [AksesAkunController::class, 'batalkanIzinKhusus'])->middleware('can:pengaturan.akun.perbaiki');
        Route::put('akun/{akun}/wajib-mfa', [AksesAkunController::class, 'aturWajibMfa'])->middleware('can:pengaturan.akun.perbaiki');
        Route::post('akun/{akun}/mfa/reset', [AksesAkunController::class, 'resetMfa'])->middleware('can:pengaturan.akun.perbaiki');
    });
});
