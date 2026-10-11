<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persetujuan dan permintaan akses (tiket 1.2d; PRD 9.2.3–9.2.4, 9.3, 11.2; keputusan K3, K4, K13).
 * Tidak ada DELETE: status menunggu → disetujui | ditolak | kedaluwarsa | dibatalkan (persetujuan) dan
 * menunggu → ditindaklanjuti | ditolak (permintaan). Nomor manusiawi dari sequence (dasar pemberian).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('akses.izin', function (Blueprint $table) {
            $table->foreignUuid('peran_penyetuju_id')->nullable()->comment('Pemilik data izin sensitif (K3): pemegang peran ini menyetujui pemberiannya')
                ->constrained('akses.peran')->restrictOnDelete();
        });

        DB::statement('CREATE SEQUENCE akses.persetujuan_akses_nomor_seq');
        DB::statement('CREATE SEQUENCE akses.permintaan_akses_nomor_seq');

        Schema::create('akses.persetujuan_akses', function (Blueprint $table) {
            $table->comment('Pemberian akses berisiko yang menunggu/diputuskan penyetuju (PRD 9.2.4). Satu baris per peran penyetuju; satu pengajuan = satu grup.');
            $table->uuid('id')->primary();
            $table->text('nomor')->unique()->comment('PSA-<tahun>-<urut>, dipakai sebagai dasar pemberian');
            $table->uuid('grup_id')->comment('Semua baris satu pengajuan; diterapkan saat seluruh grup disetujui');
            $table->text('jenis')->comment('izin_sensitif | peran_superadmin | pengecualian');
            $table->text('objek_jenis')->comment('izin_peran | penugasan | pengakhiran_penugasan | izin_khusus | penyetuju_izin');
            $table->jsonb('objek')->comment('Isi perubahan yang diterapkan bila disetujui (tanpa data rahasia)');
            $table->foreignUuid('peran_id')->nullable()->constrained('akses.peran')->restrictOnDelete();
            $table->foreignUuid('izin_id')->nullable()->constrained('akses.izin')->restrictOnDelete();
            $table->foreignUuid('sasaran_pengguna_id')->nullable()->comment('Akun penerima akses (penugasan, pengakhiran, izin khusus)')->constrained('akses.pengguna')->restrictOnDelete();
            $table->foreignUuid('penyetuju_peran_id')->constrained('akses.peran')->restrictOnDelete();
            $table->text('status')->default('menunggu');
            $table->foreignUuid('diajukan_oleh')->constrained('akses.pengguna')->restrictOnDelete();
            $table->text('alasan')->nullable();
            $table->text('dasar');
            $table->timestampTz('kedaluwarsa_pada');
            $table->uuid('diputuskan_oleh')->nullable()->comment('akses.pengguna.id; kosong = diputuskan sistem (kedaluwarsa, tidak bisa diterapkan)');
            $table->timestampTz('diputuskan_pada')->nullable();
            $table->text('catatan_keputusan')->nullable();
            $table->timestampTz('diterapkan_pada')->nullable();
            $table->kolomStandar();

            $table->index(['status', 'penyetuju_peran_id']);
            $table->index(['diajukan_oleh', 'dibuat_pada']);
            $table->index('grup_id');
            $table->index(['sasaran_pengguna_id', 'status']);
            $table->index(['peran_id', 'izin_id', 'status']);
        });
        DB::statement("ALTER TABLE akses.persetujuan_akses ADD CONSTRAINT persetujuan_akses_jenis_cek CHECK (jenis IN ('izin_sensitif', 'peran_superadmin', 'pengecualian'))");
        DB::statement("ALTER TABLE akses.persetujuan_akses ADD CONSTRAINT persetujuan_akses_objek_cek CHECK (objek_jenis IN ('izin_peran', 'penugasan', 'pengakhiran_penugasan', 'izin_khusus', 'penyetuju_izin'))");
        DB::statement("ALTER TABLE akses.persetujuan_akses ADD CONSTRAINT persetujuan_akses_status_cek CHECK (status IN ('menunggu', 'disetujui', 'ditolak', 'kedaluwarsa', 'dibatalkan'))");

        Schema::create('akses.permintaan_akses', function (Blueprint $table) {
            $table->comment('Permintaan akses dari pengguna (layar Akses ditolak), diteruskan ke pemberi kewenangan (PRD 9.3).');
            $table->uuid('id')->primary();
            $table->text('nomor')->unique()->comment('PMA-<tahun>-<urut>, dipakai sebagai dasar pemberian');
            $table->foreignUuid('pengguna_id')->constrained('akses.pengguna')->restrictOnDelete();
            $table->foreignUuid('menu_id')->nullable()->constrained('akses.menu')->restrictOnDelete();
            $table->foreignUuid('izin_id')->nullable()->constrained('akses.izin')->restrictOnDelete();
            $table->text('alasan');
            $table->text('status')->default('menunggu');
            $table->uuid('diputuskan_oleh')->nullable();
            $table->timestampTz('diputuskan_pada')->nullable();
            $table->text('catatan_keputusan')->nullable();
            $table->kolomStandar();

            $table->index(['status', 'dibuat_pada']);
            $table->index(['pengguna_id', 'dibuat_pada']);
        });
        DB::statement("ALTER TABLE akses.permintaan_akses ADD CONSTRAINT permintaan_akses_status_cek CHECK (status IN ('menunggu', 'ditindaklanjuti', 'ditolak'))");
        DB::statement('ALTER TABLE akses.permintaan_akses ADD CONSTRAINT permintaan_akses_sasaran_cek CHECK ((menu_id IS NULL) <> (izin_id IS NULL))');

        // Sub-menu pengaturan.persetujuan dan data awal K13 (penyetuju izin pengelola akses = superadmin).
        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        Schema::dropIfExists('akses.permintaan_akses');
        Schema::dropIfExists('akses.persetujuan_akses');
        DB::statement('DROP SEQUENCE IF EXISTS akses.permintaan_akses_nomor_seq');
        DB::statement('DROP SEQUENCE IF EXISTS akses.persetujuan_akses_nomor_seq');
        Schema::table('akses.izin', fn (Blueprint $table) => $table->dropConstrainedForeignId('peran_penyetuju_id'));
        app(CacheHakAkses::class)->lupakanSemua();
    }
};
