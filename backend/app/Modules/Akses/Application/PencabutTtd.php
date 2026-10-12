<?php

namespace App\Modules\Akses\Application;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PersetujuanAkses;

/**
 * Menerapkan persetujuan `reset_ttd` (tiket 1.6): mencabut TTE akun sasaran. Diisi modul Ttd; dipanggil di dalam
 * transaksi keputusan terakhir. TidakBisaDiterapkan bila TTE sudah tidak aktif.
 */
interface PencabutTtd
{
    public function cabut(PersetujuanAkses $persetujuan, Pengguna $penyetuju): void;
}
