<?php

namespace App\Modules\Ttd\Application;

/** Penyedia isi per jenis objek (tiket 1.6). Jenis yang tidak terdaftar tidak bisa ditandatangani atau diverifikasi. */
final class DaftarPenyediaIsi
{
    /** @var array<string, PenyediaIsiTtd> */
    private array $penyedia = [];

    /** @param  iterable<PenyediaIsiTtd>  $penyedia */
    public function __construct(iterable $penyedia)
    {
        foreach ($penyedia as $p) {
            $this->penyedia[$p->jenis()] = $p;
        }
    }

    public function untuk(string $jenis): ?PenyediaIsiTtd
    {
        return $this->penyedia[$jenis] ?? null;
    }
}
