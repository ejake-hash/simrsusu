<?php

use App\Modules\RawatInap\Http\Controllers\PetaTempatTidurController;
use Illuminate\Support\Facades\Route;

// Peta tempat tidur dan status TT (tiket 2.27). Izin sub-menu di cakupan mana pun; cakupan bangsal diperiksa di controller.
Route::prefix('api/v1')->middleware(['api', 'auth:web', 'sesi.pengguna'])->whereUuid(['tempatTidur'])->group(function () {
    // Layar kerja admisi dan kepala ruang yang dipolling (ETag): batas laju layar kerja.
    Route::get('peta-tempat-tidur', [PetaTempatTidurController::class, 'index'])->middleware('can:rawat_inap.peta_tempat_tidur.lihat')
        ->withoutMiddleware('throttle:api')->middleware('throttle:api-layar-kerja');
    Route::post('tempat-tidur/{tempatTidur}/status', [PetaTempatTidurController::class, 'ubahStatus'])
        ->middleware('can:rawat_inap.peta_tempat_tidur.perbaiki');
});
