<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Penjamin (tiket 2.1, FR-01-12, FR-28-07): data induk dengan atribut penagihan. Kelas rawat sudah ada sejak 1.8b. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inti.penjamin', function (Blueprint $table) {
            $table->comment('Penjamin pembayaran (BPJS, umum, asuransi, perusahaan, Jasa Raharja, Jamkesda, sponsor riset). Diisi RS; tidak dihapus.');
            $table->uuid('id')->primary();
            $table->text('kode')->unique();
            $table->text('nama');
            $table->text('jenis')->comment('bpjs | umum | asuransi | perusahaan | jasa_raharja | jamkesda | sponsor_riset');
            $table->text('status')->default('aktif');
            $table->integer('masa_berlaku_gl_hari')->nullable()->comment('Masa berlaku surat jaminan sejak terbit; null = tidak dibatasi');
            $table->decimal('plafon', 17, 2)->nullable()->comment('Rupiah; null = tanpa plafon tetap');
            $table->integer('jatuh_tempo_hari')->nullable()->comment('Jatuh tempo penagihan sejak tagihan terbit');
            $table->kolomStandar();

            $table->index(['jenis', 'status']);
        });
        DB::statement("ALTER TABLE inti.penjamin ADD CONSTRAINT penjamin_jenis_cek CHECK (jenis IN ('bpjs', 'umum', 'asuransi', 'perusahaan', 'jasa_raharja', 'jamkesda', 'sponsor_riset'))");
        DB::statement("ALTER TABLE inti.penjamin ADD CONSTRAINT penjamin_status_cek CHECK (status IN ('aktif', 'nonaktif'))");
        DB::statement('ALTER TABLE inti.penjamin ADD CONSTRAINT penjamin_atribut_cek CHECK ((masa_berlaku_gl_hari IS NULL OR masa_berlaku_gl_hari BETWEEN 1 AND 3650) AND (plafon IS NULL OR plafon >= 0) AND (jatuh_tempo_hari IS NULL OR jatuh_tempo_hari BETWEEN 0 AND 365))');

        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        Schema::dropIfExists('inti.penjamin');
    }
};
