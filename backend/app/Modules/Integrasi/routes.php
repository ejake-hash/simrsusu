<?php

use App\Modules\Integrasi\Http\Controllers\IntegrasiController;
use Illuminate\Support\Facades\Route;

// Gateway integrasi (tiket 1.9, kontrak v0.13.0). Izin diperiksa di controller (Gate → AccessEvaluator).
Route::prefix('api/v1/integrasi')->middleware(['api', 'auth:web', 'sesi.pengguna'])->group(function () {
    Route::get('sistem', [IntegrasiController::class, 'sistem']);
    Route::put('sistem/{kode}/kredensial', [IntegrasiController::class, 'simpanKredensial'])->whereIn('kode', ['bpjs_vclaim', 'aplicares', 'satusehat']);
    Route::post('sistem/{kode}/uji-koneksi', [IntegrasiController::class, 'ujiKoneksi'])->whereIn('kode', ['bpjs_vclaim', 'aplicares', 'satusehat'])
        ->middleware('throttle:integrasi-uji-koneksi');
    Route::get('kotak-keluar', [IntegrasiController::class, 'kotakKeluar']);
    Route::post('kotak-keluar/{id}/kirim-ulang', [IntegrasiController::class, 'kirimUlang'])->whereUuid('id');
    Route::get('log', [IntegrasiController::class, 'log']);
});
