<?php

use App\Modules\Pendaftaran\Http\Controllers\PasienController;
use Illuminate\Support\Facades\Route;

// Pasien (tiket 2.5). Izin pendaftaran.pasien.* cakupan rs diperiksa di controller (Gate → AccessEvaluator).
Route::prefix('api/v1')->middleware(['api', 'auth:web', 'sesi.pengguna'])->whereUuid('pasien')->group(function () {
    // Cari pasien dipakai berulang di loket: batas laju layar kerja.
    Route::get('pasien', [PasienController::class, 'index'])
        ->withoutMiddleware('throttle:api')->middleware('throttle:api-layar-kerja');
    Route::post('pasien', [PasienController::class, 'store']);
    Route::patch('pasien/{pasien}', [PasienController::class, 'update']);
});
