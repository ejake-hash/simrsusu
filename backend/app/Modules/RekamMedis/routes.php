<?php

use App\Modules\RekamMedis\Http\Controllers\AlergiController;
use App\Modules\RekamMedis\Http\Controllers\AsesmenAwalController;
use App\Modules\RekamMedis\Http\Controllers\CpptController;
use App\Modules\RekamMedis\Http\Controllers\PemeriksaanController;
use App\Modules\RekamMedis\Http\Controllers\PermintaanPemeriksaanController;
use Illuminate\Support\Facades\Route;

// Asesmen awal perawat RJ dan alergi (tiket 2.13, kontrak v0.23.0), CPPT (tiket 2.14, v0.24.0), pemeriksaan dokter (tiket 2.15,
// v0.25.0), order penunjang (tiket 2.16, v0.26.0): layar kerja klinis, rate limit api-layar-kerja.
Route::prefix('api/v1')->middleware(['api', 'auth:web', 'sesi.pengguna', 'throttle:api-layar-kerja'])->withoutMiddleware('throttle:api')
    ->whereUuid(['kunjungan', 'asesmen', 'pasien', 'alergi', 'episode', 'cppt', 'diagnosis', 'permintaan'])->group(function () {
        Route::get('kunjungan/{kunjungan}/asesmen-awal', [AsesmenAwalController::class, 'show']);
        Route::post('kunjungan/{kunjungan}/asesmen-awal', [AsesmenAwalController::class, 'store']);
        Route::patch('asesmen-awal/{asesmen}', [AsesmenAwalController::class, 'update']);
        Route::post('asesmen-awal/{asesmen}/selesai', [AsesmenAwalController::class, 'selesai']);
        Route::get('pasien/{pasien}/alergi', [AlergiController::class, 'index']);
        Route::post('pasien/{pasien}/alergi', [AlergiController::class, 'store']);
        Route::post('alergi/{alergi}/batal', [AlergiController::class, 'batal']);
        Route::get('episode/{episode}/cppt', [CpptController::class, 'episode']);
        Route::get('pasien/{pasien}/cppt', [CpptController::class, 'pasien']);
        Route::post('kunjungan/{kunjungan}/cppt', [CpptController::class, 'store']);
        Route::get('cppt/{cppt}', [CpptController::class, 'show']);
        Route::patch('cppt/{cppt}', [CpptController::class, 'update']);
        Route::post('cppt/{cppt}/sahkan', [CpptController::class, 'sahkan']);
        Route::post('cppt/{cppt}/adendum', [CpptController::class, 'adendum']);
        Route::post('kunjungan/{kunjungan}/mulai-periksa', [PemeriksaanController::class, 'mulai']);
        Route::post('kunjungan/{kunjungan}/selesai', [PemeriksaanController::class, 'selesai']);
        Route::get('kunjungan/{kunjungan}/diagnosis', [PemeriksaanController::class, 'index']);
        Route::post('kunjungan/{kunjungan}/diagnosis', [PemeriksaanController::class, 'store']);
        Route::patch('diagnosis/{diagnosis}', [PemeriksaanController::class, 'update']);
        Route::get('pasien/{pasien}/ringkasan-klinis', [PemeriksaanController::class, 'ringkasan']);
        Route::post('kunjungan/{kunjungan}/permintaan-pemeriksaan', [PermintaanPemeriksaanController::class, 'store']);
        Route::get('episode/{episode}/permintaan-pemeriksaan', [PermintaanPemeriksaanController::class, 'episode']);
        Route::post('permintaan-pemeriksaan/{permintaan}/batal', [PermintaanPemeriksaanController::class, 'batal']);
        Route::get('paket-referensi/{sistem}/kode', [PemeriksaanController::class, 'kode'])->whereIn('sistem', ['icd10', 'icd9cm', 'loinc', 'ucum']);
    });
