<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Task ID antrean 1-7 tercatat internal (tiket 2.18; PRD 7.02.1, FR-02-02/06/16). Pemetaan kejadian → nomor task adalah data:
 * 7.02.1 bertanda wajib dicocokkan dengan katalog terbaru [Medium], jadi RS mengubahnya tanpa ubah kode. Tanpa DELETE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kunjungan.pemetaan_task_antrean', function (Blueprint $table) {
            $table->comment('Pemetaan kejadian sistem → nomor task ID Antrean BPJS (7.02.1). Kejadian tanpa baris aktif tidak dicatat. Nilai awal dari 7.02.1, perlu dicocokkan katalog.');
            $table->uuid('id')->primary();
            $table->text('kejadian')->unique()->comment('Kunci kejadian, mis. kunjungan_dibuat atau antrean.<titik>.<status>');
            $table->smallInteger('task_no');
            $table->boolean('aktif')->default(true);
            $table->timestampTz('dibuat_pada')->useCurrent();
            $table->timestampTz('diubah_pada')->useCurrent();
        });
        $now = now();
        DB::table('kunjungan.pemetaan_task_antrean')->insert(array_map(
            fn (string $kejadian, int $task) => ['id' => (string) Str::uuid7(), 'kejadian' => $kejadian, 'task_no' => $task, 'dibuat_pada' => $now, 'diubah_pada' => $now],
            ['kunjungan_dibuat', 'pemeriksaan_dimulai', 'pemeriksaan_selesai', 'resep_disahkan', 'antrean.farmasi.dilayani', 'antrean.farmasi.selesai', 'antrean.poli.batal', 'antrean.farmasi.batal'],
            [3, 4, 5, 5, 6, 7, 99, 99],
        ));

        Schema::create('kunjungan.antrean_task', function (Blueprint $table) {
            $table->comment('Jam task ID per antrean kunjungan JKN (7.02.1). status_kirim tertahan = menunggu adapter Antrean (2.19), sumber kiriman.');
            $table->uuid('id')->primary();
            $table->foreignUuid('antrean_id')->constrained('kunjungan.antrean')->restrictOnDelete();
            $table->foreignUuid('kunjungan_id')->constrained('kunjungan.kunjungan')->restrictOnDelete();
            $table->smallInteger('task_no');
            $table->text('kejadian');
            $table->timestampTz('terjadi_pada');
            $table->text('status_kirim')->default('tertahan');
            $table->timestampTz('dibuat_pada')->useCurrent();
            $table->timestampTz('diubah_pada')->useCurrent();

            $table->unique(['antrean_id', 'task_no']);
            $table->index(['kunjungan_id', 'terjadi_pada', 'id']);
        });
        DB::statement("ALTER TABLE kunjungan.antrean_task ADD CONSTRAINT antrean_task_status_cek CHECK (status_kirim IN ('tertahan', 'menunggu', 'terkirim', 'gagal'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('kunjungan.antrean_task');
        Schema::dropIfExists('kunjungan.pemetaan_task_antrean');
    }
};
