<?php

use App\Modules\MasterData\Http\Controllers\BangsalController;
use App\Modules\MasterData\Http\Controllers\ImporLokasiController;
use App\Modules\MasterData\Http\Controllers\JadwalPengecualianController;
use App\Modules\MasterData\Http\Controllers\JadwalPraktikController;
use App\Modules\MasterData\Http\Controllers\KamarController;
use App\Modules\MasterData\Http\Controllers\KapasitasTempatTidurController;
use App\Modules\MasterData\Http\Controllers\KelasRawatController;
use App\Modules\MasterData\Http\Controllers\KsmController;
use App\Modules\MasterData\Http\Controllers\LokasiController;
use App\Modules\MasterData\Http\Controllers\PaketReferensiController;
use App\Modules\MasterData\Http\Controllers\PenjaminController;
use App\Modules\MasterData\Http\Controllers\PoliklinikController;
use App\Modules\MasterData\Http\Controllers\RiwayatVersiController;
use App\Modules\MasterData\Http\Controllers\TarifController;
use App\Modules\MasterData\Http\Controllers\TempatTidurController;
use App\Modules\MasterData\Http\Controllers\UnitOrganisasiController;
use App\Modules\MasterData\Http\Controllers\UsulanDataIndukController;
use Illuminate\Support\Facades\Route;

