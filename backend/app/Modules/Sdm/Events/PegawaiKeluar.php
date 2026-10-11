<?php

namespace App\Modules\Sdm\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pegawai keluar sudah diproses (tanggal_keluar tercapai; PRD 9.2.6). Didengar sinkron di dalam transaksi yang sama:
 * modul Akses menonaktifkan akunnya dan memutus sesinya, jadi keduanya berhasil atau gagal bersama.
 */
final class PegawaiKeluar
{
    use Dispatchable;

    public function __construct(
        public readonly string $pegawaiId,
        public readonly string $alasan,
        public readonly ?string $olehId,
    ) {}
}
