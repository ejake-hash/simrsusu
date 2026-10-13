<?php

use App\Modules\Akses\Http\Controllers\AksesAkunController;
use App\Modules\Akses\Http\Controllers\AkunController;
use App\Modules\Akses\Http\Controllers\AkunPegawaiController;
use App\Modules\Akses\Http\Controllers\AutentikasiController;
use App\Modules\Akses\Http\Controllers\IzinController;
use App\Modules\Akses\Http\Controllers\KebijakanKeamananController;
use App\Modules\Akses\Http\Controllers\LabelAkunController;
use App\Modules\Akses\Http\Controllers\LabelSensitivitasController;
use App\Modules\Akses\Http\Controllers\MenuController;
use App\Modules\Akses\Http\Controllers\MenuSayaController;
use App\Modules\Akses\Http\Controllers\MfaController;
use App\Modules\Akses\Http\Controllers\MfaUlangController;
use App\Modules\Akses\Http\Controllers\PeranController;
use App\Modules\Akses\Http\Controllers\PerangkatController;
use App\Modules\Akses\Http\Controllers\PermintaanAksesController;
use App\Modules\Akses\Http\Controllers\PersetujuanAksesController;
use App\Modules\Akses\Http\Controllers\SandiController;
use App\Modules\Akses\Http\Controllers\SayaController;
use App\Modules\Akses\Http\Middleware\SesiPengguna;
use Illuminate\Support\Facades\Route;

