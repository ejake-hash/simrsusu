<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Matriks perpindahan antar-pos sebagai data induk (tiket 2.26; FR-00-02, PRD 7.00.3, PRD 11.2 `aturan_perpindahan`).
 * Satu baris = satu pasangan pos asal → pos tujuan yang boleh diperintahkan, beserta kode izin yang harus dipegang pemberi
 * perintah (diperiksa lewat AccessEvaluator, bukan peran langsung). Isi awal BUKAN nilai klinis: ini aturan alur yang
 * disalin dari tabel 7.00.3 (asal IGD dan poliklinik; APS dan DOA dari FR-03-09). Perubahan lewat mesin usulan data induk
 * (jenis data `matriks_perpindahan`); baris tidak dihapus, nonaktif bila tidak dipakai.
 */
return new class extends Migration
{
    /** Isi awal: [pos_asal, pos_tujuan, izin_perintah, keterangan sumber]. */
    private const ISI_AWAL = [
        // PRD 7.00.3 baris "Poliklinik". Perintah dokter poli: izin yang sama dengan menyelesaikan pemeriksaan (2.15).
        ['poliklinik', 'poli_lain', 'rawat_jalan.pemeriksaan.sahkan', 'PRD 7.00.3: rujukan internal/konsul oleh dokter poli (episode sama bila hari sama)'],
        ['poliklinik', 'penunjang', 'rawat_jalan.pemeriksaan.sahkan', 'PRD 7.00.3: order dokter'],
        ['poliklinik', 'igd', 'rawat_jalan.pemeriksaan.sahkan', 'PRD 7.00.3: bila memburuk di poli'],
        ['poliklinik', 'kamar_operasi', 'rawat_jalan.pemeriksaan.sahkan', 'PRD 7.00.3: ODC/operasi elektif terjadwal'],
        ['poliklinik', 'icu_hcu', 'rawat_jalan.pemeriksaan.sahkan', 'PRD 7.00.3: via IGD/tim reaksi cepat'],
        ['poliklinik', 'rawat_inap', 'rawat_jalan.pemeriksaan.sahkan', 'PRD 7.00.3: SPRI dokter poli'],
        ['poliklinik', 'rujuk_keluar', 'rawat_jalan.pemeriksaan.sahkan', 'PRD 7.00.3: dokter poli'],
        ['poliklinik', 'pulang', 'rawat_jalan.pemeriksaan.sahkan', 'PRD 7.00.3'],
        ['poliklinik', 'meninggal', 'rawat_jalan.pemeriksaan.sahkan', 'PRD 7.00.3: alur B-05'],
        // PRD 7.00.3 baris "IGD"; disposisi IGD FR-03-09.
        ['igd', 'poli_lain', 'igd.disposisi.input', 'PRD 7.00.3: konsul spesialis'],
        ['igd', 'penunjang', 'igd.disposisi.input', 'PRD 7.00.3: order'],
        ['igd', 'kamar_operasi', 'igd.disposisi.input', 'PRD 7.00.3: operasi cito'],
        ['igd', 'icu_hcu', 'igd.disposisi.input', 'PRD 7.00.3'],
        ['igd', 'rawat_inap', 'igd.disposisi.input', 'PRD 7.00.3: episode rawat inap sejak IGD'],
        ['igd', 'rujuk_keluar', 'igd.disposisi.input', 'PRD 7.00.3'],
        ['igd', 'pulang', 'igd.disposisi.input', 'PRD 7.00.3: < 6 jam = episode RJ; > 6 jam = aturan D.2.b'],
        ['igd', 'meninggal', 'igd.disposisi.input', 'PRD 7.00.3'],
        ['igd', 'aps', 'igd.disposisi.input', 'FR-03-09: pulang atas permintaan sendiri (varian pulang 7.00.3)'],
        ['igd', 'doa', 'igd.disposisi.input', 'FR-03-09: DOA (varian meninggal 7.00.3)'],
    ];

    public function up(): void
    {
        Schema::create('kunjungan.aturan_perpindahan', function (Blueprint $table) {
            $table->comment('Matriks perpindahan antar-pos (FR-00-02, PRD 7.00.3): pasangan asal → tujuan yang boleh diperintahkan dan izin pemberi perintah. Data induk RS; diubah lewat usulan data induk, tidak dihapus.');
            $table->uuid('id')->primary();
            $table->text('pos_asal');
            $table->text('pos_tujuan');
            $table->text('izin_perintah')->comment('Kode izin akses.izin yang wajib dipegang pemberi perintah (cakupan rs), diperiksa AccessEvaluator');
            $table->text('keterangan')->nullable()->comment('Sumber/catatan aturan, mis. baris PRD 7.00.3');
            $table->text('status')->default('aktif');
            $table->kolomStandar();

            $table->unique(['pos_asal', 'pos_tujuan']);
        });
        // Kode pos = kolom/baris tabel 7.00.3 (+ APS dan DOA dari FR-03-09); sama dengan AturanPerpindahan::POS_ASAL/POS_TUJUAN.
        DB::statement("ALTER TABLE kunjungan.aturan_perpindahan ADD CONSTRAINT aturan_perpindahan_cek CHECK (
            pos_asal IN ('pendaftaran', 'poliklinik', 'igd', 'penunjang', 'kamar_operasi', 'icu_hcu', 'rawat_inap', 'farmasi', 'kasir')
            AND pos_tujuan IN ('poli_lain', 'penunjang', 'igd', 'kamar_operasi', 'icu_hcu', 'rawat_inap', 'rujuk_keluar', 'pulang', 'meninggal', 'aps', 'doa')
            AND status IN ('aktif', 'nonaktif') AND izin_perintah ~ '^[a-z0-9_]+(\\.[a-z0-9_]+){2,3}$')");

        $sekarang = now();
        foreach (self::ISI_AWAL as [$posAsal, $posTujuan, $izin, $keterangan]) {
            DB::table('kunjungan.aturan_perpindahan')->insert([
                'id' => (string) Str::uuid7(), 'pos_asal' => $posAsal, 'pos_tujuan' => $posTujuan, 'izin_perintah' => $izin,
                'keterangan' => $keterangan, 'status' => 'aktif', 'dibuat_pada' => $sekarang, 'diubah_pada' => $sekarang,
            ]);
        }

        // Mesin usulan 1.8c menerima jenis data `matriks_perpindahan` (pola 2.2/2.12).
        DB::statement('ALTER TABLE inti.usulan_data_induk DROP CONSTRAINT usulan_jenis_cek');
        DB::statement("ALTER TABLE inti.usulan_data_induk ADD CONSTRAINT usulan_jenis_cek CHECK (jenis_data IN ('ruang_tt', 'tarif', 'formularium', 'matriks_perpindahan'))");
        DB::table('inti.alur_usulan')->insertOrIgnore(['id' => (string) Str::uuid7(), 'jenis_data' => 'matriks_perpindahan']);
    }

    public function down(): void
    {
        DB::table('inti.alur_usulan')->where('jenis_data', 'matriks_perpindahan')->delete();
        DB::statement('ALTER TABLE inti.usulan_data_induk DROP CONSTRAINT usulan_jenis_cek');
        DB::statement("ALTER TABLE inti.usulan_data_induk ADD CONSTRAINT usulan_jenis_cek CHECK (jenis_data IN ('ruang_tt', 'tarif', 'formularium'))");
        Schema::dropIfExists('kunjungan.aturan_perpindahan');
    }
};
