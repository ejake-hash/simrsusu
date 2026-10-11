<?php

use App\Modules\Sdm\Http\Controllers\KredensialController;
use App\Modules\Sdm\Http\Controllers\PegawaiController;
use Illuminate\Support\Facades\Route;

// Pegawai, izin praktik, RKK (tiket 1.8d). Izin sdm.* cakupan rs diperiksa di controller (Gate → AccessEvaluator).
Route::prefix('api/v1')->middleware(['api', 'auth:web', 'sesi.pengguna'])
    ->whereUuid(['pegawai', 'izinPraktikId', 'kewenanganId'])->group(function () {
        Route::get('pegawai', [PegawaiController::class, 'index']);
        Route::post('pegawai', [PegawaiController::class, 'store']);
        Route::get('pegawai/{pegawai}', [PegawaiController::class, 'show']);
        Route::patch('pegawai/{pegawai}', [PegawaiController::class, 'update']);
        Route::get('pegawai/{pegawai}/izin-praktik', [KredensialController::class, 'daftarIzin']);
        Route::post('pegawai/{pegawai}/izin-praktik', [KredensialController::class, 'catatIzin']);
        Route::post('pegawai/{pegawai}/izin-praktik/{izinPraktikId}/batal', [KredensialController::class, 'batalkanIzin']);
        Route::get('pegawai/{pegawai}/izin-praktik/{izinPraktikId}/dokumen', [KredensialController::class, 'dokumen']);
        Route::get('pegawai/{pegawai}/kewenangan-klinis', [KredensialController::class, 'daftarKewenangan']);
        Route::post('pegawai/{pegawai}/kewenangan-klinis', [KredensialController::class, 'catatKewenangan']);
        Route::post('pegawai/{pegawai}/kewenangan-klinis/{kewenanganId}/batal', [KredensialController::class, 'batalkanKewenangan']);
    });
