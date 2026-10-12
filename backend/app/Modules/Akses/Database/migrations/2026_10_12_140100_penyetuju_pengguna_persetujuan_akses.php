<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Persetujuan dengan penyetuju orang tertentu (tiket 1.6, kontrak v0.8.0): reset TTE disetujui atasan langsung pemilik,
 * bukan pemegang peran. Tepat satu dari penyetuju_peran_id / penyetuju_pengguna_id terisi. Jenis/objek `reset_ttd`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE akses.persetujuan_akses ADD COLUMN penyetuju_pengguna_id uuid REFERENCES akses.pengguna (id) ON DELETE RESTRICT');
        DB::statement("COMMENT ON COLUMN akses.persetujuan_akses.penyetuju_pengguna_id IS 'Penyetuju orang tertentu (reset TTE: atasan langsung pemilik); kosong = pemegang penyetuju_peran_id'");
        DB::statement('ALTER TABLE akses.persetujuan_akses ALTER COLUMN penyetuju_peran_id DROP NOT NULL');
        DB::statement('ALTER TABLE akses.persetujuan_akses ADD CONSTRAINT persetujuan_akses_penyetuju_cek CHECK ((penyetuju_peran_id IS NULL) <> (penyetuju_pengguna_id IS NULL))');
        DB::statement('CREATE INDEX persetujuan_akses_penyetuju_pengguna_idx ON akses.persetujuan_akses (penyetuju_pengguna_id, status) WHERE penyetuju_pengguna_id IS NOT NULL');

        DB::statement('ALTER TABLE akses.persetujuan_akses DROP CONSTRAINT persetujuan_akses_jenis_cek');
        DB::statement("ALTER TABLE akses.persetujuan_akses ADD CONSTRAINT persetujuan_akses_jenis_cek CHECK (jenis IN ('izin_sensitif', 'peran_superadmin', 'pengecualian', 'reset_ttd'))");
        DB::statement('ALTER TABLE akses.persetujuan_akses DROP CONSTRAINT persetujuan_akses_objek_cek');
        DB::statement("ALTER TABLE akses.persetujuan_akses ADD CONSTRAINT persetujuan_akses_objek_cek CHECK (objek_jenis IN ('izin_peran', 'penugasan', 'pengakhiran_penugasan', 'izin_khusus', 'penyetuju_izin', 'reset_ttd'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE akses.persetujuan_akses DROP CONSTRAINT persetujuan_akses_objek_cek');
        DB::statement("ALTER TABLE akses.persetujuan_akses ADD CONSTRAINT persetujuan_akses_objek_cek CHECK (objek_jenis IN ('izin_peran', 'penugasan', 'pengakhiran_penugasan', 'izin_khusus', 'penyetuju_izin'))");
        DB::statement('ALTER TABLE akses.persetujuan_akses DROP CONSTRAINT persetujuan_akses_jenis_cek');
        DB::statement("ALTER TABLE akses.persetujuan_akses ADD CONSTRAINT persetujuan_akses_jenis_cek CHECK (jenis IN ('izin_sensitif', 'peran_superadmin', 'pengecualian'))");
        DB::statement('ALTER TABLE akses.persetujuan_akses DROP CONSTRAINT persetujuan_akses_penyetuju_cek');
        DB::statement('ALTER TABLE akses.persetujuan_akses ALTER COLUMN penyetuju_peran_id SET NOT NULL');
        DB::statement('ALTER TABLE akses.persetujuan_akses DROP COLUMN penyetuju_pengguna_id');
    }
};
