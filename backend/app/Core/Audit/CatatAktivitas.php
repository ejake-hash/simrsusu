<?php

namespace App\Core\Audit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menulis ke audit.log_aktivitas (PRD 9.2.4, 14.2). simrs_app hanya punya SELECT, INSERT di
 * skema audit, jadi log tidak bisa diubah atau dihapus aplikasi.
 * Jangan pernah mengirim sandi, token, atau data pasien di `sebelum`/`sesudah`.
 */
final class CatatAktivitas
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>|null  $sesudah
     * @param  array<string, mixed>|null  $sebelum
     */
    public function catat(
        string $aksi,
        ?string $penggunaId = null,
        ?string $subjekJenis = null,
        ?string $subjekId = null,
        ?array $sesudah = null,
        ?array $sebelum = null,
    ): void {
        DB::table('audit.log_aktivitas')->insert([
            'id' => (string) Str::uuid7(),
            'pengguna_id' => $penggunaId,
            'aksi' => $aksi,
            'subjek_jenis' => $subjekJenis,
            'subjek_id' => $subjekId,
            'sebelum' => $sebelum === null ? null : json_encode($sebelum),
            'sesudah' => $sesudah === null ? null : json_encode($sesudah),
            'ip' => $this->request->ip(),
            'perangkat' => Str::limit((string) $this->request->userAgent(), 255, ''),
            'terjadi_pada' => now(),
        ]);
    }
}
