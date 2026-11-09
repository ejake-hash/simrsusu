<?php

use App\Modules\Pendaftaran\Http\Controllers\GabungPasienController;
use App\Modules\Pendaftaran\Http\Controllers\KunjunganController;
use App\Modules\Pendaftaran\Http\Controllers\KunjunganIgdController;
use App\Modules\Pendaftaran\Http\Controllers\PasienController;
use App\Modules\Pendaftaran\Http\Controllers\SepController;
use App\Modules\Pendaftaran\Http\Controllers\TriaseController;
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

    // Registrasi cepat IGD (tiket 2.23); izin igd.pendaftaran.* cakupan rs seperti rute pendaftaran lain.
    Route::get('kunjungan-igd', [KunjunganIgdController::class, 'index'])->middleware('can.rs:igd.pendaftaran.lihat');
    Route::post('kunjungan-igd', [KunjunganIgdController::class, 'store'])->middleware('can.rs:igd.pendaftaran.input');

    // Triase dan ulang triase (tiket 2.24); instrumen dari data induk RS.
    Route::get('kunjungan/{kunjungan}/triase', [TriaseController::class, 'index'])->middleware('can.rs:igd.triase.lihat');
    Route::post('kunjungan/{kunjungan}/triase', [TriaseController::class, 'store'])->middleware('can.rs:igd.triase.input');
    Route::get('instrumen-triase', [TriaseController::class, 'instrumen'])->middleware('can.rs:igd.triase.lihat');

    // Kepesertaan, rujukan, SEP (tiket 2.7): nomor di header X-Nomor, bukan URL.
    Route::get('bpjs/peserta', [SepController::class, 'peserta']);
    Route::get('bpjs/rujukan', [SepController::class, 'rujukan']);
    Route::post('kunjungan/{kunjungan}/sep', [SepController::class, 'terbitkan']);
});
