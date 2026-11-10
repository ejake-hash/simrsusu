<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Status tempat tidur untuk peta TT (tiket 2.27; FR-08-01, FR-08-04, PRD 11.2 `riwayat_status_tempat_tidur`). Status
 * sekarang disimpan di inti.tempat_tidur agar peta dibaca tanpa menelusuri riwayat; setiap perubahan lewat
 * App\Modules\RawatInap\Application\UbahStatusTempatTidur yang menulis keduanya dalam satu transaksi.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE inti.tempat_tidur ADD COLUMN status_saat_ini text NOT NULL DEFAULT 'kosong_siap'");
        DB::statement("ALTER TABLE inti.tempat_tidur ADD CONSTRAINT tempat_tidur_status_cek CHECK (status_saat_ini IN ('terisi', 'kosong_siap', 'dibersihkan', 'dipesan', 'tidak_dipakai', 'rencana_pulang'))");
        DB::statement("COMMENT ON COLUMN inti.tempat_tidur.status_saat_ini IS 'Status peta TT (FR-08-01); hanya diubah UbahStatusTempatTidur bersama riwayatnya. TT ditutup tampil tidak_dipakai tanpa mengubah kolom ini'");
        DB::statement('ALTER TABLE inti.tempat_tidur ADD COLUMN status_sejak timestamptz');
        // Baris lama: status awal kosong_siap sejak TT dibuat.
        DB::statement('UPDATE inti.tempat_tidur SET status_sejak = dibuat_pada');
        DB::statement('ALTER TABLE inti.tempat_tidur ALTER COLUMN status_sejak SET DEFAULT now(), ALTER COLUMN status_sejak SET NOT NULL');
        DB::statement("COMMENT ON COLUMN inti.tempat_tidur.status_sejak IS 'Jam server status_saat_ini mulai berlaku'");

        Schema::create('kunjungan.riwayat_status_tempat_tidur', function (Blueprint $table) {
            $table->comment('Riwayat perubahan status TT (FR-08-01, FR-08-04): pelaku dan sumber kejadian. Hanya ditambah; tanpa data pasien.');
            $table->uuid('id')->primary();
            $table->foreignUuid('tempat_tidur_id')->constrained('inti.tempat_tidur')->restrictOnDelete();
            $table->text('status_sebelum')->nullable()->comment('Status sebelum kejadian; null = status awal');
            $table->text('status');
            $table->timestampTz('terjadi_pada', 6)->comment('Jam server');
            $table->foreignUuid('oleh')->nullable()->comment('akses.pengguna.id pelaku; null = kejadian sistem')
                ->constrained('akses.pengguna')->restrictOnDelete();
            $table->text('sumber_kejadian')->comment('petugas (2.27) | alokasi (2.28) | penempatan, pindah_ruang, pulang (2.29)');
            $table->timestampTz('dibuat_pada')->useCurrent();
        });
        DB::statement('CREATE INDEX riwayat_status_tempat_tidur_tt_waktu ON kunjungan.riwayat_status_tempat_tidur (tempat_tidur_id, terjadi_pada DESC)');
        DB::statement("ALTER TABLE kunjungan.riwayat_status_tempat_tidur ADD CONSTRAINT riwayat_status_tempat_tidur_cek CHECK (
            status IN ('terisi', 'kosong_siap', 'dibersihkan', 'dipesan', 'tidak_dipakai', 'rencana_pulang')
            AND (status_sebelum IS NULL OR status_sebelum IN ('terisi', 'kosong_siap', 'dibersihkan', 'dipesan', 'tidak_dipakai', 'rencana_pulang'))
            AND sumber_kejadian IN ('petugas', 'alokasi', 'penempatan', 'pindah_ruang', 'pulang'))");
        // Hanya ditambah (pola kunjungan.triase, kunjungan.perpindahan_pos): kejadian yang sudah tercatat tidak bisa diubah simrs_app.
        DB::statement('REVOKE UPDATE ON kunjungan.riwayat_status_tempat_tidur FROM simrs_app');
    }

    public function down(): void
    {
        Schema::dropIfExists('kunjungan.riwayat_status_tempat_tidur');
        DB::statement('ALTER TABLE inti.tempat_tidur DROP CONSTRAINT IF EXISTS tempat_tidur_status_cek');
        DB::statement('ALTER TABLE inti.tempat_tidur DROP COLUMN IF EXISTS status_sejak');
        DB::statement('ALTER TABLE inti.tempat_tidur DROP COLUMN IF EXISTS status_saat_ini');
    }
};
