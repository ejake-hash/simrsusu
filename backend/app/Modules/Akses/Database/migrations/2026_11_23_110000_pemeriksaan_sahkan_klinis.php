<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tiket 2.28 (keputusan pemilik 10 Nov 2026, tinjauan #194 butir 3): `rawat_jalan.pemeriksaan.sahkan` menjadi izin klinis
 * (lapis 5, SIP/SPK berlaku), karena izin itu memberi perintah sel matriks asal poliklinik termasuk SPRI. Mengencangkan:
 * pemegang tanpa izin praktik berlaku tidak lagi bisa menyelesaikan kunjungan poli.
 */
return new class extends Migration
{
    public function up(): void
    {
        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        DB::table('akses.izin')->where('kode', 'rawat_jalan.pemeriksaan.sahkan')->update(['butuh_kewenangan_klinis' => false, 'diubah_pada' => now()]);
        app(CacheHakAkses::class)->lupakanSemua();
    }
};
