<?php

use App\Modules\RekamMedis\Http\Controllers\AlergiController;
use App\Modules\RekamMedis\Http\Controllers\AsesmenAwalController;
use Illuminate\Support\Facades\Route;

// Asesmen awal perawat RJ dan alergi (tiket 2.13, kontrak v0.23.0): layar kerja klinis, rate limit api-layar-kerja.
Route::prefix('api/v1')->middleware(['api', 'auth:web', 'sesi.pengguna', 'throttle:api-layar-kerja'])->withoutMiddleware('throttle:api')
    ->whereUuid(['kunjungan', 'asesmen', 'pasien', 'alergi'])->group(function () {
        Route::get('kunjungan/{kunjungan}/asesmen-awal', [AsesmenAwalController::class, 'show']);
        Route::post('kunjungan/{kunjungan}/asesmen-awal', [AsesmenAwalController::class, 'store']);
        Route::patch('asesmen-awal/{asesmen}', [AsesmenAwalController::class, 'update']);
        Route::post('asesmen-awal/{asesmen}/selesai', [AsesmenAwalController::class, 'selesai']);
        Route::get('pasien/{pasien}/alergi', [AlergiController::class, 'index']);
        Route::post('pasien/{pasien}/alergi', [AlergiController::class, 'store']);
        Route::post('alergi/{alergi}/batal', [AlergiController::class, 'batal']);
    });
