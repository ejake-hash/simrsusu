<?php

use App\Modules\Akses\Application\CacheHakAkses;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lapis 4 hak akses (tiket 1.5; PRD 9.2.2, 11.2 label_sensitivitas/pengguna_label; kontrak v0.14.0). Label data induk
 * dengan peran penyetuju (pejabat PDP) yang ditetapkan RS; izin label per akun selalu lewat persetujuan, berbatas waktu,
 * dan tidak dihapus (pencabutan = status batal).
 */
return new class extends Migration
{
    /** Data awal PRD 11.2. butuh_relasi_perawatan true untuk semua label (keputusan pemilik 12 Okt 2026). */
    private const LABEL = [
        ['hiv', 'HIV', 'Status dan data HIV pasien'],
        ['psikiatri', 'Psikiatri', 'Catatan psikiatri'],
        ['kekerasan_seksual', 'Kekerasan seksual', 'Data pasien korban kekerasan seksual'],
        ['genetik', 'Genetik', 'Data genetik'],
        ['privasi_tinggi', 'Privasi tinggi', 'Pasien privasi tinggi (pejabat atau figur publik)'],
    ];

    public function up(): void
    {
        Schema::create('akses.label_sensitivitas', function (Blueprint $table) {
            $table->comment('Label sensitivitas data (lapis 4, PRD 9.2.2). Penyetuju = pejabat PDP yang ditetapkan RS (K13).');
            $table->uuid('id')->primary();
            $table->text('kode')->unique();
            $table->text('nama');
            $table->text('uraian')->nullable();
            $table->boolean('butuh_relasi_perawatan')->default(true)->comment('true = selain izin label, akun harus merawat pasiennya (fase 2)');
            $table->foreignUuid('peran_penyetuju_id')->nullable()->comment('Kosong = label belum bisa diberikan')->constrained('akses.peran')->restrictOnDelete();
            $table->boolean('aktif')->default(true);
            $table->kolomStandar();
        });

        Schema::create('akses.pengguna_label', function (Blueprint $table) {
            $table->comment('Izin label per akun (PRD 11.2). Dibuat saat diajukan (menunggu), aktif saat disetujui pejabat PDP.');
            $table->uuid('id')->primary();
            $table->foreignUuid('pengguna_id')->constrained('akses.pengguna')->restrictOnDelete();
            $table->foreignUuid('label_sensitivitas_id')->constrained('akses.label_sensitivitas')->restrictOnDelete();
            $table->foreignUuid('persetujuan_akses_id')->constrained('akses.persetujuan_akses')->restrictOnDelete();
            $table->text('status')->default('menunggu')->comment('menunggu | aktif | batal; ditolak dihitung dari persetujuannya');
            $table->timestampTz('berlaku_dari')->nullable()->comment('Saat disetujui');
            $table->timestampTz('berlaku_sampai');
            $table->text('dasar_pemberian');
            $table->text('alasan');
            $table->uuid('diberikan_oleh');
            $table->uuid('disetujui_oleh')->nullable()->comment('Pejabat PDP');
            $table->text('alasan_batal')->nullable();
            $table->uuid('dibatalkan_oleh')->nullable();
            $table->timestampTz('dibatalkan_pada')->nullable();
            $table->kolomStandar();

            $table->index(['pengguna_id', 'dibuat_pada', 'id']);
            $table->index('persetujuan_akses_id');
        });
        DB::statement("ALTER TABLE akses.pengguna_label ADD CONSTRAINT pengguna_label_status_cek CHECK (status IN ('menunggu', 'aktif', 'batal'))");
        DB::statement("ALTER TABLE akses.pengguna_label ADD CONSTRAINT pengguna_label_aktif_cek CHECK (status <> 'aktif' OR (berlaku_dari IS NOT NULL AND disetujui_oleh IS NOT NULL))");

        DB::statement('ALTER TABLE akses.persetujuan_akses DROP CONSTRAINT persetujuan_akses_jenis_cek');
        DB::statement("ALTER TABLE akses.persetujuan_akses ADD CONSTRAINT persetujuan_akses_jenis_cek CHECK (jenis IN ('izin_sensitif', 'peran_superadmin', 'pengecualian', 'reset_ttd', 'izin_label'))");
        DB::statement('ALTER TABLE akses.persetujuan_akses DROP CONSTRAINT persetujuan_akses_objek_cek');
        DB::statement("ALTER TABLE akses.persetujuan_akses ADD CONSTRAINT persetujuan_akses_objek_cek CHECK (objek_jenis IN ('izin_peran', 'penugasan', 'pengakhiran_penugasan', 'izin_khusus', 'penyetuju_izin', 'reset_ttd', 'izin_label', 'penyetuju_label'))");

        DB::table('akses.label_sensitivitas')->insert(array_map(fn (array $l) => [
            'id' => (string) Str::uuid7(), 'kode' => $l[0], 'nama' => $l[1], 'uraian' => $l[2], 'butuh_relasi_perawatan' => true,
        ], self::LABEL));

        // Bentuk HakAkses di cache bertambah (label dan syarat lapis 5).
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE akses.persetujuan_akses DROP CONSTRAINT persetujuan_akses_objek_cek');
        DB::statement("ALTER TABLE akses.persetujuan_akses ADD CONSTRAINT persetujuan_akses_objek_cek CHECK (objek_jenis IN ('izin_peran', 'penugasan', 'pengakhiran_penugasan', 'izin_khusus', 'penyetuju_izin', 'reset_ttd'))");
        DB::statement('ALTER TABLE akses.persetujuan_akses DROP CONSTRAINT persetujuan_akses_jenis_cek');
        DB::statement("ALTER TABLE akses.persetujuan_akses ADD CONSTRAINT persetujuan_akses_jenis_cek CHECK (jenis IN ('izin_sensitif', 'peran_superadmin', 'pengecualian', 'reset_ttd'))");
        Schema::dropIfExists('akses.pengguna_label');
        Schema::dropIfExists('akses.label_sensitivitas');
    }
};
