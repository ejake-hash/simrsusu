<?php

namespace App\Modules\Ttd\Application;

use App\Core\Audit\CatatAktivitas;
use App\Modules\Akses\Application\PencabutTtd;
use App\Modules\Akses\Application\TidakBisaDiterapkan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PersetujuanAkses;
use App\Modules\Ttd\Events\TtdDicabut;
use App\Modules\Ttd\Models\Kredensial;

/**
 * Menerapkan reset TTE yang disetujui (tiket 1.6): kredensial dicabut (tidak dihapus; kunci publiknya tetap untuk
 * verifikasi tanda tangan lama), pemilik diberi tahu. Dipanggil PenerapPersetujuan di dalam transaksi keputusan.
 */
final class PencabutKredensial implements PencabutTtd
{
    public function __construct(private readonly CatatAktivitas $audit) {}

    public function cabut(PersetujuanAkses $persetujuan, Pengguna $penyetuju): void
    {
        $k = Kredensial::query()->whereKey($persetujuan->objek['kredensial_id'] ?? null)->where('status', 'aktif')->lockForUpdate()->first();
        if ($k === null) {
            throw new TidakBisaDiterapkan('TTE akun ini sudah tidak aktif.');
        }
        $k->forceFill(['status' => 'dicabut', 'dicabut_pada' => now(), 'dicabut_oleh' => $penyetuju->id, 'alasan_cabut' => 'Reset '.$persetujuan->nomor,
            'diubah_oleh' => $penyetuju->id, 'revisi' => $k->revisi + 1])->save();
        TtdDicabut::dispatch($k->pengguna_id, $persetujuan->nomor);
        $this->audit->catat('ttd.dicabut', $penyetuju->id, 'ttd.kredensial', $k->id, ['persetujuan' => $persetujuan->nomor, 'dasar' => $persetujuan->dasar]);
    }
}
