<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Tanda tangan elektronik T1 (tiket 1.6; PRD 7.57.1–7.57.2, 11.2 ttd.*; K10). Kunci privat Ed25519 hanya tersimpan
 * terbungkus kunci turunan PIN; PIN hanya hash Argon2id. Tanda tangan tidak pernah diubah (simrs_app tanpa UPDATE di
 * ttd.tanda_tangan); kredensial yang direset dicabut, tidak dihapus, agar tanda tangan lama tetap terverifikasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ttd.kredensial', function (Blueprint $table) {
            $table->comment('Kredensial tanda tangan per akun (PRD 11.2). Skema ttd tidak dibaca simrs_analitik.');
            $table->uuid('id')->primary();
            $table->foreignUuid('pengguna_id')->constrained('akses.pengguna')->restrictOnDelete();
            $table->text('tingkat')->default('internal')->comment('internal (T1) | psre (T2)');
            $table->text('penyedia')->default('internal')->comment('internal | privy | peruri | vida | bsre | simulator');
            $table->text('id_penyedia')->nullable();
            $table->text('serial_sertifikat')->nullable();
            $table->timestampTz('berlaku_sampai')->nullable();
            $table->text('kunci_publik')->nullable()->comment('Ed25519, base64');
            $table->text('kunci_privat_terbungkus')->nullable()->comment('RAHASIA: XChaCha20-Poly1305 dengan kunci turunan PIN (Argon2id); base64 nonce||sandi');
            $table->text('garam')->nullable()->comment('Garam Argon2id per kredensial, base64');
            $table->jsonb('parameter_kdf')->nullable()->comment('opslimit, memlimit, alg Argon2id saat dibungkus');
            $table->text('pin_hash')->nullable()->comment('RAHASIA: Argon2id; PIN asli tidak pernah disimpan');
            $table->integer('percobaan_gagal')->default(0);
            $table->timestampTz('terkunci_sampai')->nullable();
            $table->text('status')->default('aktif');
            $table->timestampTz('diaktifkan_pada')->nullable();
            $table->timestampTz('dicabut_pada')->nullable();
            $table->uuid('dicabut_oleh')->nullable()->comment('akses.pengguna.id penyetuju reset');
            $table->text('alasan_cabut')->nullable()->comment('Mis. nomor persetujuan reset');
            $table->kolomStandar();
        });
        DB::statement("ALTER TABLE ttd.kredensial ADD CONSTRAINT kredensial_status_cek CHECK (status IN ('aktif', 'dicabut'))");
        DB::statement("ALTER TABLE ttd.kredensial ADD CONSTRAINT kredensial_tingkat_cek CHECK (tingkat IN ('internal', 'psre'))");
        DB::statement("CREATE UNIQUE INDEX kredensial_aktif_unik ON ttd.kredensial (pengguna_id, tingkat) WHERE status = 'aktif'");

        Schema::create('ttd.tanda_tangan', function (Blueprint $table) {
            $table->comment('Satu baris per objek per penanda tangan (PRD 7.57.2). Tidak pernah diubah atau dihapus.');
            $table->uuid('id')->primary();
            $table->text('objek_jenis');
            $table->uuid('objek_id');
            $table->text('penanda_tangan_jenis')->default('pengguna')->comment('pengguna | pasien | wali | saksi (P: fase 2)');
            $table->foreignUuid('pengguna_id')->nullable()->constrained('akses.pengguna')->restrictOnDelete();
            $table->foreignUuid('kredensial_id')->nullable()->constrained('ttd.kredensial')->restrictOnDelete();
            $table->text('tingkat')->comment('T1 | T2 | S | P');
            $table->text('metode')->comment('pin | psre | tablet_otp | segel');
            $table->char('hash_isi', 64)->comment('SHA-256 hex isi kanonik');
            $table->text('nilai_tanda_tangan')->comment('base64');
            $table->text('serial_sertifikat')->nullable();
            $table->timestampTz('cap_waktu', 6)->comment('Waktu server (NTP); ikut ditandatangani');
            $table->jsonb('bukti')->default('{}')->comment('Mis. cara buka kunci (pin/sesi), id sesi tersamar; tanpa PIN');
            $table->timestampTz('ditandatangani_pada', 6)->useCurrent();

            $table->unique(['objek_jenis', 'objek_id', 'pengguna_id']);
            $table->index(['pengguna_id', 'ditandatangani_pada']);
        });
        DB::statement("ALTER TABLE ttd.tanda_tangan ADD CONSTRAINT tanda_tangan_tingkat_cek CHECK (tingkat IN ('T1', 'T2', 'S', 'P'))");
        // Bukti tidak boleh berubah sesudah dibuat (PRD 7.57.2 huruf c).
        DB::statement('REVOKE UPDATE ON ttd.tanda_tangan FROM simrs_app');

        Schema::create('ttd.permintaan', function (Blueprint $table) {
            $table->comment('Permintaan tanda tangan asinkron ke PSrE (T2). Fase 1 hanya dipakai SimulatorTte (K10).');
            $table->uuid('id')->primary();
            $table->text('objek_jenis');
            $table->uuid('objek_id');
            $table->foreignUuid('penanda_tangan_id')->constrained('akses.pengguna')->restrictOnDelete();
            $table->text('tingkat')->default('T2');
            $table->text('penyedia');
            $table->text('status')->default('menunggu');
            $table->text('id_transaksi_penyedia')->nullable()->unique();
            $table->char('hash_isi', 64);
            $table->timestampTz('selesai_pada')->nullable();
            $table->decimal('biaya', 12, 2)->nullable();
            $table->kolomStandar();

            $table->index(['status', 'dibuat_pada']);
        });
        DB::statement("ALTER TABLE ttd.permintaan ADD CONSTRAINT permintaan_status_cek CHECK (status IN ('menunggu', 'terkirim', 'ditandatangani', 'ditolak', 'kedaluwarsa', 'gagal'))");

        Schema::create('ttd.aturan_tingkat', function (Blueprint $table) {
            $table->comment('Tingkat tanda tangan per jenis dokumen (FR-57-05), data induk RS. Fase 1 hanya T1 aktif.');
            $table->uuid('id')->primary();
            $table->text('jenis_dokumen')->unique();
            $table->text('tingkat');
            $table->text('keterangan')->nullable();
            $table->boolean('aktif')->default(true);
            $table->kolomStandar();
        });
        DB::statement("ALTER TABLE ttd.aturan_tingkat ADD CONSTRAINT aturan_tingkat_cek CHECK (tingkat IN ('T1', 'T2', 'S', 'P'))");
        DB::table('ttd.aturan_tingkat')->insert([
            'id' => (string) Str::uuid7(), 'jenis_dokumen' => 'persetujuan_akses', 'tingkat' => 'T1',
            'keterangan' => 'Keputusan persetujuan akses (tiket 1.2d); data awal, diubah RS',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ttd.aturan_tingkat');
        Schema::dropIfExists('ttd.permintaan');
        Schema::dropIfExists('ttd.tanda_tangan');
        Schema::dropIfExists('ttd.kredensial');
    }
};
