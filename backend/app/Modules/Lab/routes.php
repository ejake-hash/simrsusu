<?php

use App\Modules\Lab\Http\Controllers\DaftarKerjaLabController;
use App\Modules\Lab\Http\Controllers\SpesimenController;
use Illuminate\Support\Facades\Route;

// Daftar kerja lab dan spesimen (tiket 2.30). Izin penunjang.laboratorium.* cakupan RS, diperiksa di controller.
Route::prefix('api/v1')->middleware(['api', 'auth:web', 'sesi.pengguna'])->whereUuid(['item', 'spesimen'])->group(function () {
    // Layar kerja lab yang dipolling: batas laju layar kerja.
    Route::get('lab/daftar-kerja', DaftarKerjaLabController::class)
        ->withoutMiddleware('throttle:api')->middleware('throttle:api-layar-kerja');
    Route::post('permintaan-item/{item}/spesimen', [SpesimenController::class, 'ambil']);
    Route::post('spesimen/{spesimen}/terima', [SpesimenController::class, 'terima']);
});
