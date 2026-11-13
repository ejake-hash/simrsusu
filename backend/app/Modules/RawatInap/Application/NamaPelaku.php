<?php

namespace App\Modules\RawatInap\Application;

use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Support\Facades\DB;

/** Bentuk kontrak `Pelaku` {id akun, nama pegawai atau nama pengguna} untuk beberapa akun sekaligus (tanpa N+1). */
final class NamaPelaku
{
    public function __construct(private readonly RingkasanPegawai $pegawai) {}

    /**
     * @param  iterable<string|null>  $penggunaId
     * @return array<string, array{id: string, nama: string}> id akun => pelaku
     */
    public function untuk(iterable $penggunaId): array
    {
        $id = array_values(array_unique(array_filter([...$penggunaId], fn ($v) => is_string($v) && $v !== '')));
        if ($id === []) {
            return [];
        }
        $akun = DB::table('akses.pengguna')->whereIn('id', $id)->get(['id', 'pegawai_id', 'nama_pengguna']);
        $nama = $this->pegawai->nama($akun->pluck('pegawai_id')->filter()->map(fn ($v) => (string) $v)->all());
        $hasil = [];
        foreach ($akun as $a) {
            $hasil[(string) $a->id] = ['id' => (string) $a->id, 'nama' => $nama[(string) $a->pegawai_id] ?? (string) $a->nama_pengguna];
        }

        return $hasil;
    }
}
