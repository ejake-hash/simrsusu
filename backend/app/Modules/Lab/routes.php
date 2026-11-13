<?php

use App\Modules\Lab\Http\Controllers\DaftarKerjaLabController;
use App\Modules\Lab\Http\Controllers\HasilLabController;
use App\Modules\Lab\Http\Controllers\SpesimenController;
use Illuminate\Support\Facades\Route;

// Daftar kerja lab dan spesimen (tiket 2.30), hasil lab (tiket 2.31). Izin penunjang.laboratorium.* cakupan RS, diperiksa di
// controller; linimasa episode memakai izin baca order (pola 2.16).
Route::prefix('api/v1')->middleware(['api', 'auth:web', 'sesi.pengguna'])->whereUuid(['item', 'spesimen', 'hasil', 'episode'])->group(function () {
    // Layar kerja lab yang dipolling: batas laju layar kerja.
    Route::get('lab/daftar-kerja', DaftarKerjaLabController::class)
        ->withoutMiddleware('throttle:api')->middleware('throttle:api-layar-kerja');
    Route::post('permintaan-item/{item}/spesimen', [SpesimenController::class, 'ambil']);
    Route::post('spesimen/{spesimen}/terima', [SpesimenController::class, 'terima']);
    Route::post('permintaan-item/{item}/hasil', [HasilLabController::class, 'input']);
    Route::post('hasil-lab/{hasil}/validasi', [HasilLabController::class, 'validasi']);
    Route::post('hasil-lab/{hasil}/adendum', [HasilLabController::class, 'adendum']);
    Route::get('episode/{episode}/hasil-lab', [HasilLabController::class, 'episode']);
});
