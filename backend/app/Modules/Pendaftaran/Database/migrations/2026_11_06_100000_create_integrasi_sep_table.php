<?php

use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SEP per kunjungan (tiket 2.7; FR-01-10, kontrak v0.21.0). Tidak dihapus: penolakan disimpan sebagai jejak (`ditolak`),
 * pembatalan (endpointnya menyusul, B-10) lewat status `batal` beserta alasan, pelaku, dan waktunya. Satu SEP
 * `menunggu`/`terbit` per kunjungan (penjaga balapan 409). Tugas SEP_TERTUNDA untuk SEP yang belum bisa terbit (UC-01 6a).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrasi.sep', function (Blueprint $table) {
            $table->comment('SEP VClaim per kunjungan (tiket 2.7). dibuat_oleh = petugas yang mengajukan.');
            $table->uuid('id')->primary();
            $table->foreignUuid('kunjungan_id')->constrained('kunjungan.kunjungan')->restrictOnDelete();
            $table->foreignUuid('episode_id')->constrained('kunjungan.episode')->restrictOnDelete();
            $table->foreignUuid('pasien_id')->constrained('pasien.pasien')->restrictOnDelete();
            $table->text('no_sep')->nullable()->comment('Dari BPJS saat terbit');
            $table->date('tanggal_sep')->comment('Tanggal kunjungan');
            $table->text('jenis_pelayanan')->default('rawat_jalan');
            $table->text('status');
            $table->text('no_rujukan')->nullable()->comment('Data pasien: tidak pernah di log/audit');
            $table->text('asal_rujukan')->nullable();
            $table->date('tanggal_rujukan')->nullable();
            $table->date('berlaku_sampai')->nullable();
            $table->text('ppk_rujukan')->nullable();
            $table->text('diagnosis_kode')->nullable();
            $table->text('diagnosis_nama')->nullable();
            $table->text('catatan')->nullable();
            $table->jsonb('peringatan')->default('[]');
            $table->text('galat_sumber')->nullable();
            $table->text('galat_kode')->nullable();
            $table->text('galat_pesan')->nullable()->comment('Pesan BPJS apa adanya; tidak ditulis ke log aplikasi');
            $table->uuid('kiriman_id')->nullable()->comment('integrasi.kotak_keluar.id');
            $table->text('lingkungan')->nullable();
            $table->text('kunci_idempotensi')->nullable();
            $table->timestampTz('terbit_pada')->nullable();
            $table->text('alasan_batal')->nullable();
            $table->uuid('dibatalkan_oleh')->nullable()->comment('akses.pengguna.id');
            $table->timestampTz('dibatalkan_pada')->nullable();
            $table->kolomStandar();

            $table->index(['kunjungan_id', 'dibuat_pada']);
            $table->index(['status', 'tanggal_sep']);
        });
        DB::statement("ALTER TABLE integrasi.sep ADD CONSTRAINT sep_cek CHECK (jenis_pelayanan IN ('rawat_jalan', 'rawat_inap')
            AND status IN ('menunggu', 'terbit', 'ditolak', 'batal') AND ((status = 'terbit') <= (no_sep IS NOT NULL))
            AND ((status = 'batal') = (dibatalkan_pada IS NOT NULL AND alasan_batal IS NOT NULL))
            AND (asal_rujukan IS NULL OR asal_rujukan IN ('fktp', 'rs')) AND (galat_sumber IS NULL OR galat_sumber IN ('bpjs', 'simrs')))");
        DB::statement("CREATE UNIQUE INDEX sep_aktif_unik ON integrasi.sep (kunjungan_id) WHERE status IN ('menunggu', 'terbit')");
        DB::statement('CREATE UNIQUE INDEX sep_idempotensi_unik ON integrasi.sep (kunjungan_id, kunci_idempotensi) WHERE kunci_idempotensi IS NOT NULL');

        $sekarang = now();
        DB::table('inti.jenis_tugas')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'kode' => 'SEP_TERTUNDA', 'nama' => 'SEP belum terbit',
            'uraian' => 'SEP ditunda atau VClaim tidak menjawab; terbitkan ulang atau tindak lanjuti penolakannya (UC-01 6a).',
            'modul' => 'pendaftaran', 'objek_jenis' => 'sep', 'tautan_layar' => '/pendaftaran/sep?sep={objek_id}', 'tenggat_dari' => 'dibuat',
            'batas_menit' => 240, 'pengingat_menit' => -60, 'teguran_menit' => 0, 'eskalasi_menit' => 240,
            'kritis' => false, 'aktif' => true, 'dibuat_pada' => $sekarang, 'diubah_pada' => $sekarang,
        ]);

        MenuBawaan::pasang();
    }

    public function down(): void
    {
        DB::table('inti.jenis_tugas')->where('kode', 'SEP_TERTUNDA')->delete();
        Schema::dropIfExists('integrasi.sep');
    }
};
