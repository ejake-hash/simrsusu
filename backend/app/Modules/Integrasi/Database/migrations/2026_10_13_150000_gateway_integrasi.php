<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Gateway integrasi (tiket 1.9; PRD 10, 11.2 integrasi.*; K8, K9): kredensial per sistem + lingkungan (rahasia
 * terenkripsi KREDENSIAL_KUNCI), status sistem (lingkungan aktif, circuit breaker, uji koneksi), outbox
 * `kotak_keluar` dan `log_api` berpartisi bulanan (UTC). Log disamarkan sebelum disimpan dan dihapus per partisi
 * sesudah masa simpan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrasi.kredensial_api', function (Blueprint $table) {
            $table->comment('Kredensial sistem luar per lingkungan (K9). Kolom rahasia hanya dalam bentuk terenkripsi.');
            $table->uuid('id')->primary();
            $table->text('tujuan');
            $table->text('lingkungan');
            $table->boolean('aktif')->default(false)->comment('Satu lingkungan aktif per tujuan');
            $table->jsonb('isian')->default('{}')->comment('Kolom bukan rahasia (cons_id, client_id, organization_id)');
            $table->text('rahasia')->nullable()->comment('JSON kolom rahasia, terenkripsi KREDENSIAL_KUNCI');
            $table->text('id_kunci')->nullable()->comment('Id kunci yang mengenkripsi `rahasia` (rotasi)');
            $table->jsonb('rahasia_ringkas')->default('{}')->comment('Kolom rahasia yang terisi => 4 karakter terakhir (hanya bila panjang ≥ 16) atau null; dibaca tanpa membuka rahasia');
            $table->kolomStandar();
            $table->unique(['tujuan', 'lingkungan']);
        });
        DB::statement("ALTER TABLE integrasi.kredensial_api ADD CONSTRAINT kredensial_api_tujuan_cek CHECK (tujuan IN ('bpjs_vclaim', 'aplicares', 'satusehat'))");
        DB::statement("ALTER TABLE integrasi.kredensial_api ADD CONSTRAINT kredensial_api_lingkungan_cek CHECK (lingkungan IN ('sandbox', 'produksi'))");
        DB::statement('CREATE UNIQUE INDEX kredensial_api_satu_aktif ON integrasi.kredensial_api (tujuan) WHERE aktif');

        Schema::create('integrasi.status_sistem', function (Blueprint $table) {
            $table->comment('Keadaan per sistem luar: circuit breaker lingkungan aktif, error dan uji koneksi terakhir.');
            $table->uuid('id')->primary();
            $table->text('tujuan')->unique();
            $table->text('cb_status')->default('tertutup');
            $table->integer('gagal_beruntun')->default(0);
            $table->timestampTz('cb_sejak')->nullable();
            $table->timestampTz('coba_lagi_pada')->nullable()->comment('Circuit breaker terbuka sampai');
            $table->integer('buka_detik')->default(0)->comment('Durasi terbuka terakhir (berlipat bila gagal lagi)');
            $table->text('error_terakhir')->nullable()->comment('Disamarkan');
            $table->timestampTz('error_terakhir_pada')->nullable();
            $table->jsonb('uji_koneksi_terakhir')->nullable();
            $table->timestampTz('diubah_pada')->useCurrent();
        });
        DB::statement("ALTER TABLE integrasi.status_sistem ADD CONSTRAINT status_sistem_cb_cek CHECK (cb_status IN ('tertutup', 'terbuka', 'setengah_terbuka'))");
        foreach (['bpjs_vclaim', 'aplicares', 'satusehat'] as $tujuan) {
            DB::table('integrasi.status_sistem')->insert(['id' => (string) Str::uuid7(), 'tujuan' => $tujuan]);
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE integrasi.kotak_keluar (
    id                       uuid           NOT NULL,
    tujuan                   text           NOT NULL CHECK (tujuan IN ('bpjs_vclaim', 'aplicares', 'satusehat')),
    lingkungan               text           CHECK (lingkungan IN ('sandbox', 'produksi')),
    jenis_resource           text           NOT NULL,
    agregat_jenis            text           NOT NULL,
    agregat_id               uuid           NOT NULL,
    muatan                   jsonb          NOT NULL DEFAULT '{}',
    kunci_idempotensi        text           NOT NULL,
    status                   text           NOT NULL DEFAULT 'menunggu' CHECK (status IN ('menunggu', 'terkirim', 'gagal', 'tertahan')),
    percobaan                integer        NOT NULL DEFAULT 0,
    coba_lagi_pada           timestamptz,
    terkirim_pada            timestamptz,
    error_terakhir           text,
    penyebab_tertahan_kode   text,
    penyebab_tertahan_pesan  text,
    pemilik_unit_id          uuid,
    id_luar                  text,
    dibuat_pada              timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
    diubah_pada              timestamptz    NOT NULL DEFAULT now(),
    PRIMARY KEY (id, dibuat_pada),
    CHECK ((status = 'tertahan') = (penyebab_tertahan_kode IS NOT NULL))
) PARTITION BY RANGE (dibuat_pada);

COMMENT ON TABLE integrasi.kotak_keluar IS 'Outbox kiriman ke sistem luar (PRD 10, FR-49-04/05), berpartisi per bulan (UTC). Muatan tidak pernah dikirim ke layar.';
CREATE INDEX kotak_keluar_daftar_idx ON integrasi.kotak_keluar (dibuat_pada DESC, id DESC);
CREATE INDEX kotak_keluar_tujuan_idx ON integrasi.kotak_keluar (tujuan, dibuat_pada DESC, id DESC);
CREATE INDEX kotak_keluar_id_idx ON integrasi.kotak_keluar (id);
CREATE INDEX kotak_keluar_antre_idx ON integrasi.kotak_keluar (tujuan, status, coba_lagi_pada) WHERE status IN ('menunggu', 'tertahan', 'gagal');
CREATE INDEX kotak_keluar_terkirim_idx ON integrasi.kotak_keluar (tujuan, terkirim_pada) WHERE status = 'terkirim';
CREATE INDEX kotak_keluar_agregat_idx ON integrasi.kotak_keluar (agregat_jenis, agregat_id, tujuan, jenis_resource) WHERE status <> 'terkirim';
CREATE TABLE integrasi.kotak_keluar_default PARTITION OF integrasi.kotak_keluar DEFAULT;

CREATE TABLE integrasi.log_api (
    id               uuid           NOT NULL,
    tujuan           text           NOT NULL CHECK (tujuan IN ('bpjs_vclaim', 'aplicares', 'satusehat')),
    lingkungan       text           NOT NULL CHECK (lingkungan IN ('sandbox', 'produksi')),
    metode           text           NOT NULL CHECK (metode IN ('GET', 'POST', 'PUT', 'PATCH', 'DELETE')),
    endpoint         text           NOT NULL,
    kode_http        integer,
    berhasil         boolean        NOT NULL,
    latensi_ms       integer,
    pesan            text,
    kotak_keluar_id  uuid,
    permintaan       text,
    respons          text,
    terpotong        boolean        NOT NULL DEFAULT false,
    terjadi_pada     timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (id, terjadi_pada)
) PARTITION BY RANGE (terjadi_pada);

COMMENT ON TABLE integrasi.log_api IS 'Log panggilan HTTP ke sistem luar, disamarkan (PRD 14.2), berpartisi per bulan; dihapus per partisi sesudah masa simpan.';
CREATE INDEX log_api_daftar_idx ON integrasi.log_api (terjadi_pada DESC, id DESC);
CREATE INDEX log_api_tujuan_idx ON integrasi.log_api (tujuan, terjadi_pada DESC, id DESC);
CREATE INDEX log_api_kiriman_idx ON integrasi.log_api (kotak_keluar_id) WHERE kotak_keluar_id IS NOT NULL;
CREATE TABLE integrasi.log_api_default PARTITION OF integrasi.log_api DEFAULT;

-- Partisi bulanan untuk kedua tabel (pola notifikasi.siapkan_partisi). SECURITY DEFINER: simrs_app tidak punya DDL.
CREATE FUNCTION integrasi.siapkan_partisi(acuan date, bulan_ke_depan int)
RETURNS TABLE (partisi text, status text) LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, integrasi, pg_temp AS $$
DECLARE
    awal timestamptz;
    akhir timestamptz;
    tabel text;
    kolom text;
BEGIN
    IF bulan_ke_depan < 0 OR bulan_ke_depan > 24 THEN
        RAISE EXCEPTION 'bulan_ke_depan harus 0..24';
    END IF;
    FOREACH tabel IN ARRAY ARRAY['kotak_keluar', 'log_api'] LOOP
        kolom := CASE tabel WHEN 'kotak_keluar' THEN 'dibuat_pada' ELSE 'terjadi_pada' END;
        FOR i IN 0..bulan_ke_depan LOOP
            awal := (date_trunc('month', acuan) + make_interval(months => i))::timestamp AT TIME ZONE 'UTC';
            akhir := (date_trunc('month', acuan) + make_interval(months => i + 1))::timestamp AT TIME ZONE 'UTC';
            partisi := 'integrasi.' || tabel || '_' || to_char(awal AT TIME ZONE 'UTC', 'YYYY_MM');
            IF to_regclass(partisi) IS NOT NULL THEN
                status := 'sudah_ada';
            ELSE
                EXECUTE format('SELECT EXISTS (SELECT 1 FROM integrasi.%I WHERE %I >= %L AND %I < %L)', tabel || '_default', kolom, awal, kolom, akhir) INTO STRICT status;
                IF status = 'true' THEN
                    status := 'bentrok_default';
                    RAISE WARNING 'Partisi % tidak dibuat: ada baris bulan itu di partisi default', partisi;
                ELSE
                    EXECUTE format('CREATE TABLE %s PARTITION OF integrasi.%I FOR VALUES FROM (%L) TO (%L)', partisi, tabel, awal, akhir);
                    status := 'dibuat';
                END IF;
            END IF;
            RETURN NEXT;
        END LOOP;
    END LOOP;
END
$$;
REVOKE ALL ON FUNCTION integrasi.siapkan_partisi(date, int) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION integrasi.siapkan_partisi(date, int) TO simrs_app;

-- Masa simpan log (PRD 14.2; paling singkat 30 hari): hanya partisi bulanan log_api yang seluruhnya sebelum `batas` yang dihapus. simrs_app
-- tidak punya DELETE di tabel ini; penghapusan hanya lewat fungsi ini.
CREATE FUNCTION integrasi.hapus_log_lama(batas timestamptz)
RETURNS SETOF text LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, integrasi, pg_temp AS $$
DECLARE
    p record;
    akhir timestamptz;
BEGIN
    -- Masa simpan minimum 30 hari dijaga di sini juga, tidak hanya di konfigurasi aplikasi.
    IF batas > now() - interval '30 days' THEN
        RAISE EXCEPTION 'batas masa simpan log terlalu dekat';
    END IF;
    FOR p IN SELECT c.relname FROM pg_catalog.pg_inherits i JOIN pg_catalog.pg_class c ON c.oid = i.inhrelid
             WHERE i.inhparent = 'integrasi.log_api'::regclass AND c.relname ~ '^log_api_[0-9]{4}_[0-9]{2}$' LOOP
        akhir := (to_date(substr(p.relname, 9), 'YYYY_MM') + interval '1 month')::timestamp AT TIME ZONE 'UTC';
        IF akhir <= batas THEN
            EXECUTE format('DROP TABLE integrasi.%I', p.relname);
            RETURN NEXT p.relname;
        END IF;
    END LOOP;
END
$$;
REVOKE ALL ON FUNCTION integrasi.hapus_log_lama(timestamptz) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION integrasi.hapus_log_lama(timestamptz) TO simrs_app;
SQL);
        DB::select('SELECT * FROM integrasi.siapkan_partisi(?::date, ?)', [now()->toDateString(), 3]);

        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS integrasi.hapus_log_lama(timestamptz)');
        DB::statement('DROP FUNCTION IF EXISTS integrasi.siapkan_partisi(date, int)');
        Schema::dropIfExists('integrasi.log_api');
        Schema::dropIfExists('integrasi.kotak_keluar');
        Schema::dropIfExists('integrasi.status_sistem');
        Schema::dropIfExists('integrasi.kredensial_api');
    }
};
