<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Data induk papan pasien IGD (tiket 2.25; FR-03-03, FR-03-04, FR-03-05, FR-00-06).
 * - inti.area_igd: area IGD RS (mis. Resusitasi, Observasi, Hijau). Tanpa isi: diisi RS; contoh sintetis hanya lewat
 *   `lokal:kunjungan-contoh`.
 * - inti.aturan_papan_igd: satu baris batas waktu papan. Angka awal = data, diubah RS di layar data induk (pola
 *   inti.jenis_tugas). Batas tanggap 5 menit dari PRD FR-03-04/KPI FR-03-05; tanpa lebar peringatan sebelum batas
 *   (keputusan pemilik 9 Nov 2026: menunggu lalu lewat batas). Ambang mendekati 6 jam kosong sampai RS mengisi (keputusan
 *   pemilik 9 Nov 2026; PRD FR-00-06 tanpa angka): kosong = tanda mendekati 6 jam tidak dipakai. Batas 6 jam sendiri aturan
 *   INA-CBG D.1.f/D.2.b (Kunjungan::BATAS_JAM_IGD), bukan data induk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inti.area_igd', function (Blueprint $table) {
            $table->comment('Area IGD yang ditetapkan RS (FR-03-03). Tidak dihapus: nonaktif bila tidak dipakai, penempatan lama tetap terbaca.');
            $table->uuid('id')->primary();
            $table->text('kode')->unique();
            $table->text('nama');
            $table->integer('urutan')->default(0)->comment('Urut tampil di papan (kecil dulu)');
            $table->text('status')->default('aktif');
            $table->kolomStandar();
        });
        DB::statement("ALTER TABLE inti.area_igd ADD CONSTRAINT area_igd_cek CHECK (status IN ('aktif', 'nonaktif') AND urutan BETWEEN 0 AND 999)");

        Schema::create('inti.aturan_papan_igd', function (Blueprint $table) {
            $table->comment('Batas waktu papan IGD (satu baris; FR-03-04, FR-03-05, FR-00-06). Diubah RS di layar data induk.');
            $table->uuid('id')->primary();
            $table->integer('batas_tanggap_menit')->comment('Batas waktu tanggap: jam tiba → dokter mulai mengisi asesmen awal (FR-03-04, KPI FR-03-05)');
            $table->integer('peringatan_lama_igd_menit')->nullable()->comment('Tanda mendekati 6 jam, menit sebelum 6 jam (FR-00-06); kosong = tanda tidak dipakai');
            $table->kolomStandar();
        });
        DB::statement('ALTER TABLE inti.aturan_papan_igd ADD CONSTRAINT aturan_papan_igd_cek CHECK (batas_tanggap_menit BETWEEN 1 AND 1440 AND (peringatan_lama_igd_menit IS NULL OR peringatan_lama_igd_menit BETWEEN 1 AND 359))');
        DB::statement('CREATE UNIQUE INDEX aturan_papan_igd_satu_baris ON inti.aturan_papan_igd ((true))');

        $sekarang = now();
        DB::table('inti.aturan_papan_igd')->insert([
            'id' => (string) Str::uuid7(), 'batas_tanggap_menit' => 5, 'peringatan_lama_igd_menit' => null,
            'dibuat_pada' => $sekarang, 'diubah_pada' => $sekarang,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('inti.aturan_papan_igd');
        Schema::dropIfExists('inti.area_igd');
    }
};
