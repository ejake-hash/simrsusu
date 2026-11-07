<?php

use App\Modules\RekamMedis\Http\Controllers\AlergiController;
use App\Modules\RekamMedis\Http\Controllers\AsesmenAwalController;
use App\Modules\RekamMedis\Http\Controllers\CpptController;
use Illuminate\Support\Facades\Route;

// Asesmen awal perawat RJ dan alergi (tiket 2.13, kontrak v0.23.0), CPPT (tiket 2.14, v0.24.0): layar kerja klinis, rate limit api-layar-kerja.
Route::prefix('api/v1')->middleware(['api', 'auth:web', 'sesi.pengguna', 'throttle:api-layar-kerja'])->withoutMiddleware('throttle:api')
    ->whereUuid(['kunjungan', 'asesmen', 'pasien', 'alergi', 'episode', 'cppt'])->group(function () {
        Route::get('kunjungan/{kunjungan}/asesmen-awal', [AsesmenAwalController::class, 'show']);
        Route::post('kunjungan/{kunjungan}/asesmen-awal', [AsesmenAwalController::class, 'store']);
        Route::patch('asesmen-awal/{asesmen}', [AsesmenAwalController::class, 'update']);
        Route::post('asesmen-awal/{asesmen}/selesai', [AsesmenAwalController::class, 'selesai']);
        Route::get('pasien/{pasien}/alergi', [AlergiController::class, 'index']);
        Route::post('pasien/{pasien}/alergi', [AlergiController::class, 'store']);
        Route::post('alergi/{alergi}/batal', [AlergiController::class, 'batal']);
        Route::get('episode/{episode}/cppt', [CpptController::class, 'episode']);
        Route::get('pasien/{pasien}/cppt', [CpptController::class, 'pasien']);
        Route::post('kunjungan/{kunjungan}/cppt', [CpptController::class, 'store']);
        Route::get('cppt/{cppt}', [CpptController::class, 'show']);
        Route::patch('cppt/{cppt}', [CpptController::class, 'update']);
        Route::post('cppt/{cppt}/sahkan', [CpptController::class, 'sahkan']);
        Route::post('cppt/{cppt}/adendum', [CpptController::class, 'adendum']);
    });
