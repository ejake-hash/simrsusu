<?php

use App\Modules\MasterData\Http\Controllers\KsmController;
use App\Modules\MasterData\Http\Controllers\UnitOrganisasiController;
use Illuminate\Support\Facades\Route;

// Organisasi, unit & KSM (tiket 1.8a). Izin dan lapis 3 diperiksa di controller lewat Gate → AccessEvaluator:
// daftar unit boleh untuk dua izin (data induk ATAU pengelola akun), dan cakupan `unit` = subpohon.
Route::prefix('api/v1')->middleware(['api', 'auth:web', 'sesi.pengguna'])->whereUuid(['unit', 'ksm'])->group(function () {
    Route::get('unit-organisasi', [UnitOrganisasiController::class, 'index']);
    Route::post('unit-organisasi', [UnitOrganisasiController::class, 'store'])->middleware('can:master_data.organisasi.input');
    Route::get('unit-organisasi/{unit}', [UnitOrganisasiController::class, 'show']);
    Route::patch('unit-organisasi/{unit}', [UnitOrganisasiController::class, 'update'])->middleware('can:master_data.organisasi.perbaiki');

    Route::get('ksm', [KsmController::class, 'index']);
    Route::post('ksm', [KsmController::class, 'store']);
    Route::get('ksm/{ksm}', [KsmController::class, 'show']);
    Route::patch('ksm/{ksm}', [KsmController::class, 'update']);
});
