<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gabung RM ganda (tiket 2.9; FR-01-02). RM lama tidak dihapus: ditandai `digabung_ke_id` dan tetap bisa ditelusuri.
 * Usulan dua tahap (diusulkan → digabung | ditolak) dengan pengusul ≠ pemverifikasi, seperti alur usulan 1.8c.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE akses.izin DROP CONSTRAINT IF EXISTS izin_jenis_aksi_cek');
        DB::statement("ALTER TABLE akses.izin ADD CONSTRAINT izin_jenis_aksi_cek CHECK (jenis_aksi IN
            ('lihat', 'input', 'perbaiki', 'sahkan', 'verifikasi', 'setujui', 'batal', 'ekspor', 'cetak', 'lihat_rupiah', 'usul'))");

        Schema::table('pasien.pasien', function (Blueprint $table) {
            $table->foreignUuid('digabung_ke_id')->nullable()->comment('RM tujuan; terisi = RM ini sudah digabung (tidak dipakai lagi)')
                ->constrained('pasien.pasien')->restrictOnDelete();
            $table->timestampTz('digabung_pada')->nullable();
        });
        DB::statement('ALTER TABLE pasien.pasien ADD CONSTRAINT pasien_digabung_cek CHECK (digabung_ke_id IS DISTINCT FROM id AND ((digabung_ke_id IS NULL) = (digabung_pada IS NULL)))');

        Schema::create('pasien.usulan_gabung', function (Blueprint $table) {
            $table->comment('Usulan menggabung RM ganda: kunjungan RM sumber pindah ke RM tujuan setelah diverifikasi Rekam Medis.');
            $table->uuid('id')->primary();
            $table->foreignUuid('sumber_id')->comment('RM yang digabung (ditandai digabung)')->constrained('pasien.pasien')->restrictOnDelete();
            $table->foreignUuid('tujuan_id')->comment('RM yang dipertahankan')->constrained('pasien.pasien')->restrictOnDelete();
            $table->text('alasan');
            $table->text('tahap')->default('diusulkan');
            $table->uuid('diusulkan_oleh')->comment('akses.pengguna.id');
            $table->timestampTz('diusulkan_pada')->useCurrent();
            $table->uuid('diverifikasi_oleh')->nullable()->comment('akses.pengguna.id');
            $table->timestampTz('diverifikasi_pada')->nullable();
            $table->text('catatan_verifikasi')->nullable();
            $table->jsonb('rincian_pindah')->nullable()->comment('Id objek yang dipindahkan: kunjungan, episode, relasi_perawatan (tanpa data pribadi)');
            $table->kolomStandar();

            $table->index(['dibuat_pada', 'id']);
            $table->index(['tahap', 'dibuat_pada']);
        });
        DB::statement("ALTER TABLE pasien.usulan_gabung ADD CONSTRAINT usulan_gabung_cek CHECK (tahap IN ('diusulkan', 'digabung', 'ditolak') AND sumber_id <> tujuan_id
            AND (tahap = 'diusulkan' OR (diverifikasi_oleh IS NOT NULL AND diverifikasi_pada IS NOT NULL AND diverifikasi_oleh <> diusulkan_oleh))
            AND (tahap <> 'digabung') = (rincian_pindah IS NULL))");
        // Satu RM hanya punya satu usulan terbuka (penjaga balapan).
        DB::statement("CREATE UNIQUE INDEX usulan_gabung_sumber_terbuka ON pasien.usulan_gabung (sumber_id) WHERE tahap = 'diusulkan'");
        DB::statement("CREATE UNIQUE INDEX usulan_gabung_tujuan_terbuka ON pasien.usulan_gabung (tujuan_id) WHERE tahap = 'diusulkan'");

        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        Schema::dropIfExists('pasien.usulan_gabung');
        Schema::table('pasien.pasien', function (Blueprint $table) {
            $table->dropConstrainedForeignId('digabung_ke_id');
            $table->dropColumn('digabung_pada');
        });
    }
};
