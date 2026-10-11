<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pegawai lengkap, izin praktik, kewenangan klinis (tiket 1.8d; PRD 11.2 sdm, 9.2.6; K5). Tidak ada DELETE: izin
 * praktik dan RKK dibatalkan lewat status, pegawai keluar lewat tanggal_keluar. NIK terenkripsi di aplikasi, keunikan
 * lewat sidik HMAC. Juga kepala unit / ketua KSM dan wajib ganti sandi akun (akun dari pegawai).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sdm.pegawai', function (Blueprint $table) {
            $table->text('nik_enkripsi')->nullable()->comment('PRIBADI: NIK terenkripsi aplikasi; tidak pernah di log/audit');
            $table->text('nik_sidik')->nullable()->unique()->comment('HMAC-SHA256 NIK untuk keunikan, bukan untuk ditampilkan');
            $table->text('jenis_kepegawaian')->nullable()->comment('Isian RS, mis. PNS, PPPK, pegawai BLU, kontrak');
            $table->text('profesi')->nullable();
            $table->foreignUuid('unit_organisasi_id')->nullable()->constrained('inti.unit_organisasi')->restrictOnDelete();
            $table->text('jabatan')->nullable();
            $table->date('tmt')->nullable()->comment('Terhitung mulai tanggal');
            $table->date('tanggal_keluar')->nullable()->comment('Akun nonaktif dan sesi diputus pada tanggal ini (PRD 9.2.6)');
            $table->text('alasan_keluar')->nullable();
            $table->text('ihs_practitioner_id')->nullable()->comment('Diisi integrasi SATUSEHAT (tiket 1.9)');
        });
        DB::statement("ALTER TABLE sdm.pegawai ADD CONSTRAINT pegawai_profesi_cek CHECK (profesi IS NULL OR profesi IN ('dokter', 'dokter_gigi', 'perawat', 'bidan', 'apoteker', 'tenaga_kesehatan_lain', 'non_kesehatan'))");
        DB::statement('ALTER TABLE sdm.pegawai ADD CONSTRAINT pegawai_keluar_cek CHECK ((tanggal_keluar IS NULL) = (alasan_keluar IS NULL))');
        DB::statement('COMMENT ON COLUMN sdm.pegawai.status IS \'aktif | nonaktif (keluar sudah diproses: akun dinonaktifkan)\'');

        Schema::create('sdm.izin_praktik', function (Blueprint $table) {
            $table->comment('STR/SIP/SIK/SPK pegawai (PRD 11.2). Tidak diubah setelah dicatat: perbaikan = batal lalu catat ulang.');
            $table->uuid('id')->primary();
            $table->foreignUuid('pegawai_id')->constrained('sdm.pegawai')->restrictOnDelete();
            $table->text('jenis')->comment('str | sip | sik | spk');
            $table->text('nomor');
            $table->date('terbit');
            $table->date('berlaku_sampai')->nullable()->comment('Kosong = seumur hidup (hanya STR)');
            $table->text('dokumen_jalur')->nullable()->comment('Jalur di disk privat; tidak pernah dikirim ke klien');
            $table->text('dokumen_mime')->nullable();
            $table->integer('dokumen_ukuran')->nullable();
            $table->text('status')->default('aktif');
            $table->text('alasan_batal')->nullable();
            $table->uuid('dibatalkan_oleh')->nullable();
            $table->timestampTz('dibatalkan_pada')->nullable();
            $table->kolomStandar();

            $table->index(['pegawai_id', 'dibuat_pada']);
        });
        DB::statement("ALTER TABLE sdm.izin_praktik ADD CONSTRAINT izin_praktik_jenis_cek CHECK (jenis IN ('str', 'sip', 'sik', 'spk'))");
        DB::statement("ALTER TABLE sdm.izin_praktik ADD CONSTRAINT izin_praktik_status_cek CHECK (status IN ('aktif', 'batal'))");
        DB::statement("ALTER TABLE sdm.izin_praktik ADD CONSTRAINT izin_praktik_masa_cek CHECK ((berlaku_sampai IS NOT NULL OR jenis = 'str') AND (berlaku_sampai IS NULL OR berlaku_sampai >= terbit))");
        DB::statement("CREATE UNIQUE INDEX izin_praktik_nomor_aktif ON sdm.izin_praktik (jenis, nomor) WHERE status = 'aktif'");

        Schema::create('sdm.kewenangan_klinis', function (Blueprint $table) {
            $table->comment('Rincian kewenangan klinis (RKK) minimal: daftar kode tindakan dan masa berlaku (PRD 11.2).');
            $table->uuid('id')->primary();
            $table->foreignUuid('pegawai_id')->constrained('sdm.pegawai')->restrictOnDelete();
            $table->text('kategori');
            $table->jsonb('kode_tindakan')->comment('Kode diisi RS dari referensi resmi; tidak ada daftar bawaan');
            $table->date('berlaku_dari');
            $table->date('berlaku_sampai');
            $table->text('komite');
            $table->text('dasar')->nullable()->comment('Nomor SK penugasan klinis');
            $table->text('status')->default('aktif');
            $table->text('alasan_batal')->nullable();
            $table->uuid('dibatalkan_oleh')->nullable();
            $table->timestampTz('dibatalkan_pada')->nullable();
            $table->kolomStandar();

            $table->index(['pegawai_id', 'dibuat_pada']);
        });
        DB::statement("ALTER TABLE sdm.kewenangan_klinis ADD CONSTRAINT kewenangan_klinis_status_cek CHECK (status IN ('aktif', 'batal'))");
        DB::statement('ALTER TABLE sdm.kewenangan_klinis ADD CONSTRAINT kewenangan_klinis_masa_cek CHECK (berlaku_sampai >= berlaku_dari)');

        Schema::table('inti.unit_organisasi', fn (Blueprint $table) => $table->foreignUuid('kepala_pegawai_id')->nullable()
            ->comment('Kepala unit; dipakai eskalasi tugas (1.7)')->constrained('sdm.pegawai')->restrictOnDelete());
        Schema::table('inti.ksm', fn (Blueprint $table) => $table->foreignUuid('ketua_pegawai_id')->nullable()
            ->constrained('sdm.pegawai')->restrictOnDelete());

        Schema::table('akses.pengguna', function (Blueprint $table) {
            $table->boolean('wajib_ganti_sandi')->default(false)->comment('Akun dari pegawai dengan sandi awal: endpoint lain 403 sampai diganti');
            $table->timestampTz('sandi_diubah_pada')->nullable();
        });

        // Sub-menu sdm.pegawai tersedia, bagian sdm.pegawai.identitas dan izinnya (sensitif tanpa penyetuju bawaan).
        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        Schema::table('akses.pengguna', fn (Blueprint $table) => $table->dropColumn(['wajib_ganti_sandi', 'sandi_diubah_pada']));
        Schema::table('inti.ksm', fn (Blueprint $table) => $table->dropConstrainedForeignId('ketua_pegawai_id'));
        Schema::table('inti.unit_organisasi', fn (Blueprint $table) => $table->dropConstrainedForeignId('kepala_pegawai_id'));
        Schema::dropIfExists('sdm.kewenangan_klinis');
        Schema::dropIfExists('sdm.izin_praktik');
        DB::statement('ALTER TABLE sdm.pegawai DROP CONSTRAINT IF EXISTS pegawai_keluar_cek');
        DB::statement('ALTER TABLE sdm.pegawai DROP CONSTRAINT IF EXISTS pegawai_profesi_cek');
        Schema::table('sdm.pegawai', fn (Blueprint $table) => $table->dropColumn([
            'nik_enkripsi', 'nik_sidik', 'jenis_kepegawaian', 'profesi', 'unit_organisasi_id', 'jabatan', 'tmt', 'tanggal_keluar', 'alasan_keluar', 'ihs_practitioner_id',
        ]));
    }
};
