<?php

use App\Modules\Ttd\Http\Controllers\TtdController;
use Illuminate\Support\Facades\Route;

// Tanda tangan elektronik T1 (tiket 1.6, kontrak v0.8.0). /saya/ttd/* dan tandatangani tanpa izin khusus (akun
// sendiri); reset butuh sdm.kredensial.perbaiki (Gate → AccessEvaluator di controller); verifikasi mengikuti objeknya.
Route::prefix('api/v1')->middleware(['api', 'auth:web', 'sesi.pengguna'])->whereUuid(['pengguna', 'objekId'])->group(function () {
    Route::post('saya/ttd/aktivasi', [TtdController::class, 'aktivasi']);
    Route::put('saya/ttd/pin', [TtdController::class, 'gantiPin']);
    Route::post('ttd/{pengguna}/reset', [TtdController::class, 'reset']);
    Route::post('ttd/tandatangani', [TtdController::class, 'tandatangani']);
    Route::get('ttd/verifikasi/{objekJenis}/{objekId}', [TtdController::class, 'verifikasi'])->whereIn('objekJenis', ['persetujuan_akses']);
});
