<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Katalog obat/BMHP dan formularium (tiket 2.11, FR-22-06, FR-22-17; kontrak v0.23.0). Tanpa DELETE: status nonaktif. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistik.barang', function (Blueprint $table) {
            $table->comment('Katalog barang (PRD 11.2). Generik dan paten zat yang sama = dua barang dengan dua kode (FR-22-06). Stok dan harga menyusul Fase 3.');
            $table->uuid('id')->primary();
            $table->text('kode')->unique()->comment('kode_rs');
            $table->text('nama');
            $table->text('jenis')->comment('obat | bmhp | reagen | gizi | umum | implan');
            $table->text('generik_paten')->nullable()->comment('generik | paten; wajib untuk obat');
            $table->text('zat_aktif')->nullable()->comment('Beberapa zat dipisah +');
            $table->text('kekuatan')->nullable();
            $table->text('sediaan')->nullable();
            $table->text('satuan_terkecil');
            $table->text('kode_kfa')->nullable()->comment('Diisi manual sampai pencarian KFA (tiket 2.22)');
            $table->boolean('kewaspadaan_tinggi')->default(false);
            $table->boolean('narkotika')->default(false)->comment('Narkotika atau psikotropika');
            $table->boolean('lasa')->default(false)->comment('Look-alike sound-alike');
            $table->text('status')->default('aktif');
            $table->kolomStandar();

            $table->index(['status', 'nama', 'id']);
        });
        DB::statement("ALTER TABLE logistik.barang ADD CONSTRAINT barang_cek CHECK (jenis IN ('obat', 'bmhp', 'reagen', 'gizi', 'umum', 'implan') AND status IN ('aktif', 'nonaktif') AND (generik_paten IS NULL OR generik_paten IN ('generik', 'paten')) AND (jenis <> 'obat' OR generik_paten IS NOT NULL))");
        DB::statement('CREATE INDEX barang_cari_trgm ON logistik.barang USING gin ((kode || \' \' || nama || \' \' || coalesce(zat_aktif, \'\')) gin_trgm_ops)');

        Schema::create('farmasi.formularium', function (Blueprint $table) {
            $table->comment('Formularium Fornas/RS/penjamin (PRD 11.2). Sesudah siap melayani diubah lewat usulan (FR-54-02, tiket 2.12). Satu per (jenis, penjamin) pada satu tanggal.');
            $table->uuid('id')->primary();
            $table->text('kode')->unique()->comment('Tidak dapat diubah (kontrak v0.23.0)');
            $table->text('nama');
            $table->text('jenis')->comment('fornas | rs | penjamin');
            $table->foreignUuid('penjamin_id')->nullable()->constrained('inti.penjamin')->restrictOnDelete();
            $table->date('berlaku_dari');
            $table->date('berlaku_sampai')->nullable()->comment('null = tanpa batas akhir');
            $table->kolomStandar();

            $table->index(['jenis', 'penjamin_id', 'berlaku_dari']);
        });
        DB::statement("ALTER TABLE farmasi.formularium ADD CONSTRAINT formularium_cek CHECK (jenis IN ('fornas', 'rs', 'penjamin') AND ((jenis = 'penjamin') = (penjamin_id IS NOT NULL)) AND (berlaku_sampai IS NULL OR berlaku_sampai >= berlaku_dari))");

        Schema::create('farmasi.formularium_item', function (Blueprint $table) {
            $table->comment('Barang dalam formularium (PRD 11.2). Tidak dihapus: status nonaktif.');
            $table->uuid('id')->primary();
            $table->foreignUuid('formularium_id')->constrained('farmasi.formularium')->restrictOnDelete();
            $table->foreignUuid('barang_id')->constrained('logistik.barang')->restrictOnDelete();
            $table->text('restriksi')->nullable();
            $table->integer('maks_per_resep')->nullable()->comment('Satuan terkecil; null = tidak dibatasi');
            $table->boolean('kronis')->default(false);
            $table->text('status')->default('aktif');
            $table->kolomStandar();

            $table->unique(['formularium_id', 'barang_id']);
            $table->index(['barang_id']);
        });
        DB::statement("ALTER TABLE farmasi.formularium_item ADD CONSTRAINT formularium_item_cek CHECK (status IN ('aktif', 'nonaktif') AND (maks_per_resep IS NULL OR maks_per_resep >= 1))");
    }

    public function down(): void
    {
        Schema::dropIfExists('farmasi.formularium_item');
        Schema::dropIfExists('farmasi.formularium');
        Schema::dropIfExists('logistik.barang');
    }
};
