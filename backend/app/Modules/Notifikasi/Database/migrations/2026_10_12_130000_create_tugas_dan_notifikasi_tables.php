<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Kotak kerja, eskalasi, dan notifikasi (tiket 1.7; PRD 7.53, 11.2 inti.jenis_tugas/tugas/tugas_kejadian,
 * notifikasi.notifikasi). Tanpa DELETE: tugas ditutup lewat status, notifikasi ditandai dibaca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inti.jenis_tugas', function (Blueprint $table) {
            $table->comment('Jenis tugas dan aturan tenggat/eskalasi (FR-53-04): diubah RS di layar, dipasang migrasi modul pembuatnya.');
            $table->uuid('id')->primary();
            $table->text('kode')->unique()->comment('Stabil, dipakai kejadian domain');
            $table->text('nama');
            $table->text('uraian')->nullable();
            $table->text('modul')->comment('Modul pembuat tugas');
            $table->text('objek_jenis');
            $table->text('tautan_layar')->comment('Rute SPA dengan {objek_id}');
            $table->text('tenggat_dari')->default('dibuat')->comment('dibuat: tenggat = dibuat + batas; objek: tenggat dari tanggal objek, tugas dibuat batas menit sebelumnya');
            $table->integer('batas_menit');
            $table->foreignUuid('unit_teguran_id')->nullable()->comment('Tingkat 2 bila tugas tanpa unit')->constrained('inti.unit_organisasi')->restrictOnDelete();
            $table->foreignUuid('unit_eskalasi_id')->nullable()->comment('Tingkat 3; kosong = induk unit tingkat 2')->constrained('inti.unit_organisasi')->restrictOnDelete();
            $table->integer('pengingat_menit')->comment('Tingkat 1, relatif terhadap tenggat (negatif)');
            $table->integer('teguran_menit')->comment('Tingkat 2, sesudah tenggat');
            $table->integer('eskalasi_menit')->comment('Tingkat 3, sesudah tenggat');
            $table->boolean('kritis')->default(false)->comment('Notifikasinya tidak bisa dimatikan preferensi');
            $table->boolean('aktif')->default(true);
            $table->kolomStandar();
        });
        DB::statement("ALTER TABLE inti.jenis_tugas ADD CONSTRAINT jenis_tugas_tenggat_cek CHECK (tenggat_dari IN ('dibuat', 'objek'))");
        DB::statement('ALTER TABLE inti.jenis_tugas ADD CONSTRAINT jenis_tugas_eskalasi_cek CHECK (pengingat_menit < 0 AND teguran_menit >= 0 AND eskalasi_menit >= teguran_menit AND batas_menit > 0)');

        Schema::create('inti.tugas', function (Blueprint $table) {
            $table->comment('Tugas di kotak kerja (FR-53-01…03). Dibuat dan diselesaikan oleh kejadian domain.');
            $table->uuid('id')->primary();
            $table->foreignUuid('jenis_tugas_id')->constrained('inti.jenis_tugas')->restrictOnDelete();
            $table->text('objek_jenis');
            $table->uuid('objek_id');
            $table->text('judul')->comment('Tanpa data pasien');
            $table->text('tautan');
            $table->text('pelaksana_jenis')->comment('pengguna | peran | unit');
            $table->uuid('pelaksana_id');
            $table->uuid('unit_id')->nullable()->comment('Unit untuk eskalasi tingkat 2-3 (kepala unit, kepala unit induk)');
            $table->timestampTz('tenggat_pada');
            $table->timestampTz('pengingat_pada')->comment('Dihitung saat dibuat dari jenis_tugas');
            $table->timestampTz('teguran_pada');
            $table->timestampTz('eskalasi_pada');
            $table->smallInteger('tingkat_saat_ini')->default(0);
            $table->boolean('kritis')->default(false);
            $table->text('status')->default('terbuka');
            $table->timestampTz('selesai_pada')->nullable();
            $table->text('alasan_selesai')->nullable();
            $table->kolomStandar();

            $table->index(['status', 'tingkat_saat_ini', 'pengingat_pada']);
            $table->index(['pelaksana_jenis', 'pelaksana_id', 'status']);
            $table->index(['unit_id', 'status']);
            $table->index(['objek_jenis', 'objek_id']);
        });
        DB::statement("ALTER TABLE inti.tugas ADD CONSTRAINT tugas_status_cek CHECK (status IN ('terbuka', 'selesai', 'batal'))");
        DB::statement("ALTER TABLE inti.tugas ADD CONSTRAINT tugas_pelaksana_cek CHECK (pelaksana_jenis IN ('pengguna', 'peran', 'unit'))");
        DB::statement('ALTER TABLE inti.tugas ADD CONSTRAINT tugas_tingkat_cek CHECK (tingkat_saat_ini BETWEEN 0 AND 3)');
        // Satu tugas terbuka per jenis, objek, dan pelaksana: kejadian yang terulang tidak menggandakan tugas.
        DB::statement("CREATE UNIQUE INDEX tugas_terbuka_unik ON inti.tugas (jenis_tugas_id, objek_jenis, objek_id, pelaksana_jenis, pelaksana_id) WHERE status = 'terbuka'");

        Schema::create('inti.tugas_kejadian', function (Blueprint $table) {
            $table->comment('Linimasa tugas (FR-53-07): dibuat, pengingat, teguran, eskalasi, selesai, batal.');
            $table->uuid('id')->primary();
            $table->foreignUuid('tugas_id')->constrained('inti.tugas')->restrictOnDelete();
            $table->text('jenis');
            $table->smallInteger('tingkat');
            $table->timestampTz('terjadi_pada')->useCurrent();
            $table->jsonb('penerima')->default('[]')->comment('id akses.pengguna yang diberi tahu');
            $table->jsonb('kanal')->default('[]');
            $table->text('catatan')->nullable();

            $table->index(['tugas_id', 'terjadi_pada']);
        });
        DB::statement("ALTER TABLE inti.tugas_kejadian ADD CONSTRAINT tugas_kejadian_jenis_cek CHECK (jenis IN ('dibuat', 'pengingat', 'teguran', 'eskalasi', 'selesai', 'batal'))");
        DB::statement('CREATE INDEX tugas_kejadian_penerima_idx ON inti.tugas_kejadian USING gin (penerima)');

        DB::unprepared(<<<'SQL'
CREATE TABLE notifikasi.notifikasi (
    id           uuid        NOT NULL,
    pengguna_id  uuid        NOT NULL,
    jenis        text        NOT NULL,
    judul        text        NOT NULL,
    isi          text        NOT NULL,
    tautan       text,
    prioritas    text        NOT NULL DEFAULT 'biasa' CHECK (prioritas IN ('biasa', 'penting', 'kritis')),
    tugas_id     uuid,
    dibaca_pada  timestamptz,
    dibuat_pada  timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (id, dibuat_pada)
) PARTITION BY RANGE (dibuat_pada);

COMMENT ON TABLE notifikasi.notifikasi IS 'Notifikasi in-app per akun (FR-53-06), berpartisi per bulan (UTC). Tanpa data pasien di isi.';
CREATE INDEX notifikasi_pengguna_idx ON notifikasi.notifikasi (pengguna_id, dibuat_pada DESC, id DESC);
CREATE INDEX notifikasi_belum_dibaca_idx ON notifikasi.notifikasi (pengguna_id, dibuat_pada DESC, id DESC) WHERE dibaca_pada IS NULL;
CREATE TABLE notifikasi.notifikasi_default PARTITION OF notifikasi.notifikasi DEFAULT;

CREATE FUNCTION notifikasi.siapkan_partisi(acuan date, bulan_ke_depan int)
RETURNS TABLE (partisi text, status text) LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, notifikasi AS $$
DECLARE
    awal timestamptz;
    akhir timestamptz;
BEGIN
    IF bulan_ke_depan < 0 OR bulan_ke_depan > 24 THEN
        RAISE EXCEPTION 'bulan_ke_depan harus 0..24';
    END IF;
    FOR i IN 0..bulan_ke_depan LOOP
        awal := (date_trunc('month', acuan) + make_interval(months => i))::timestamp AT TIME ZONE 'UTC';
        akhir := (date_trunc('month', acuan) + make_interval(months => i + 1))::timestamp AT TIME ZONE 'UTC';
        partisi := 'notifikasi.notifikasi_' || to_char(awal AT TIME ZONE 'UTC', 'YYYY_MM');
        IF to_regclass(partisi) IS NOT NULL THEN
            status := 'sudah_ada';
        ELSIF EXISTS (SELECT 1 FROM notifikasi.notifikasi_default d WHERE d.dibuat_pada >= awal AND d.dibuat_pada < akhir) THEN
            status := 'bentrok_default';
            RAISE WARNING 'Partisi % tidak dibuat: ada baris bulan itu di notifikasi.notifikasi_default', partisi;
        ELSE
            EXECUTE format('CREATE TABLE %s PARTITION OF notifikasi.notifikasi FOR VALUES FROM (%L) TO (%L)', partisi, awal, akhir);
            status := 'dibuat';
        END IF;
        RETURN NEXT;
    END LOOP;
END
$$;
REVOKE ALL ON FUNCTION notifikasi.siapkan_partisi(date, int) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION notifikasi.siapkan_partisi(date, int) TO simrs_app;
SQL);
        DB::select('SELECT * FROM notifikasi.siapkan_partisi(?::date, ?)', [now()->toDateString(), 3]);

        Schema::create('notifikasi.preferensi', function (Blueprint $table) {
            $table->comment('Preferensi kanal per akun dan jenis (FR-53-09). Tanpa baris = bawaan (email menyala bila ada alamat).');
            $table->uuid('id')->primary();
            $table->foreignUuid('pengguna_id')->constrained('akses.pengguna')->restrictOnDelete();
            $table->text('jenis');
            $table->boolean('email')->default(true);
            $table->boolean('pesan_singkat')->default(false);
            $table->kolomStandar();

            $table->unique(['pengguna_id', 'jenis']);
        });

        // Jenis bawaan fase 1 (angka awal = data, diubah RS di layar; perlu verifikasi kebijakan RS).
        $sekarang = now();
        foreach ([
            ['PERSETUJUAN_AKSES_MENUNGGU', 'Persetujuan akses menunggu', 'akses', 'persetujuan_akses', '/pengaturan/persetujuan/{objek_id}', 'dibuat', 2880, -240, 0, 1440],
            ['PERMINTAAN_AKSES', 'Permintaan akses menunggu', 'akses', 'permintaan_akses', '/pengaturan/akun?permintaan={objek_id}', 'dibuat', 2880, -240, 0, 1440],
            ['IZIN_PRAKTIK_BERAKHIR', 'Izin praktik akan berakhir', 'sdm', 'izin_praktik', '/sdm/pegawai/{pegawai_id}?tab=kredensial', 'objek', 43200, -10080, 0, 1440],
        ] as [$kode, $nama, $modul, $objek, $tautan, $tenggatDari, $batas, $pengingat, $teguran, $eskalasi]) {
            DB::table('inti.jenis_tugas')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'kode' => $kode, 'nama' => $nama, 'modul' => $modul, 'objek_jenis' => $objek,
                'tautan_layar' => $tautan, 'tenggat_dari' => $tenggatDari, 'batas_menit' => $batas, 'pengingat_menit' => $pengingat,
                'teguran_menit' => $teguran, 'eskalasi_menit' => $eskalasi, 'kritis' => false, 'aktif' => true, 'dibuat_pada' => $sekarang, 'diubah_pada' => $sekarang,
            ]);
        }

        // Sub-menu master_data.jenis_tugas (PRD 9.1 "Jenis Tugas, Tenggat & Eskalasi").
        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        Schema::dropIfExists('notifikasi.preferensi');
        DB::statement('DROP FUNCTION IF EXISTS notifikasi.siapkan_partisi(date, int)');
        DB::statement('DROP TABLE IF EXISTS notifikasi.notifikasi');
        Schema::dropIfExists('inti.tugas_kejadian');
        Schema::dropIfExists('inti.tugas');
        Schema::dropIfExists('inti.jenis_tugas');
    }
};
