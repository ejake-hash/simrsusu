<?php

use App\Modules\Notifikasi\Http\Controllers\JenisTugasController;
use App\Modules\Notifikasi\Http\Controllers\NotifikasiSayaController;
use App\Modules\Notifikasi\Http\Controllers\TugasController;
use Illuminate\Support\Facades\Route;

// Notifikasi, kotak kerja, jenis tugas (tiket 1.7). /saya/* dan /tugas/{id} tanpa izin khusus (data akun itu saja);
// polling 30 detik lewat batas laju layar kerja agar tidak kena batas laju umum.
Route::prefix('api/v1')->middleware(['api', 'auth:web', 'sesi.pengguna'])->whereUuid(['notifikasi', 'tugas', 'jenisTugas'])->group(function () {
    Route::get('saya/notifikasi', [NotifikasiSayaController::class, 'index'])->withoutMiddleware('throttle:api')->middleware('throttle:api-layar-kerja');
    Route::post('saya/notifikasi/semua-dibaca', [NotifikasiSayaController::class, 'semuaDibaca']);
    Route::post('saya/notifikasi/{notifikasi}/dibaca', [NotifikasiSayaController::class, 'dibaca']);
    Route::get('saya/preferensi-notifikasi', [NotifikasiSayaController::class, 'preferensi']);
    Route::put('saya/preferensi-notifikasi', [NotifikasiSayaController::class, 'aturPreferensi']);
    Route::get('saya/tugas', [TugasController::class, 'index'])->withoutMiddleware('throttle:api')->middleware('throttle:api-layar-kerja');
    Route::get('tugas/{tugas}', [TugasController::class, 'show']);
    Route::get('jenis-tugas', [JenisTugasController::class, 'index']);
    Route::get('jenis-tugas/{jenisTugas}', [JenisTugasController::class, 'show']);
    Route::patch('jenis-tugas/{jenisTugas}', [JenisTugasController::class, 'update']);
});
