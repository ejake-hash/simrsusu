<?php

namespace App\Modules\Sdm\Application;

use App\Core\Database\Like;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Database\Eloquent\Builder;

/** Pintu baca data pegawai untuk modul lain (PRD 10.3: tidak query tabel modul lain langsung). */
final class RingkasanPegawai
{
    /** @return array{id: string, nama: string}|null */
    public function untuk(string $pegawaiId): ?array
    {
        $pegawai = Pegawai::query()->find($pegawaiId, ['id', 'nama']);

        return $pegawai === null ? null : ['id' => $pegawai->id, 'nama' => $pegawai->nama];
    }

    /**
     * Ringkasan banyak pegawai sekaligus (kontrak RingkasPegawai: id, nama, status kepegawaian).
     *
     * @param  iterable<string>  $pegawaiId
     * @return array<string, array{id: string, nama: string, status: string}>
     */
    public function ringkas(iterable $pegawaiId): array
    {
        $id = array_values(array_unique([...$pegawaiId]));

        return $id === [] ? [] : Pegawai::query()->whereIn('id', $id)->get(['id', 'nama', 'tanggal_keluar'])
            ->mapWithKeys(fn (Pegawai $p) => [$p->id => ['id' => $p->id, 'nama' => $p->nama, 'status' => $p->statusKepegawaian()]])->all();
    }

    /**
     * Nama banyak pegawai sekaligus (daftar berhalaman, tanpa N+1).
     *
     * @param  iterable<string>  $pegawaiId
     * @return array<string, string> id pegawai => nama
     */
    public function nama(iterable $pegawaiId): array
    {
        $id = array_values(array_unique([...$pegawaiId]));

        return $id === [] ? [] : Pegawai::query()->whereIn('id', $id)->pluck('nama', 'id')->all();
    }

    /**
     * Subquery id pegawai yang namanya memuat $cari (tanpa membedakan huruf besar), untuk whereIn.
     *
     * @return Builder<Pegawai>
     */
    public function idCocokNama(string $cari): Builder
    {
        return Pegawai::query()->select('id')->whereRaw('nama ILIKE ?', [Like::memuat($cari)]);
    }
}
