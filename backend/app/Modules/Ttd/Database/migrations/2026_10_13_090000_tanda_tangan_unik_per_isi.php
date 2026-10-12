<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dedup tanda tangan per isi (tiket 1.8c, kontrak v0.9.0): objek yang isinya berubah (mis. usulan dikirim ulang) bisa
 * ditandatangani lagi oleh orang yang sama; tanda tangan lama tetap ada dan terverifikasi `isi_berubah`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ttd.tanda_tangan DROP CONSTRAINT ttd_tanda_tangan_objek_jenis_objek_id_pengguna_id_unique');
        DB::statement('ALTER TABLE ttd.tanda_tangan ADD CONSTRAINT tanda_tangan_unik_per_isi UNIQUE (objek_jenis, objek_id, pengguna_id, hash_isi)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ttd.tanda_tangan DROP CONSTRAINT tanda_tangan_unik_per_isi');
        DB::statement('ALTER TABLE ttd.tanda_tangan ADD CONSTRAINT ttd_tanda_tangan_objek_jenis_objek_id_pengguna_id_unique UNIQUE (objek_jenis, objek_id, pengguna_id)');
    }
};
