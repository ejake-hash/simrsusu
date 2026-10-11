<?php

use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menu bawaan dan peran superadmin (tiket 1.2a). Superadmin yang dibuat akses:superadmin-pertama
 * sebelum peran ada (penanda audit akses.superadmin_dibuat, tiket 1.1c) dipindah ke peran superadmin.
 */
return new class extends Migration
{
    public function up(): void
    {
        MenuBawaan::pasang();

        $peranId = DB::table('akses.peran')->where('kode', MenuBawaan::PERAN_SUPERADMIN)->value('id');
        $lama = DB::table('audit.log_aktivitas')->where('aksi', 'akses.superadmin_dibuat')
            ->whereIn('subjek_id', DB::table('akses.pengguna')->select('id'))
            ->distinct()->pluck('subjek_id');

        foreach ($lama as $penggunaId) {
            DB::table('akses.pengguna_peran')->insert([
                'id' => (string) Str::uuid7(),
                'pengguna_id' => $penggunaId,
                'peran_id' => $peranId,
                'dasar_pemberian' => 'Konsol server: akses:superadmin-pertama (FR-54-08)',
                'alasan' => 'Dipindah dari penanda audit tiket 1.1c',
            ]);
        }
    }

    public function down(): void
    {
        // Isi ikut hilang saat tabelnya di-drop oleh migrasi sebelumnya.
    }
};
