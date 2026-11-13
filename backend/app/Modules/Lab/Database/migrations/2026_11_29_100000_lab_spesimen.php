<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lab: daftar kerja dan spesimen (tiket 2.30; FR-19-01..03, PRD 7.00.5 butir 4, PRD 11.2 `lab.spesimen`).
 * - Status butir order diperluas: `dipesan` → `sampel_diambil` → `diterima_lab` (atau `batal`). Status permintaan tetap.
 * - lab.spesimen: satu spesimen per butir order; barcode `LAB-YYYYMMDD-00001` dari penghitung harian (tanggal zona RS).
 *   `diterima_oleh` tambahan di luar 11.2 (uji tiket meminta petugas penerimaan). Tanpa DELETE.
 * - Indeks daftar kerja: indeks parsial butir aktif (alasan di up()).
 * - Aksi `verifikasi` (terima spesimen di lab) untuk penunjang.laboratorium lewat AKSI_TAMBAHAN; jenis aksi sudah dikenal
 *   izin_jenis_aksi_cek (usulan data induk), jadi cek itu tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE permintaan.permintaan_item DROP CONSTRAINT permintaan_item_cek');
        DB::statement("ALTER TABLE permintaan.permintaan_item ADD CONSTRAINT permintaan_item_cek CHECK (status IN ('dipesan', 'sampel_diambil', 'diterima_lab', 'batal'))");
        // Daftar kerja (tinjauan #206): status permintaan tetap `dipesan` sesudah semua butir diterima lab, jadi indeks di
        // permintaan_pemeriksaan ikut memuat seluruh riwayat order lab dan polling makin lambat. Indeks parsial butir aktif
        // hanya berisi butir yang sedang dikerjakan (dipesan/sampel_diambil); query mulai dari himpunan kecil ini
        // (p.id IN subkueri) lalu mengambil permintaannya lewat kunci utama dan mengurutkan keyset (dibuat_pada, id).
        // Dipilih ketimbang penanda `lab_aktif` di permintaan: tidak ada kolom turunan yang harus dijaga sinkron oleh pesan,
        // ambil, terima, dan batal (dua modul), dan ukuran indeks mengikuti beban kerja aktif, bukan umur data.
        DB::statement("CREATE INDEX permintaan_item_aktif ON permintaan.permintaan_item (permintaan_pemeriksaan_id) WHERE status IN ('dipesan', 'sampel_diambil')");

        Schema::create('lab.nomor_spesimen', function (Blueprint $table) {
            $table->comment('Penghitung barcode spesimen per tanggal zona RS (LAB-YYYYMMDD-NNNNN). Dinaikkan di transaksi ambil sampel.');
            $table->date('tanggal')->primary();
            $table->integer('terakhir')->default(0);
        });

        Schema::create('lab.spesimen', function (Blueprint $table) {
            $table->comment('Spesimen lab per butir order (PRD 11.2, tiket 2.30). Tidak dihapus; penolakan spesimen menyusul.');
            $table->uuid('id')->primary();
            $table->foreignUuid('permintaan_item_id')->unique()->constrained('permintaan.permintaan_item')->restrictOnDelete();
            $table->text('barcode')->unique();
            $table->text('jenis')->comment('Jenis spesimen, teks bebas sampai data induk jenis spesimen tersedia');
            $table->text('lokasi_pengambilan')->comment('instalasi | samping_tt | kamar_operasi (7.00.5 butir 4)');
            $table->timestampTz('diambil_pada', 6)->comment('Jam server');
            $table->foreignUuid('diambil_oleh')->comment('akses.pengguna.id')->constrained('akses.pengguna')->restrictOnDelete();
            $table->timestampTz('diterima_pada', 6)->nullable()->comment('Jam server penerimaan di lab');
            $table->uuid('diterima_oleh')->nullable()->comment('akses.pengguna.id');
            $table->text('kondisi')->nullable()->comment('Diisi saat diterima; penolakan spesimen menyusul');
            $table->kolomStandar();

            $table->foreign('diterima_oleh')->references('id')->on('akses.pengguna')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE lab.spesimen ADD CONSTRAINT spesimen_cek CHECK (
            lokasi_pengambilan IN ('instalasi', 'samping_tt', 'kamar_operasi')
            AND length(btrim(jenis)) > 0
            AND (kondisi IS NULL OR kondisi IN ('baik'))
            AND ((diterima_pada IS NULL) = (diterima_oleh IS NULL))
            AND ((diterima_pada IS NULL) = (kondisi IS NULL))
            AND (diterima_pada IS NULL OR diterima_pada >= diambil_pada))");

        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    /** Maju saja di produksi; down untuk lokal. Izin `verifikasi` tetap (bisa sudah diberikan ke peran). */
    public function down(): void
    {
        Schema::dropIfExists('lab.spesimen');
        Schema::dropIfExists('lab.nomor_spesimen');
        DB::statement('DROP INDEX IF EXISTS permintaan.permintaan_item_aktif');
        DB::statement("UPDATE permintaan.permintaan_item SET status = 'dipesan' WHERE status IN ('sampel_diambil', 'diterima_lab')");
        DB::statement('ALTER TABLE permintaan.permintaan_item DROP CONSTRAINT permintaan_item_cek');
        DB::statement("ALTER TABLE permintaan.permintaan_item ADD CONSTRAINT permintaan_item_cek CHECK (status IN ('dipesan', 'batal'))");
    }
};
