<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Jadwal praktik dokter dan pengecualian cuti/libur (tiket 2.4, FR-01-15). Tidak dihapus: jadwal `nonaktif`, pengecualian `batal`. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kunjungan.jadwal_praktik', function (Blueprint $table) {
            $table->comment('Jadwal praktik mingguan dokter per poli dengan kuota JKN/non-JKN. Dokter wajib ber-SIP aktif saat dijadwalkan (dicek aplikasi).');
            $table->uuid('id')->primary();
            $table->foreignUuid('pegawai_id')->constrained('sdm.pegawai')->restrictOnDelete();
            $table->foreignUuid('poli_id')->constrained('inti.poliklinik')->restrictOnDelete();
            $table->foreignUuid('ruang_id')->nullable()->comment('Ruang praktik; null = ruang poliklinik')->constrained('inti.lokasi')->restrictOnDelete();
            $table->smallInteger('hari')->comment('ISO: 1 = Senin … 7 = Minggu (zona RS)');
            $table->time('jam_mulai');
            $table->time('jam_selesai');
            $table->integer('kuota_jkn');
            $table->integer('kuota_non_jkn');
            $table->date('berlaku_dari');
            $table->date('berlaku_sampai')->nullable();
            $table->text('status')->default('aktif');
            $table->kolomStandar();

            $table->index(['pegawai_id', 'hari', 'status']);
            $table->index(['poli_id', 'hari', 'status']);
            $table->index(['dibuat_pada', 'id']);
        });
        DB::statement("ALTER TABLE kunjungan.jadwal_praktik ADD CONSTRAINT jadwal_praktik_status_cek CHECK (status IN ('aktif', 'nonaktif'))");
        DB::statement('ALTER TABLE kunjungan.jadwal_praktik ADD CONSTRAINT jadwal_praktik_nilai_cek CHECK (hari BETWEEN 1 AND 7 AND jam_selesai > jam_mulai AND kuota_jkn BETWEEN 0 AND 1000 AND kuota_non_jkn BETWEEN 0 AND 1000 AND (berlaku_sampai IS NULL OR berlaku_sampai >= berlaku_dari))');

        Schema::create('kunjungan.jadwal_pengecualian', function (Blueprint $table) {
            $table->comment('Cuti dokter atau libur RS yang menutup jadwal praktik pada rentang tanggal. Pembatalan = status batal.');
            $table->uuid('id')->primary();
            $table->text('jenis');
            $table->foreignUuid('pegawai_id')->nullable()->comment('Wajib untuk cuti; null untuk libur RS')->constrained('sdm.pegawai')->restrictOnDelete();
            $table->date('dari_tanggal');
            $table->date('sampai_tanggal');
            $table->text('alasan');
            $table->text('status')->default('aktif');
            $table->text('alasan_batal')->nullable();
            $table->uuid('dibatalkan_oleh')->nullable()->comment('akses.pengguna.id');
            $table->timestampTz('dibatalkan_pada')->nullable();
            $table->kolomStandar();

            $table->index(['sampai_tanggal', 'status']);
            $table->index(['pegawai_id', 'sampai_tanggal']);
        });
        DB::statement("ALTER TABLE kunjungan.jadwal_pengecualian ADD CONSTRAINT jadwal_pengecualian_cek CHECK (jenis IN ('cuti', 'libur') AND status IN ('aktif', 'batal') AND sampai_tanggal >= dari_tanggal AND ((jenis = 'cuti') = (pegawai_id IS NOT NULL)) AND ((status = 'batal') = (alasan_batal IS NOT NULL)))");

        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        Schema::dropIfExists('kunjungan.jadwal_pengecualian');
        Schema::dropIfExists('kunjungan.jadwal_praktik');
    }
};
