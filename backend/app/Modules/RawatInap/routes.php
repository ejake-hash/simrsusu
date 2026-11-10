<?php

use App\Modules\RawatInap\Http\Controllers\PermintaanTempatTidurController;
use App\Modules\RawatInap\Http\Controllers\PetaTempatTidurController;
use App\Modules\RawatInap\Http\Controllers\RawatInapController;
use Illuminate\Support\Facades\Route;

// Peta tempat tidur dan status TT (tiket 2.27). Izin sub-menu di cakupan mana pun; cakupan bangsal diperiksa di controller.
Route::prefix('api/v1')->middleware(['api', 'auth:web', 'sesi.pengguna'])->whereUuid(['tempatTidur', 'permintaan', 'kunjungan'])->group(function () {
    // Layar kerja admisi dan kepala ruang yang dipolling (ETag): batas laju layar kerja.
    Route::get('peta-tempat-tidur', [PetaTempatTidurController::class, 'index'])->middleware('can:rawat_inap.peta_tempat_tidur.lihat')
        ->withoutMiddleware('throttle:api')->middleware('throttle:api-layar-kerja');
    Route::post('tempat-tidur/{tempatTidur}/status', [PetaTempatTidurController::class, 'ubahStatus'])
        ->middleware('can:rawat_inap.peta_tempat_tidur.perbaiki');

    // SPRI, antrean permintaan kamar, alokasi TT (tiket 2.28). Izin SPRI = izin sel matriks, diperiksa di layanan.
    Route::post('kunjungan/{kunjungan}/spri', [PermintaanTempatTidurController::class, 'spri']);
    Route::get('permintaan-tempat-tidur', [PermintaanTempatTidurController::class, 'index'])->middleware('can:pendaftaran.admisi.lihat');
    Route::post('permintaan-tempat-tidur/{permintaan}/alokasi', [PermintaanTempatTidurController::class, 'alokasi'])
        ->middleware('can:pendaftaran.admisi.input');

    // Penempatan, batal permintaan, pindah ruang (tiket 2.29). Izin pindah ruang = izin sel matriks, diperiksa di layanan.
    Route::post('permintaan-tempat-tidur/{permintaan}/tempatkan', [PermintaanTempatTidurController::class, 'tempatkan'])
        ->middleware('can:rawat_inap.penempatan.input');
    Route::post('permintaan-tempat-tidur/{permintaan}/batal', [PermintaanTempatTidurController::class, 'batal'])
        ->middleware('can:pendaftaran.admisi.input');
    Route::post('kunjungan/{kunjungan}/pindah-ruang', [RawatInapController::class, 'pindahRuang']);
});