// Organisasi, unit & KSM (tiket 1.8a). Izin dan lapis 3 diperiksa di controller lewat Gate → AccessEvaluator:
// daftar unit boleh untuk dua izin (data induk ATAU pengelola akun), dan cakupan `unit` = subpohon.
Route::prefix('api/v1')->middleware(['api', 'auth:web', 'sesi.pengguna'])->whereUuid(['unit', 'ksm', 'lokasi', 'bangsal', 'kamar', 'tempatTidur', 'kelasRawat', 'penjamin', 'tarif', 'jadwal', 'pengecualian', 'poliklinik', 'usulan', 'objekId'])->group(function () {
    Route::get('unit-organisasi', [UnitOrganisasiController::class, 'index']);
    Route::post('unit-organisasi', [UnitOrganisasiController::class, 'store'])->middleware('can:master_data.organisasi.input');
    Route::get('unit-organisasi/{unit}', [UnitOrganisasiController::class, 'show']);
    Route::patch('unit-organisasi/{unit}', [UnitOrganisasiController::class, 'update'])->middleware('can:master_data.organisasi.perbaiki');

    Route::get('ksm', [KsmController::class, 'index']);
    Route::post('ksm', [KsmController::class, 'store']);
    Route::get('ksm/{ksm}', [KsmController::class, 'show']);
    Route::patch('ksm/{ksm}', [KsmController::class, 'update']);

    // Lokasi fisik, ruang, dan TT (tiket 1.8b): izin master_data.organisasi.* cakupan rs, diperiksa di controller.
    Route::get('lokasi', [LokasiController::class, 'index']);
    Route::post('lokasi', [LokasiController::class, 'store']);
    Route::post('lokasi/impor', ImporLokasiController::class);
    Route::get('lokasi/{lokasi}', [LokasiController::class, 'show']);
    Route::patch('lokasi/{lokasi}', [LokasiController::class, 'update']);
    Route::get('bangsal', [BangsalController::class, 'index']);
    Route::post('bangsal', [BangsalController::class, 'store']);
    Route::get('bangsal/{bangsal}', [BangsalController::class, 'show']);
    Route::patch('bangsal/{bangsal}', [BangsalController::class, 'update']);
    Route::get('kamar', [KamarController::class, 'index']);
    Route::post('kamar', [KamarController::class, 'store']);
    Route::get('kamar/{kamar}', [KamarController::class, 'show']);
    Route::patch('kamar/{kamar}', [KamarController::class, 'update']);
    Route::get('tempat-tidur', [TempatTidurController::class, 'index']);
    Route::post('tempat-tidur', [TempatTidurController::class, 'store']);
    Route::get('tempat-tidur/{tempatTidur}', [TempatTidurController::class, 'show']);
    Route::patch('tempat-tidur/{tempatTidur}', [TempatTidurController::class, 'update']);
    Route::post('tempat-tidur/{tempatTidur}/tutup', [TempatTidurController::class, 'tutup']);
    Route::get('kelas-rawat', [KelasRawatController::class, 'index']);
    Route::post('kelas-rawat', [KelasRawatController::class, 'store']);
    Route::get('kelas-rawat/{kelasRawat}', [KelasRawatController::class, 'show']);
    Route::patch('kelas-rawat/{kelasRawat}', [KelasRawatController::class, 'update']);
    Route::get('poliklinik', [PoliklinikController::class, 'index']);
    Route::post('poliklinik', [PoliklinikController::class, 'store']);
    Route::get('poliklinik/{poliklinik}', [PoliklinikController::class, 'show']);
    Route::patch('poliklinik/{poliklinik}', [PoliklinikController::class, 'update']);
    Route::get('kapasitas-tempat-tidur', KapasitasTempatTidurController::class);

    // Usulan perubahan data induk berjenjang (tiket 1.8c): izin master_data.usulan.* cakupan rs, diperiksa di controller/mesin.
    Route::get('usulan-data-induk', [UsulanDataIndukController::class, 'index']);
    Route::post('usulan-data-induk', [UsulanDataIndukController::class, 'store']);
    Route::get('usulan-data-induk/{usulan}', [UsulanDataIndukController::class, 'show']);
    Route::post('usulan-data-induk/{usulan}/verifikasi', [UsulanDataIndukController::class, 'verifikasi']);
    Route::post('usulan-data-induk/{usulan}/setujui', [UsulanDataIndukController::class, 'setujui']);
    Route::post('usulan-data-induk/{usulan}/kembalikan', [UsulanDataIndukController::class, 'kembalikan']);
    Route::post('usulan-data-induk/{usulan}/kirim-ulang', [UsulanDataIndukController::class, 'kirimUlang']);
    Route::get('data-induk/{jenis}/{objekId}/riwayat-versi', RiwayatVersiController::class)->whereIn('jenis', ['ruang', 'kamar', 'tempat_tidur']);

    // Penjamin (tiket 2.1): izin master_data.penjamin.* cakupan rs, diperiksa di controller.
    Route::get('data-induk/penjamin', [PenjaminController::class, 'index']);
    Route::post('data-induk/penjamin', [PenjaminController::class, 'store']);
    Route::get('data-induk/penjamin/{penjamin}', [PenjaminController::class, 'show']);
    Route::patch('data-induk/penjamin/{penjamin}', [PenjaminController::class, 'update']);

    // Buku tarif (tiket 2.2): hanya baca; izin master_data.tarif.lihat cakupan rs, diperiksa di controller. Perubahan lewat usulan 1.8c.
    Route::get('data-induk/tarif', [TarifController::class, 'index']);
    Route::get('data-induk/tarif/{tarif}/versi', [TarifController::class, 'versi']);

    // Jadwal praktik dan pengecualian (tiket 2.4): izin master_data.jadwal_praktik.* cakupan rs, diperiksa di controller.
    Route::get('jadwal-praktik', [JadwalPraktikController::class, 'index']);
    Route::post('jadwal-praktik', [JadwalPraktikController::class, 'store']);
    Route::patch('jadwal-praktik/{jadwal}', [JadwalPraktikController::class, 'update']);
    Route::post('jadwal-pengecualian', [JadwalPengecualianController::class, 'store']);
    Route::patch('jadwal-pengecualian/{pengecualian}', [JadwalPengecualianController::class, 'update']);

    // Paket referensi (tiket 1.8e): izin master_data.katalog.input cakupan rs, diperiksa di controller.
    Route::post('paket-referensi/{sistem}/muat', [PaketReferensiController::class, 'muat'])
        ->whereIn('sistem', ['wilayah', 'referensi', 'icd10', 'icd9cm', 'loinc', 'ucum']);
});
