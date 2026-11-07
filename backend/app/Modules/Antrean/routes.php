<?php

use App\Modules\Antrean\Http\Controllers\AntreanController;
use App\Modules\Antrean\Http\Controllers\DisplayController;
use App\Modules\Antrean\Http\Controllers\WorklistController;
use App\Modules\Antrean\Http\Middleware\TokenPerangkatDisplay;
use Illuminate\Support\Facades\Route;

// Antrean poli (tiket 2.8). Izin pendaftaran.antrean.* cakupan rs diperiksa di controller (Gate → AccessEvaluator).
Route::prefix('api/v1')->middleware(['api', 'auth:web', 'sesi.pengguna'])->whereUuid('antrean')->group(function () {
    // Worklist poli (tiket 2.13): layar kerja perawat/dokter.
    Route::get('rawat-jalan/worklist', WorklistController::class)->withoutMiddleware('throttle:api')->middleware('throttle:api-layar-kerja');
    Route::get('antrean', [AntreanController::class, 'index'])
        ->withoutMiddleware('throttle:api')->middleware('throttle:api-layar-kerja');
    Route::post('antrean/{antrean}/panggil', [AntreanController::class, 'panggil']);
    Route::post('antrean/{antrean}/lewati', [AntreanController::class, 'lewati']);
    Route::post('antrean/{antrean}/sisip', [AntreanController::class, 'sisip']);
    Route::post('antrean/{antrean}/batal', [AntreanController::class, 'batal']);
});

// Display: token perangkat, bukan sesi cookie; batas laju per perangkat.
Route::prefix('api/v1')->middleware('api')->group(function () {
    Route::get('display/{area}', DisplayController::class)->where('area', '[a-z0-9][a-z0-9_-]{0,39}')
        ->withoutMiddleware('throttle:api')->middleware(['throttle:display', TokenPerangkatDisplay::class]);
});
