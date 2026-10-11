<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menu, peran, izin, dan penugasan (PRD 9.2, 11.2, tiket 1.2a). Tidak ada DELETE: penugasan dan
 * izin khusus diakhiri lewat status = 'batal' (alasan_batal, dibatalkan_oleh, dibatalkan_pada) atau
 * berlaku_sampai. Kolom personalisasi tampilan peran_menu (urutan/label khusus, PRD 9.3) menyusul.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('akses.menu', function (Blueprint $table) {
            $table->comment('Pohon menu sidebar dan bagian halaman (PRD 9.1, 9.2.1). Diisi MenuBawaan, diubah dari layar 9.3.');
            $table->uuid('id')->primary();
            $table->uuid('induk_id')->nullable();
            $table->text('tingkat')->comment('grup | menu | sub_menu | bagian');
            $table->text('kode')->unique()->comment('Kode stabil, mis. igd.papan; dipakai rute frontend dan kode izin');
            $table->text('label')->nullable()->comment('Kosong hanya untuk grup tanpa judul (Beranda)');
            $table->text('ikon')->nullable()->comment('Nama ikon design-system, bukan URL');
            $table->text('rute')->nullable();
            $table->smallInteger('modul_no')->nullable()->comment('Modul katalog PRD Bagian 6; status aktif mengikuti inti.modul_aktif');
            $table->smallInteger('fase')->nullable()->comment('Fase rencana PRD 16, ditampilkan di halaman Segera hadir');
            $table->integer('urutan')->default(0);
            $table->boolean('tersedia')->default(false)->comment('Halaman sudah dibangun di versi ini (diisi pengembang lewat MenuBawaan)');
            $table->boolean('selalu_tampil')->default(false)->comment('Terbuka untuk semua akun aktif tanpa penugasan (Beranda, PRD 9.2)');
            $table->boolean('ikut_induk')->default(false)->comment('Bagian yang ikut diberikan bersama sub-menunya (PRD 9.2.3)');
            $table->boolean('aktif')->default(true)->comment('false = dinonaktifkan admin; tidak tampil untuk siapa pun');
            $table->kolomStandar();

            $table->index('induk_id');
        });
        // Kunci tamu ke diri sendiri setelah kunci utama ada.
        Schema::table('akses.menu', fn (Blueprint $table) => $table->foreign('induk_id')->references('id')->on('akses.menu')->restrictOnDelete());
        DB::statement("ALTER TABLE akses.menu ADD CONSTRAINT menu_tingkat_cek CHECK (tingkat IN ('grup', 'menu', 'sub_menu', 'bagian'))");
        DB::statement("ALTER TABLE akses.menu ADD CONSTRAINT menu_induk_cek CHECK ((tingkat = 'grup') = (induk_id IS NULL))");

        Schema::create('akses.peran', function (Blueprint $table) {
            $table->comment('Paket sub-menu, bagian, dan aksi yang ditugaskan ke akun (PRD 9.2.3).');
            $table->uuid('id')->primary();
            $table->text('kode')->unique();
            $table->text('nama');
            $table->text('uraian')->nullable();
            $table->boolean('bawaan_sistem')->default(false)->comment('Dibuat produk (mis. superadmin); tidak bisa dihapus dari layar');
            $table->boolean('wajib_mfa')->default(false)->comment('Pemegang peran ini wajib MFA walau dari jaringan RS (PRD 10.3.2)');
            $table->text('status')->default('aktif')->comment('aktif | nonaktif');
            $table->kolomStandar();
        });
        DB::statement("ALTER TABLE akses.peran ADD CONSTRAINT peran_status_cek CHECK (status IN ('aktif', 'nonaktif'))");
        // PRD 10.3.2: MFA superadmin tidak bisa dibebaskan admin.
        DB::statement("ALTER TABLE akses.peran ADD CONSTRAINT peran_superadmin_wajib_mfa CHECK (kode <> 'superadmin' OR wajib_mfa)");

        Schema::create('akses.izin', function (Blueprint $table) {
            $table->comment('Aksi pada sub-menu atau bagian (lapis 2, PRD 9.2.2). Kode <menu>.<sub_menu>.<aksi>.');
            $table->uuid('id')->primary();
            $table->text('kode')->unique();
            $table->foreignUuid('menu_id')->comment('Sub-menu atau bagian tempat aksi berada')->constrained('akses.menu')->restrictOnDelete();
            $table->text('jenis_aksi');
            $table->text('uraian')->nullable();
            $table->boolean('sensitif')->default(false)->comment('Pemberian butuh persetujuan pemilik data; pemegangnya wajib MFA');
            $table->boolean('butuh_mfa')->default(false)->comment('Lapis 5: aksi ini hanya dengan MFA aktif (tiket 1.5)');
            $table->boolean('butuh_kewenangan_klinis')->default(false)->comment('Lapis 5: SIP/SPK/RKK (tiket 1.5)');
            $table->kolomStandar();

            $table->index('menu_id');
        });
        DB::statement("ALTER TABLE akses.izin ADD CONSTRAINT izin_jenis_aksi_cek CHECK (jenis_aksi IN
            ('lihat', 'input', 'perbaiki', 'sahkan', 'verifikasi', 'setujui', 'batal', 'ekspor', 'cetak', 'lihat_rupiah'))");

        Schema::create('akses.peran_menu', function (Blueprint $table) {
            $table->comment('Sub-menu dan bagian yang diberikan peran (lapis 1).');
            $table->uuid('id')->primary();
            $table->foreignUuid('peran_id')->constrained('akses.peran')->restrictOnDelete();
            $table->foreignUuid('menu_id')->constrained('akses.menu')->restrictOnDelete();
            $table->kolomStandar();

            $table->unique(['peran_id', 'menu_id']);
        });

        Schema::create('akses.peran_izin', function (Blueprint $table) {
            $table->comment('Aksi yang diberikan peran (lapis 2).');
            $table->uuid('id')->primary();
            $table->foreignUuid('peran_id')->constrained('akses.peran')->restrictOnDelete();
            $table->foreignUuid('izin_id')->constrained('akses.izin')->restrictOnDelete();
            $table->uuid('disetujui_oleh')->nullable()->comment('Pemilik data, untuk izin sensitif (persetujuan menyusul)');
            $table->timestampTz('disetujui_pada')->nullable();
            $table->kolomStandar();

            $table->unique(['peran_id', 'izin_id']);
        });

        Schema::create('akses.pengguna_peran', function (Blueprint $table) {
            $table->comment('Penugasan peran ke akun dengan cakupan data, masa berlaku, dan dasar pemberian (FR-09-04).');
            $table->uuid('id')->primary();
            $table->foreignUuid('pengguna_id')->constrained('akses.pengguna')->restrictOnDelete();
            $table->foreignUuid('peran_id')->constrained('akses.peran')->restrictOnDelete();
            $this->kolomPemberian($table);

            $table->index(['pengguna_id', 'status']);
            $table->index('peran_id');
        });
        $this->cekPemberian('pengguna_peran');

        Schema::create('akses.pengguna_izin_khusus', function (Blueprint $table) {
            $table->comment('Pemberian (tambah) atau pencabutan (cabut) per akun atas sub-menu, bagian, atau aksi. Cabut selalu menang.');
            $table->uuid('id')->primary();
            $table->foreignUuid('pengguna_id')->constrained('akses.pengguna')->restrictOnDelete();
            $table->foreignUuid('menu_id')->nullable()->constrained('akses.menu')->restrictOnDelete();
            $table->foreignUuid('izin_id')->nullable()->constrained('akses.izin')->restrictOnDelete();
            $table->text('efek')->comment('tambah | cabut');
            $this->kolomPemberian($table);

            $table->index(['pengguna_id', 'status']);
        });
        $this->cekPemberian('pengguna_izin_khusus');
        DB::statement("ALTER TABLE akses.pengguna_izin_khusus ADD CONSTRAINT pengguna_izin_khusus_efek_cek CHECK (efek IN ('tambah', 'cabut'))");
        DB::statement('ALTER TABLE akses.pengguna_izin_khusus ADD CONSTRAINT pengguna_izin_khusus_sasaran_cek CHECK ((menu_id IS NULL) <> (izin_id IS NULL))');
    }

    public function down(): void
    {
        foreach (['pengguna_izin_khusus', 'pengguna_peran', 'peran_izin', 'peran_menu', 'izin', 'peran', 'menu'] as $tabel) {
            Schema::dropIfExists("akses.$tabel");
        }
    }

    /** Cakupan (lapis 3), masa berlaku, dasar pemberian, dan pembatalan. */
    private function kolomPemberian(Blueprint $table): void
    {
        $table->text('cakupan_jenis')->default('rs')->comment('rs | unit | ruang | poli | ksm | depo | pasien_dirawat');
        $table->uuid('cakupan_id')->nullable()->comment('Kosong bila cakupan_jenis = rs atau pasien_dirawat');
        // Presisi mikrodetik: presisi 0 membulatkan ke detik terdekat, sehingga penugasan baru bisa
        // tercatat mulai berlaku setengah detik di masa depan. Aplikasi mengisi berlaku_dari sendiri.
        $table->timestampTz('berlaku_dari', 6)->useCurrent();
        $table->timestampTz('berlaku_sampai', 6)->nullable()->comment('Kosong = tanpa batas; lewat = berhenti tanpa tindakan admin (PRD 9.2.6)');
        $table->text('dasar_pemberian')->comment('Nomor SK, surat tugas, atau persetujuan (PRD 9.2)');
        $table->text('alasan')->nullable();
        $table->uuid('diberikan_oleh')->nullable()->comment('akses.pengguna.id; kosong = konsol/sistem');
        $table->uuid('disetujui_oleh')->nullable();
        $table->text('status')->default('aktif')->comment('aktif | batal');
        $table->text('alasan_batal')->nullable();
        $table->uuid('dibatalkan_oleh')->nullable();
        $table->timestampTz('dibatalkan_pada')->nullable();
        $table->kolomStandar();
    }

    private function cekPemberian(string $tabel): void
    {
        DB::statement("ALTER TABLE akses.$tabel ADD CONSTRAINT {$tabel}_status_cek CHECK (status IN ('aktif', 'batal'))");
        DB::statement("ALTER TABLE akses.$tabel ADD CONSTRAINT {$tabel}_cakupan_cek CHECK
            (cakupan_jenis IN ('rs', 'unit', 'ruang', 'poli', 'ksm', 'depo', 'pasien_dirawat')
             AND (cakupan_jenis IN ('rs', 'pasien_dirawat')) = (cakupan_id IS NULL))");
        DB::statement("ALTER TABLE akses.$tabel ADD CONSTRAINT {$tabel}_masa_cek CHECK (berlaku_sampai IS NULL OR berlaku_sampai > berlaku_dari)");
    }
};
