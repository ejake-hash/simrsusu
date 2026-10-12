<?php

use App\Core\Instalasi\Http\PenyiapanController;
use App\Core\Instalasi\Penyiapan;
use Illuminate\Support\Facades\Route;

// Wizard penyiapan instalasi kosong (tiket 1.8f). Langkah di luar daftar → 404.
Route::prefix('api/v1')->middleware(['api', 'auth:web', 'sesi.pengguna'])->group(function () {
    Route::get('penyiapan', [PenyiapanController::class, 'show'])->middleware('can:pengaturan.penyiapan.lihat');
    Route::post('penyiapan/{langkah}/selesai', [PenyiapanController::class, 'selesai'])
        ->whereIn('langkah', array_keys(Penyiapan::LANGKAH))->middleware('can:pengaturan.penyiapan.perbaiki');
});
