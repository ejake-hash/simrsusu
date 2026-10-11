<?php

use App\Core\ProfilRs\Http\ProfilRsController;
use Illuminate\Support\Facades\Route;

// Profil RS (tiket 1.8a). GET publik untuk halaman masuk (rate limit per IP dari grup api); ubah lewat Gate.
Route::prefix('api/v1')->middleware('api')->whereIn('jenis', ['logo', 'motif', 'foto'])->group(function () {
    Route::get('profil-rs', [ProfilRsController::class, 'show']);
    Route::get('profil-rs/berkas/{jenis}', [ProfilRsController::class, 'berkas']);

    Route::middleware(['auth:web', 'sesi.pengguna', 'can:pengaturan.profil_rs.perbaiki'])->group(function () {
        Route::put('profil-rs', [ProfilRsController::class, 'update']);
        Route::put('profil-rs/berkas/{jenis}', [ProfilRsController::class, 'gantiBerkas']);
    });
});
