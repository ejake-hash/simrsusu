<?php

use App\Modules\Pendaftaran\Http\Controllers\GabungPasienController;
use App\Modules\Pendaftaran\Http\Controllers\KunjunganController;
use App\Modules\Pendaftaran\Http\Controllers\PasienController;
use Illuminate\Support\Facades\Route;

// Pasien (tiket 2.5) dan kunjungan (tiket 2.6). Izin pendaftaran.pasien.* cakupan rs diperiksa di controller (Gate → AccessEvaluator).
Route::prefix('api/v1')->middleware(['api', 'auth:web', 'sesi.pengguna'])->whereUuid(['pasien', 'kunjungan', 'usulan'])->group(function () {
    // Cari pasien dan papan kunjungan dipakai berulang di loket: batas laju layar kerja.
    Route::get('pasien', [PasienController::class, 'index'])
        ->withoutMiddleware('throttle:api')->middleware('throttle:api-layar-kerja');
    Route::post('pasien', [PasienController::class, 'store']);
    Route::post('pasien/cek-duplikat', [PasienController::class, 'cekDuplikat'])
        ->withoutMiddleware('throttle:api')->middleware('throttle:api-layar-kerja');
    Route::patch('pasien/{pasien}', [PasienController::class, 'update']);

    // Gabung RM ganda (tiket 2.9): usul → verifikasi Rekam Medis.
    Route::get('gabung-pasien', [GabungPasienController::class, 'index']);
    Route::post('gabung-pasien', [GabungPasienController::class, 'store']);
    Route::post('gabung-pasien/{usulan}/verifikasi', [GabungPasienController::class, 'verifikasi']);

    Route::get('kunjungan', [KunjunganController::class, 'index'])
        ->withoutMiddleware('throttle:api')->middleware('throttle:api-layar-kerja');
    Route::post('kunjungan', [KunjunganController::class, 'store']);
    Route::post('kunjungan/{kunjungan}/batal', [KunjunganController::class, 'batal']);
});