// GET /sanctum/csrf-cookie didaftarkan oleh Sanctum. Rute bersesi memakai guard web (cookie),
// bukan token pribadi Sanctum: token hanya untuk integrasi mesin-ke-mesin.
// Id bukan UUID → 404 tidak_ditemukan, bukan error tipe dari PostgreSQL.
Route::prefix('api/v1')->middleware('api')->whereUuid(['peran', 'akun', 'penugasanId', 'izinKhususId', 'persetujuan', 'permintaan', 'labelAkunId'])->group(function () {
    Route::post('autentikasi/masuk', [AutentikasiController::class, 'masuk'])
        ->middleware('throttle:autentikasi-masuk');

    // Langkah MFA (PRD 10.3.2): sesi belum masuk, jadi di luar auth:web; challenge di sesi.
    Route::middleware('throttle:autentikasi-mfa')->group(function () {
        Route::post('autentikasi/mfa', [MfaController::class, 'verifikasi'])->name('autentikasi.mfa');
        Route::post('autentikasi/mfa/daftar', [MfaController::class, 'daftar'])->name('autentikasi.mfa.daftar');
        Route::post('autentikasi/mfa/daftar/konfirmasi', [MfaController::class, 'konfirmasiDaftar'])->name('autentikasi.mfa.daftar.konfirmasi');
    });

    Route::middleware(['auth:web', SesiPengguna::class])->group(function () {
        Route::post('autentikasi/keluar', [AutentikasiController::class, 'keluar']);
        Route::get('saya', SayaController::class);
        Route::get('saya/menu', MenuSayaController::class);

        // Izin diperiksa Gate → AccessEvaluator (ModuleServiceProvider), bukan peran di controller. Pengelolaan peran dan
        // akun memakai can.rs: izinnya harus bercakupan seluruh RS (IzinSeluruhRs; temuan ASVS tiket 1.12).
        // Pengaturan → Peran & izin, Akun & akses (tiket 1.2c, kontrak docs/api/openapi.yaml).
        Route::get('menu', MenuController::class);

        Route::get('peran', [PeranController::class, 'index'])->middleware('can.rs:pengaturan.peran.lihat');
        Route::post('peran', [PeranController::class, 'store'])->middleware('can.rs:pengaturan.peran.input');
        Route::get('peran/{peran}', [PeranController::class, 'show'])->middleware('can.rs:pengaturan.peran.lihat');
        Route::patch('peran/{peran}', [PeranController::class, 'update'])->middleware('can.rs:pengaturan.peran.perbaiki');
        Route::put('peran/{peran}/izin', [PeranController::class, 'gantiIzin'])->middleware('can.rs:pengaturan.peran.perbaiki');

        Route::get('akun', [AkunController::class, 'index'])->middleware('can.rs:pengaturan.akun.lihat');
        Route::get('akun/saran-peran', [AkunController::class, 'saranPeran'])->middleware('can.rs:pengaturan.akun.lihat');
        Route::get('akun/{akun}', [AkunController::class, 'show'])->middleware('can.rs:pengaturan.akun.lihat');
        Route::get('akun/{akun}/menu', [AkunController::class, 'menu'])->middleware('can.rs:pengaturan.akun.lihat');
        Route::post('akun/{akun}/penugasan', [AksesAkunController::class, 'tugaskan'])->middleware('can.rs:pengaturan.akun.input');
        Route::post('akun/{akun}/penugasan/{penugasanId}/batal', [AksesAkunController::class, 'batalkanPenugasan'])->middleware('can.rs:pengaturan.akun.perbaiki');
        Route::post('akun/{akun}/izin-khusus', [AksesAkunController::class, 'buatIzinKhusus'])->middleware('can.rs:pengaturan.akun.input');
        Route::post('akun/{akun}/izin-khusus/{izinKhususId}/batal', [AksesAkunController::class, 'batalkanIzinKhusus'])->middleware('can.rs:pengaturan.akun.perbaiki');
        Route::put('akun/{akun}/wajib-mfa', [AksesAkunController::class, 'aturWajibMfa'])->middleware('can.rs:pengaturan.akun.perbaiki');
        Route::post('akun/{akun}/mfa/reset', [AksesAkunController::class, 'resetMfa'])->middleware('can.rs:pengaturan.akun.perbaiki');
        // Lupa sandi: sandi sementara sekali tampil, wajib ganti saat masuk (tiket 1.14).
        Route::post('akun/{akun}/sandi/reset', [AksesAkunController::class, 'resetSandi'])->middleware('can.rs:pengaturan.akun.perbaiki');
        // Perangkat tepercaya (tiket 1.13): milik sendiri tanpa izin khusus, milik akun lain lewat Akun & akses.
        Route::get('saya/perangkat', [PerangkatController::class, 'saya']);
        Route::post('saya/perangkat/{perangkatId}/cabut', [PerangkatController::class, 'cabutSaya'])->whereUuid('perangkatId');
        Route::get('akun/{akun}/perangkat', [PerangkatController::class, 'akun'])->middleware('can.rs:pengaturan.akun.lihat');
        Route::post('akun/{akun}/perangkat/{perangkatId}/cabut', [PerangkatController::class, 'cabutAkun'])->whereUuid('perangkatId')
            ->middleware('can.rs:pengaturan.akun.perbaiki');
        Route::get('kebijakan-keamanan', [KebijakanKeamananController::class, 'show'])->middleware('can.rs:pengaturan.keamanan.lihat');
        Route::put('kebijakan-keamanan', [KebijakanKeamananController::class, 'update'])->middleware('can.rs:pengaturan.keamanan.perbaiki');
        // Akun dari pegawai, nonaktif/aktifkan, ganti sandi (tiket 1.8d).
        Route::post('akun/{akun}/nonaktifkan', [AksesAkunController::class, 'nonaktifkan'])->middleware('can.rs:pengaturan.akun.perbaiki');
        Route::post('akun/{akun}/aktifkan', [AksesAkunController::class, 'aktifkan'])->middleware('can.rs:pengaturan.akun.perbaiki');
        Route::post('pegawai/{pegawai}/akun', AkunPegawaiController::class)->whereUuid('pegawai')->middleware('can.rs:pengaturan.akun.input');
        Route::post('saya/sandi', SandiController::class);
        // MFA ulang di sesi berjalan untuk izin butuh_mfa (K11, PRD 10.3.2 langkah 3; tiket 1.5).
        Route::post('saya/mfa/verifikasi-ulang', MfaUlangController::class)->middleware('throttle:autentikasi-mfa');

        // Persetujuan dan permintaan akses (tiket 1.2d). /persetujuan-akses tanpa izin: hanya data yang relevan
        // bagi akun itu (pengaju, penerima, pemegang peran penyetuju); aturan keputusan di AlurPersetujuan.
        Route::put('izin/{kode}/penyetuju', [IzinController::class, 'aturPenyetuju'])->middleware('can.rs:pengaturan.peran.perbaiki');
        Route::get('persetujuan-akses', [PersetujuanAksesController::class, 'index']);
        Route::get('persetujuan-akses/{persetujuan}', [PersetujuanAksesController::class, 'show']);
        Route::post('persetujuan-akses/{persetujuan}/setujui', [PersetujuanAksesController::class, 'setujui']);
        Route::post('persetujuan-akses/{persetujuan}/tolak', [PersetujuanAksesController::class, 'tolak']);
        Route::post('persetujuan-akses/{persetujuan}/batalkan', [PersetujuanAksesController::class, 'batalkan']);
        Route::get('permintaan-akses', [PermintaanAksesController::class, 'index']);
        Route::post('permintaan-akses', [PermintaanAksesController::class, 'store']);
        // Lapis 4: label sensitivitas dan izin label per akun (tiket 1.5, kontrak v0.14.0).
        Route::get('label-sensitivitas', [LabelSensitivitasController::class, 'index'])->middleware('can.rs:pengaturan.akun.lihat');
        Route::put('label-sensitivitas/{kode}/penyetuju', [LabelSensitivitasController::class, 'aturPenyetuju'])->middleware('can.rs:pengaturan.peran.perbaiki');
        Route::get('akun/{akun}/label', [LabelAkunController::class, 'index'])->middleware('can.rs:pengaturan.akun.lihat');
        Route::post('akun/{akun}/label', [LabelAkunController::class, 'ajukan'])->middleware('can.rs:pengaturan.akun.input');
        Route::post('akun/{akun}/label/{labelAkunId}/batal', [LabelAkunController::class, 'batalkan'])->middleware('can.rs:pengaturan.akun.perbaiki');
        Route::post('permintaan-akses/{permintaan}/tolak', [PermintaanAksesController::class, 'tolak'])->middleware('can.rs:pengaturan.akun.perbaiki');
    });
});
