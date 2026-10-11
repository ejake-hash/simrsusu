<?php

namespace App\Modules\Ttd\Infrastructure;

use App\Modules\Ttd\Domain\PenyediaTte;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Simulator PSrE (T2) untuk uji alur asinkron (K10): menyimpan status transaksi di cache dan meniru webhook lewat
 * `putuskan()`. Bukan tanda tangan tersertifikasi; tidak boleh dipakai sebagai penyedia di produksi.
 */
final class SimulatorTte implements PenyediaTte
{
    private const KUNCI = 'ttd:simulator:';

    public function kode(): string
    {
        return 'simulator';
    }

    public function kirim(string $permintaanId, string $penandaTanganId, string $hashIsi): string
    {
        $id = 'SIM-'.Str::uuid7();
        Cache::put(self::KUNCI.$id, 'menunggu', now()->addDay());

        return $id;
    }

    public function status(string $idTransaksi): string
    {
        return (string) Cache::get(self::KUNCI.$idTransaksi, 'gagal');
    }

    /** Tiruan keputusan penanda tangan di sisi penyedia (sebelum webhook). */
    public function putuskan(string $idTransaksi, string $status): void
    {
        Cache::put(self::KUNCI.$idTransaksi, $status, now()->addDay());
    }
}
