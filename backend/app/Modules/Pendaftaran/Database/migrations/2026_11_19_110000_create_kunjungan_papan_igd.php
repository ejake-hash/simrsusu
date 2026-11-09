<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Papan pasien IGD (tiket 2.25; FR-03-03, PRD 11.2 `papan_igd`). Satu baris per kunjungan IGD yang sudah ditempatkan di
 * area (data induk inti.area_igd); pindah area = baris yang sama diubah, jejaknya di audit `kunjungan.area_igd_ditempatkan`.
 * Asumsi: kolom PRD `status` dan `menunggu_kamar_sejak` tidak disalin ke sini, dibaca dari kunjungan.kunjungan dan
 * perintah perpindahan yang masih menunggu (kunjungan.perpindahan_pos, 2.26) agar tidak ada dua sumber; `rencana` menyusul
 * bersama asesmen awal medis IGD (FR-03-08).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kunjungan.papan_igd', function (Blueprint $table) {
            $table->comment('Penempatan pasien IGD di area (FR-03-03). Satu baris per kunjungan; riwayat pindah area di audit.');
            $table->uuid('id')->primary();
            $table->foreignUuid('kunjungan_id')->unique()->constrained('kunjungan.kunjungan')->restrictOnDelete();
            $table->foreignUuid('area_igd_id')->constrained('inti.area_igd')->restrictOnDelete();
            $table->timestampTz('ditempatkan_pada')->comment('Jam server penempatan terakhir');
            $table->kolomStandar();

            $table->index('area_igd_id');
        });

        // Waktu tanggap di papan = saat dokter mulai mengisi asesmen awal IGD (FR-03-04; keputusan pemilik 9 Nov 2026):
        // min(dibuat_pada) per kunjungan, draf ikut dihitung.
        DB::statement("CREATE INDEX catatan_klinis_asesmen_dokter_igd ON klinis.catatan_klinis (kunjungan_id, dibuat_pada) WHERE jenis = 'asesmen' AND tatanan = 'igd' AND profesi IN ('dokter', 'ppds')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS klinis.catatan_klinis_asesmen_dokter_igd');
        Schema::dropIfExists('kunjungan.papan_igd');
    }
};
