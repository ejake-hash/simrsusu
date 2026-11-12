<?php

namespace App\Modules\MasterData\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\SuntinganBerversi;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\DaftarPeriksaPulang;
use Illuminate\Support\Facades\DB;

/**
 * Data induk daftar periksa pulang (tiket 2.40, FR-05-03). Pola area IGD (2.25): edit langsung oleh pemegang
 * master_data.daftar_periksa_pulang.perbaiki, dasar perubahan wajib, audit sebelum/sesudah. Kode dan sumber tetap (kode
 * disalin ke rencana pulang; sumber menentukan siapa yang menyelesaikan butir). Bukan data pasien.
 */
final class KelolaDaftarPeriksaPulang
{
    private const KOLOM = ['kode', 'nama', 'sumber', 'wajib', 'urutan', 'status'];

    public function __construct(private readonly CatatAktivitas $audit) {}

    /** @param  array<string, mixed>  $isi  sudah divalidasi bentuknya */
    public function ubah(Pengguna $penyunting, DaftarPeriksaPulang $butir, string $versi, array $isi): DaftarPeriksaPulang
    {
        return DB::transaction(function () use ($penyunting, $butir, $versi, $isi) {
            SuntinganBerversi::naikkan(DaftarPeriksaPulang::class, $butir->id, $versi, $penyunting->id, 'Butir daftar periksa pulang');
            $sebelum = $butir->refresh()->only(self::KOLOM);
            $ubahan = array_intersect_key($isi, array_flip(['nama', 'wajib', 'urutan', 'status']));
            if (isset($ubahan['nama'])) {
                $ubahan['nama'] = trim((string) $ubahan['nama']);
            }
            $butir->fill($ubahan)->forceFill(['diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('master_data.daftar_periksa_pulang_diubah', $penyunting->id, $butir->getTable(), $butir->id,
                [...$butir->refresh()->only(self::KOLOM), 'dasar_perubahan' => $isi['dasar_perubahan']], $sebelum);

            return $butir;
        });
    }
}
