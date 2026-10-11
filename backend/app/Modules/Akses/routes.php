<?php

use App\Modules\Akses\Http\Controllers\AutentikasiController;
use App\Modules\Akses\Http\Controllers\MenuSayaController;
use App\Modules\Akses\Http\Controllers\MfaController;
use App\Modules\Akses\Http\Controllers\PeranController;
use App\Modules\Akses\Http\Controllers\SayaController;
use App\Modules\Akses\Http\Middleware\SesiPengguna;
use Illuminate\Support\Facades\Route;

// GET /sanctum/csrf-cookie didaftarkan oleh Sanctum. Rute bersesi memakai guard web (cookie),
// bukan token pribadi Sanctum: token hanya untuk integrasi mesin-ke-mesin.
Route::prefix('api/v1')->middleware('api')->group(function () {
    Route::post('autentikasi/masuk', [AutentikasiController::class, 'masuk'])
        ->middleware('throttle:autentikasi-masuk');

    // Langkah MFA (PRD 10.3.2): sesi belum masuk, jadi di luar auth:web; tantangan di sesi.
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
        Route::get('peran', [PeranController::class, 'index'])->middleware('can:data_induk.peran.lihat');
    });
});
