<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perintah perpindahan pasien antar-pos (tiket 2.26; FR-00-02, FR-00-03, FR-03-09, PRD 7.00.3, PRD 11.2 `perpindahan_pos`).
 * Hanya ditambah: satu baris = satu perintah yang sah menurut matriks (kunjungan.aturan_perpindahan), membawa pemutus,
 * alasan, pos asal/tujuan, serah terima, dan status episode saat perintah (7.00.3). Kelanjutan rawat inap (permintaan
 * kamar, penempatan) dicatat tiket 2.28/2.29 di tabelnya sendiri, tidak dengan mengubah baris ini.
 * Episode: kolom `selesai_pada` (PRD 11.2 "menyusul") diisi saat episode IGD > 6 jam selesai karena disposisi (7.00.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kunjungan.perpindahan_pos', function (Blueprint $table) {
            $table->comment('Perintah perpindahan antar-pos (FR-00-02/03, 7.00.3), mis. disposisi IGD. Hanya ditambah; alasan dan serah terima klinis, tidak ke log/audit.');
            $table->uuid('id')->primary();
            $table->foreignUuid('episode_id')->comment('Episode kunjungan asal saat perintah (potret, tidak ikut dipindah)')
                ->constrained('kunjungan.episode')->restrictOnDelete();
            $table->foreignUuid('dari_kunjungan_id')->constrained('kunjungan.kunjungan')->restrictOnDelete();
            $table->foreignUuid('aturan_perpindahan_id')->comment('Sel matriks yang mengesahkan perintah ini')
                ->constrained('kunjungan.aturan_perpindahan')->restrictOnDelete();
            $table->text('pos_asal')->comment('Salinan dari sel matriks saat perintah');
            $table->text('pos_tujuan')->comment('Salinan dari sel matriks saat perintah');
            $table->text('izin_perintah')->comment('Salinan izin pemberi perintah dari sel matriks saat perintah');
            $table->text('alasan')->comment('KLINIS: alasan keputusan; tidak pernah di log/audit');
            $table->text('serah_terima')->nullable()->comment('KLINIS: catatan serah terima (bagian data RM-15); tidak pernah di log/audit');
            $table->text('status')->comment('Akibat perintah pada kunjungan asal: selesai | menunggu_kamar | menunggu_kamar_operasi');
            $table->text('episode_jenis')->comment('Jenis episode sesudah perintah');
            $table->text('episode_status')->comment('Status episode sesudah perintah: aktif | selesai');
            $table->foreignUuid('diputuskan_oleh')->comment('akses.pengguna.id pemberi perintah')->constrained('akses.pengguna')->restrictOnDelete();
            $table->timestampTz('terjadi_pada', 6)->comment('Jam server');
            $table->timestampTz('dibuat_pada')->useCurrent();

            $table->index(['dari_kunjungan_id', 'terjadi_pada', 'id']);
            $table->index('episode_id');
        });
        DB::statement("ALTER TABLE kunjungan.perpindahan_pos ADD CONSTRAINT perpindahan_pos_cek CHECK (status IN ('selesai', 'menunggu_kamar', 'menunggu_kamar_operasi') AND episode_status IN ('aktif', 'selesai') AND length(btrim(alasan)) >= 5)");
        // Hanya ditambah (pola kunjungan.triase): perintah yang sudah dikeluarkan tidak bisa diubah simrs_app.
        DB::statement('REVOKE UPDATE ON kunjungan.perpindahan_pos FROM simrs_app');

        DB::statement('ALTER TABLE kunjungan.episode ADD COLUMN selesai_pada timestamptz');
        DB::statement("COMMENT ON COLUMN kunjungan.episode.selesai_pada IS 'Jam episode selesai (mis. episode IGD > 6 jam saat disposisi, 7.00.2)'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE kunjungan.episode DROP COLUMN IF EXISTS selesai_pada');
        Schema::dropIfExists('kunjungan.perpindahan_pos');
    }
};
