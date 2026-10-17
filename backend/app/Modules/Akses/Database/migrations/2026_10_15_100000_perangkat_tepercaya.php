<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Perangkat tepercaya (tiket 1.13; PRD 10.3.2, 14.2; ADR 0002): MFA sekali per perangkat per masa percaya. Token acak
 * hanya disimpan sebagai hash; pencabutan mengisi dicabut_pada (tidak dihapus). Kebijakan instalasi satu baris, masa
 * percaya per peran, sub-menu Pengaturan → Keamanan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('akses.perangkat_tepercaya', function (Blueprint $table) {
            $table->comment('Perangkat tepercaya per akun (tiket 1.13). Token di cookie HttpOnly; di sini hanya hash SHA-256.');
            $table->uuid('id')->primary();
            $table->foreignUuid('pengguna_id')->constrained('akses.pengguna')->restrictOnDelete();
            $table->text('token_hash')->unique()->comment('SHA-256 heksadesimal token; token tidak pernah disimpan');
            $table->text('nama_perangkat')->comment('Dari user-agent, mis. "Chrome di Windows"; user-agent mentah tidak disimpan');
            $table->timestampTz('berlaku_sampai')->comment('Saat dipercaya + masa percaya efektif saat itu; kebijakan yang turun dihitung saat dipakai');
            $table->timestampTz('terakhir_dipakai_pada')->nullable()->comment('Terakhir MFA dilewati karena perangkat ini');
            $table->timestampTz('dicabut_pada')->nullable();
            $table->uuid('dicabut_oleh')->nullable()->comment('akses.pengguna.id; null = belum dicabut');
            $table->text('alasan_cabut')->nullable();
            $table->text('keterangan_cabut')->nullable()->comment('Teks alasan admin (dicabut_admin)');
            $table->kolomStandar();

            $table->index(['pengguna_id', 'dibuat_pada', 'id']);
        });
        DB::statement("ALTER TABLE akses.perangkat_tepercaya ADD CONSTRAINT perangkat_tepercaya_alasan_cek CHECK (alasan_cabut IN ('dicabut_pemilik', 'dicabut_admin', 'sandi_diganti', 'sandi_direset', 'mfa_direset', 'akun_nonaktif'))");
        DB::statement('ALTER TABLE akses.perangkat_tepercaya ADD CONSTRAINT perangkat_tepercaya_cabut_cek CHECK ((dicabut_pada IS NULL) = (alasan_cabut IS NULL))');

        Schema::table('akses.peran', function (Blueprint $table) {
            $table->smallInteger('masa_percaya_hari')->nullable()->comment('Perangkat tepercaya (1.13); null = ikut akses.kebijakan_keamanan');
        });
        DB::statement('ALTER TABLE akses.peran ADD CONSTRAINT peran_masa_percaya_cek CHECK (masa_percaya_hari IS NULL OR masa_percaya_hari >= 0)');

        Schema::create('akses.kebijakan_keamanan', function (Blueprint $table) {
            $table->comment('Kebijakan keamanan instalasi, satu baris (Pengaturan → Keamanan, tiket 1.13).');
            $table->uuid('id')->primary();
            $table->boolean('tunggal')->default(true)->unique()->comment('Menjaga satu baris');
            $table->smallInteger('perangkat_tepercaya_hari')->default(30)->comment('0 = perangkat tepercaya mati');
            $table->kolomStandar();
        });
        DB::statement('ALTER TABLE akses.kebijakan_keamanan ADD CONSTRAINT kebijakan_keamanan_tunggal_cek CHECK (tunggal)');
        DB::statement('ALTER TABLE akses.kebijakan_keamanan ADD CONSTRAINT kebijakan_keamanan_hari_cek CHECK (perangkat_tepercaya_hari >= 0)');
        DB::table('akses.kebijakan_keamanan')->insert(['id' => (string) Str::uuid7(), 'perangkat_tepercaya_hari' => 30]);

        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        Schema::drop('akses.kebijakan_keamanan');
        DB::statement('ALTER TABLE akses.peran DROP CONSTRAINT peran_masa_percaya_cek');
        Schema::table('akses.peran', fn (Blueprint $table) => $table->dropColumn('masa_percaya_hari'));
        Schema::drop('akses.perangkat_tepercaya');
    }
};
