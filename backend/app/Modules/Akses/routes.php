<?php

use App\Modules\Akses\Http\Controllers\AutentikasiController;
use App\Modules\Akses\Http\Controllers\SayaController;
use App\Modules\Akses\Http\Middleware\SesiPengguna;
use Illuminate\Support\Facades\Route;

// GET /sanctum/csrf-cookie didaftarkan oleh Sanctum. Rute bersesi memakai guard web (cookie),
// bukan token pribadi Sanctum: token hanya untuk integrasi mesin-ke-mesin.
Route::prefix('api/v1')->middleware('api')->group(function () {
    Route::post('autentikasi/masuk', [AutentikasiController::class, 'masuk'])
        ->middleware('throttle:autentikasi-masuk');

    Route::middleware(['auth:web', SesiPengguna::class])->group(function () {
        Route::post('autentikasi/keluar', [AutentikasiController::class, 'keluar']);
        Route::get('saya', SayaController::class);
    });
});
