<?php

use App\Core\Http\SesiSpaStateful;
use App\Modules\Antrean\Http\Controllers\AnjunganController;
use App\Modules\Antrean\Http\Controllers\AntreanController;
use App\Modules\Antrean\Http\Controllers\DisplayController;
use App\Modules\Antrean\Http\Controllers\TaskAntreanController;
use App\Modules\Antrean\Http\Controllers\WorklistController;
use App\Modules\Antrean\Http\Middleware\TokenPerangkat;
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

// Task ID antrean satu kunjungan (tiket 2.18): izin integrasi.bpjs.lihat diperiksa di controller.
Route::prefix('api/v1')->middleware(['api', 'auth:web', 'sesi.pengguna'])->group(function () {
    Route::get('kunjungan/{kunjungan}/task-antrean', [TaskAntreanController::class, 'index'])->whereUuid('kunjungan');
});

// Display: token perangkat, bukan sesi cookie; batas laju per perangkat.
Route::prefix('api/v1')->middleware('api')->group(function () {
    Route::get('display/{area}', DisplayController::class)->where('area', '[a-z0-9][a-z0-9_-]{0,39}')
        ->withoutMiddleware('throttle:api')->middleware(['throttle:display', TokenPerangkat::class.':display']);
});

// Anjungan (tiket 2.20): token perangkat kemampuan `anjungan`; batas laju per perangkat dan per IP.
// Tanpa sesi SPA/CSRF: rute ini hanya menerima bearer token (tidak dikirim otomatis oleh peramban), dan kios tidak menyimpan sesi.
Route::prefix('api/v1/anjungan')->withoutMiddleware(['throttle:api', SesiSpaStateful::class])
    ->middleware(['api', 'throttle:anjungan', TokenPerangkat::class.':anjungan'])->group(function () {
        Route::get('/', [AnjunganController::class, 'show']);
        Route::post('nomor-loket', [AnjunganController::class, 'nomorLoket']);
        Route::get('jadwal-praktik', [AnjunganController::class, 'jadwalPraktik']);
    });
