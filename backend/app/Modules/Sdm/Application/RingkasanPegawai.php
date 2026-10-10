<?php

namespace App\Modules\Sdm\Application;

use App\Modules\Sdm\Models\Pegawai;

/** Pintu baca data pegawai untuk modul lain (PRD 10.3: tidak query tabel modul lain langsung). */
final class RingkasanPegawai
{
    /** @return array{id: string, nama: string}|null */
    public function untuk(string $pegawaiId): ?array
    {
        $pegawai = Pegawai::query()->find($pegawaiId, ['id', 'nama']);

        return $pegawai === null ? null : ['id' => $pegawai->id, 'nama' => $pegawai->nama];
    }
}
