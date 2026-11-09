<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kunjungan IGD (tiket 2.23; FR-03-01, 7.00.2 baris "Rawat jalan harian"). IGD tidak memakai jadwal praktik, jadi
 * jadwal/poli/dokter boleh kosong hanya untuk `igd`; jam tiba (`tiba_pada`) wajib untuk `igd` dan kosong untuk poli.
 * Indeks unik kunjungan poli dipersempit ke poliklinik; satu kunjungan IGD aktif per pasien dijaga indeks parsial baru.
 * Jenis episode `igd_lebih_6_jam` (keputusan pemilik 9 Nov 2026, BentukEpisodeIgd).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE kunjungan.kunjungan ALTER COLUMN jadwal_praktik_id DROP NOT NULL, ALTER COLUMN poli_id DROP NOT NULL, ALTER COLUMN pegawai_id DROP NOT NULL');
        DB::statement('ALTER TABLE kunjungan.kunjungan ADD COLUMN tiba_pada timestamptz');
        DB::statement("COMMENT ON COLUMN kunjungan.kunjungan.tiba_pada IS 'Jam tiba IGD = jam server saat registrasi; null untuk poliklinik'");

        DB::statement('ALTER TABLE kunjungan.kunjungan DROP CONSTRAINT kunjungan_cek');
        DB::statement("ALTER TABLE kunjungan.kunjungan ADD CONSTRAINT kunjungan_cek CHECK (jenis_kunjungan IN ('poliklinik', 'igd') AND cara_bayar IN ('jkn', 'non_jkn') AND status IN ('terdaftar', 'dilayani', 'selesai', 'batal') AND ((status = 'batal') = (dibatalkan_pada IS NOT NULL AND alasan_batal IS NOT NULL)) AND (cara_bayar = 'non_jkn' OR penjamin_id IS NOT NULL))");
        DB::statement("ALTER TABLE kunjungan.kunjungan ADD CONSTRAINT kunjungan_jenis_cek CHECK ((jenis_kunjungan = 'poliklinik' AND jadwal_praktik_id IS NOT NULL AND poli_id IS NOT NULL AND pegawai_id IS NOT NULL AND tiba_pada IS NULL) OR (jenis_kunjungan = 'igd' AND tiba_pada IS NOT NULL))");

        DB::statement('DROP INDEX kunjungan.kunjungan_aktif_unik');
        DB::statement("CREATE UNIQUE INDEX kunjungan_aktif_unik ON kunjungan.kunjungan (pasien_id, jadwal_praktik_id, tanggal) WHERE status <> 'batal' AND jenis_kunjungan = 'poliklinik'");
        // 409 kunjungan_igd_aktif. Daftar IGD aktif (bawaan GET /kunjungan-igd, papan 2.25) dan pencarian kunjungan > 6 jam
        // memakai indeks parsial (tiba_pada, id).
        DB::statement("CREATE UNIQUE INDEX kunjungan_igd_aktif_unik ON kunjungan.kunjungan (pasien_id) WHERE jenis_kunjungan = 'igd' AND status IN ('terdaftar', 'dilayani')");
        DB::statement("CREATE INDEX kunjungan_igd_aktif_tiba ON kunjungan.kunjungan (tiba_pada, id) WHERE jenis_kunjungan = 'igd' AND status IN ('terdaftar', 'dilayani')");

        // Episode "IGD > 6 jam tanpa rawat inap" (7.00.2 baris 3). Indeks unik harian tetap hanya rawat_jalan_harian.
        DB::statement('ALTER TABLE kunjungan.episode DROP CONSTRAINT episode_cek');
        DB::statement("ALTER TABLE kunjungan.episode ADD CONSTRAINT episode_cek CHECK (jenis IN ('rawat_jalan_harian', 'igd_lebih_6_jam') AND status IN ('aktif', 'selesai', 'batal') AND ((status = 'batal') = (dibatalkan_pada IS NOT NULL)))");
    }

    /** Hanya untuk basis data tanpa kunjungan IGD (lokal/uji); produksi maju saja. */
    public function down(): void
    {
        DB::statement('ALTER TABLE kunjungan.episode DROP CONSTRAINT episode_cek');
        DB::statement("ALTER TABLE kunjungan.episode ADD CONSTRAINT episode_cek CHECK (jenis IN ('rawat_jalan_harian') AND status IN ('aktif', 'selesai', 'batal') AND ((status = 'batal') = (dibatalkan_pada IS NOT NULL)))");
        DB::statement('DROP INDEX IF EXISTS kunjungan.kunjungan_igd_aktif_tiba');
        DB::statement('DROP INDEX IF EXISTS kunjungan.kunjungan_igd_aktif_unik');
        DB::statement('DROP INDEX kunjungan.kunjungan_aktif_unik');
        DB::statement("CREATE UNIQUE INDEX kunjungan_aktif_unik ON kunjungan.kunjungan (pasien_id, jadwal_praktik_id, tanggal) WHERE status <> 'batal'");
        DB::statement('ALTER TABLE kunjungan.kunjungan DROP CONSTRAINT kunjungan_jenis_cek');
        DB::statement('ALTER TABLE kunjungan.kunjungan DROP CONSTRAINT kunjungan_cek');
        DB::statement("ALTER TABLE kunjungan.kunjungan ADD CONSTRAINT kunjungan_cek CHECK (jenis_kunjungan IN ('poliklinik') AND cara_bayar IN ('jkn', 'non_jkn') AND status IN ('terdaftar', 'dilayani', 'selesai', 'batal') AND ((status = 'batal') = (dibatalkan_pada IS NOT NULL AND alasan_batal IS NOT NULL)) AND (cara_bayar = 'non_jkn' OR penjamin_id IS NOT NULL))");
        DB::statement('ALTER TABLE kunjungan.kunjungan DROP COLUMN tiba_pada');
        DB::statement('ALTER TABLE kunjungan.kunjungan ALTER COLUMN jadwal_praktik_id SET NOT NULL, ALTER COLUMN poli_id SET NOT NULL, ALTER COLUMN pegawai_id SET NOT NULL');
    }
};
