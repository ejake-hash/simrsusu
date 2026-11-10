<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SPRI, antrean permintaan kamar, dan alokasi TT (tiket 2.28; FR-08-03, FR-03-06, FR-03-07, PRD 7.00.2 baris "Rawat inap",
 * PRD 11.2 `surat_kontrol` dan `permintaan_tempat_tidur`).
 * - kunjungan.surat_kontrol: jenis `spri` = catatan internal keputusan rawat. `no_surat_bpjs` kosong sampai bridging
 *   surat kontrol/SPRI BPJS diputuskan ([Perlu pemilik]). `diterbitkan_pada` = awal rawat inap untuk episode 2.29.
 * - kunjungan.permintaan_tempat_tidur: satu antrean dari IGD dan poli; paling banyak satu permintaan aktif
 *   (`menunggu`/`dialokasikan`) per kunjungan dan satu pesanan per TT. Tidak dihapus: batal = status + alasan + pelaku.
 * - Riwayat status TT menerima sumber `alokasi_batal` (pesanan batal, TT kembali kosong siap).
 * - Jenis tugas PERMINTAAN_KAMAR_MENUNGGU (FR-03-07, FR-53-05) dipasang NONAKTIF (keputusan pemilik 10 Nov 2026):
 *   angka tenggat di bawah hanya pengisi CHECK `batas_menit > 0` (contoh PRD FR-53-05 "> 4 jam"), tidak berlaku selama
 *   nonaktif; RS menetapkan batas, unit teguran (IGD), lalu mengaktifkan di layar Jenis Tugas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kunjungan.surat_kontrol', function (Blueprint $table) {
            $table->comment('Surat kontrol dan SPRI (PRD 11.2). Fase 2: jenis spri sebagai catatan internal; nomor BPJS menyusul.');
            $table->uuid('id')->primary();
            $table->foreignUuid('kunjungan_id')->constrained('kunjungan.kunjungan')->restrictOnDelete();
            $table->text('jenis')->comment('kontrol | spri');
            $table->text('no_surat_bpjs')->nullable()->comment('Kosong sampai bridging BPJS diputuskan');
            $table->date('tanggal_rencana')->comment('Tanggal rencana rawat (spri) atau kontrol, zona RS');
            $table->foreignUuid('diterbitkan_oleh')->comment('akses.pengguna.id dokter pemberi perintah')->constrained('akses.pengguna')->restrictOnDelete();
            $table->timestampTz('diterbitkan_pada', 6)->comment('Jam server; untuk spri = awal rawat inap (dipakai episode 2.29)');
            $table->text('status')->default('aktif');
            $table->text('alasan_batal')->nullable();
            $table->uuid('dibatalkan_oleh')->nullable()->comment('akses.pengguna.id; kosong = sistem');
            $table->timestampTz('dibatalkan_pada')->nullable();
            $table->kolomStandar();

            $table->index(['kunjungan_id', 'jenis']);
        });
        DB::statement("ALTER TABLE kunjungan.surat_kontrol ADD CONSTRAINT surat_kontrol_cek CHECK (jenis IN ('kontrol', 'spri')
            AND status IN ('aktif', 'batal') AND ((status = 'batal') = (dibatalkan_pada IS NOT NULL AND alasan_batal IS NOT NULL)))");

        Schema::create('kunjungan.permintaan_tempat_tidur', function (Blueprint $table) {
            $table->comment('Antrean permintaan kamar (FR-08-03) dari IGD dan poli. Satu aktif per kunjungan; tidak dihapus.');
            $table->uuid('id')->primary();
            $table->foreignUuid('kunjungan_id')->constrained('kunjungan.kunjungan')->restrictOnDelete();
            $table->foreignUuid('surat_kontrol_id')->comment('SPRI yang melahirkan permintaan ini')->constrained('kunjungan.surat_kontrol')->restrictOnDelete();
            $table->foreignUuid('perpindahan_pos_id')->comment('Perintah perpindahan → rawat_inap (matriks 7.00.3)')->constrained('kunjungan.perpindahan_pos')->restrictOnDelete();
            $table->text('asal')->comment('Pos asal matriks: igd | poliklinik (OK dan elektif menyusul)');
            $table->foreignUuid('kelas_diminta_id')->nullable()->comment('Kosong = belum ditentukan (disposisi IGD)')->constrained('inti.kelas_rawat')->restrictOnDelete();
            $table->foreignUuid('bangsal_pilihan_id')->nullable()->constrained('inti.bangsal')->restrictOnDelete();
            $table->foreignUuid('diminta_oleh')->constrained('akses.pengguna')->restrictOnDelete();
            $table->timestampTz('diminta_pada', 6)->comment('Jam server SPRI; awal lama menunggu');
            $table->foreignUuid('tempat_tidur_id')->nullable()->constrained('inti.tempat_tidur')->restrictOnDelete();
            $table->uuid('dialokasikan_oleh')->nullable()->comment('akses.pengguna.id');
            $table->timestampTz('dialokasikan_pada', 6)->nullable();
            $table->text('status')->default('menunggu')->comment('menunggu | dialokasikan | ditempatkan (2.29) | batal');
            $table->text('alasan_batal')->nullable();
            $table->uuid('dibatalkan_oleh')->nullable()->comment('akses.pengguna.id; kosong = sistem');
            $table->timestampTz('dibatalkan_pada')->nullable();
            $table->kolomStandar();

            $table->index(['status', 'diminta_pada', 'id']);
        });
        DB::statement("ALTER TABLE kunjungan.permintaan_tempat_tidur ADD CONSTRAINT permintaan_tempat_tidur_cek CHECK (
            asal IN ('igd', 'poliklinik') AND status IN ('menunggu', 'dialokasikan', 'ditempatkan', 'batal')
            AND (status <> 'menunggu' OR tempat_tidur_id IS NULL)
            AND (status NOT IN ('dialokasikan', 'ditempatkan') OR (tempat_tidur_id IS NOT NULL AND dialokasikan_pada IS NOT NULL))
            AND ((status = 'batal') = (dibatalkan_pada IS NOT NULL AND alasan_batal IS NOT NULL)))");
        DB::statement("CREATE UNIQUE INDEX permintaan_tempat_tidur_aktif_unik ON kunjungan.permintaan_tempat_tidur (kunjungan_id) WHERE status IN ('menunggu', 'dialokasikan')");
        DB::statement("CREATE UNIQUE INDEX permintaan_tempat_tidur_tt_unik ON kunjungan.permintaan_tempat_tidur (tempat_tidur_id) WHERE status = 'dialokasikan'");

        DB::statement('ALTER TABLE kunjungan.riwayat_status_tempat_tidur DROP CONSTRAINT riwayat_status_tempat_tidur_cek');
        DB::statement("ALTER TABLE kunjungan.riwayat_status_tempat_tidur ADD CONSTRAINT riwayat_status_tempat_tidur_cek CHECK (
            status IN ('terisi', 'kosong_siap', 'dibersihkan', 'dipesan', 'tidak_dipakai', 'rencana_pulang')
            AND (status_sebelum IS NULL OR status_sebelum IN ('terisi', 'kosong_siap', 'dibersihkan', 'dipesan', 'tidak_dipakai', 'rencana_pulang'))
            AND sumber_kejadian IN ('petugas', 'alokasi', 'alokasi_batal', 'penempatan', 'pindah_ruang', 'pulang'))");

        $sekarang = now();
        DB::table('inti.jenis_tugas')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'kode' => 'PERMINTAAN_KAMAR_MENUNGGU', 'nama' => 'Pasien IGD menunggu kamar',
            'uraian' => 'Permintaan kamar dari IGD belum dialokasikan TT (FR-03-07). Nonaktif sampai RS menetapkan batas dan unit teguran (IGD).',
            'modul' => 'rawat_inap', 'objek_jenis' => 'permintaan_tempat_tidur', 'tautan_layar' => '/pendaftaran/admisi?permintaan={objek_id}',
            'tenggat_dari' => 'dibuat', 'batas_menit' => 240, 'pengingat_menit' => -60, 'teguran_menit' => 0, 'eskalasi_menit' => 240,
            'kritis' => false, 'aktif' => false, 'dibuat_pada' => $sekarang, 'diubah_pada' => $sekarang,
        ]);
    }

    public function down(): void
    {
        DB::table('inti.jenis_tugas')->where('kode', 'PERMINTAAN_KAMAR_MENUNGGU')->delete();
        DB::statement('ALTER TABLE kunjungan.riwayat_status_tempat_tidur DROP CONSTRAINT riwayat_status_tempat_tidur_cek');
        DB::statement("ALTER TABLE kunjungan.riwayat_status_tempat_tidur ADD CONSTRAINT riwayat_status_tempat_tidur_cek CHECK (
            status IN ('terisi', 'kosong_siap', 'dibersihkan', 'dipesan', 'tidak_dipakai', 'rencana_pulang')
            AND (status_sebelum IS NULL OR status_sebelum IN ('terisi', 'kosong_siap', 'dibersihkan', 'dipesan', 'tidak_dipakai', 'rencana_pulang'))
            AND sumber_kejadian IN ('petugas', 'alokasi', 'penempatan', 'pindah_ruang', 'pulang'))");
        Schema::dropIfExists('kunjungan.permintaan_tempat_tidur');
        Schema::dropIfExists('kunjungan.surat_kontrol');
    }
};
