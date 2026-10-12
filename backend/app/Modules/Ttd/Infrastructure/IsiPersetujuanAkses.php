<?php

namespace App\Modules\Ttd\Infrastructure;

use App\Modules\Akses\Application\AlurPersetujuan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PersetujuanAkses;
use App\Modules\Ttd\Application\PenyediaIsiTtd;
use App\Modules\Ttd\Domain\IsiKanonik;

/**
 * Isi kanonik persetujuan akses (aturan rinci 1.6): hanya kolom tersimpan yang tidak berubah sesudah diputuskan, tanpa
 * label tampilan. Ditandatangani oleh akun pemutusnya sesudah disetujui/ditolak.
 */
final class IsiPersetujuanAkses implements PenyediaIsiTtd
{
    public function __construct(private readonly AlurPersetujuan $alur) {}

    public function jenis(): string
    {
        return 'persetujuan_akses';
    }

    public function isi(string $id): ?array
    {
        $p = PersetujuanAkses::query()->find($id);

        return $p === null ? null : [
            'id' => $p->id, 'nomor' => $p->nomor, 'jenis' => $p->jenis, 'objek_jenis' => $p->objek_jenis, 'objek' => $p->objek,
            'peran_id' => $p->peran_id, 'izin_id' => $p->izin_id, 'sasaran_pengguna_id' => $p->sasaran_pengguna_id,
            'penyetuju_peran_id' => $p->penyetuju_peran_id, 'penyetuju_pengguna_id' => $p->penyetuju_pengguna_id,
            'diajukan_oleh' => $p->diajukan_oleh, 'alasan' => $p->alasan, 'dasar' => $p->dasar, 'status' => $p->status,
            'diputuskan_oleh' => $p->diputuskan_oleh, 'diputuskan_pada' => IsiKanonik::waktu($p->diputuskan_pada),
            'catatan_keputusan' => $p->catatan_keputusan,
        ];
    }

    public function alasanTidakBoleh(Pengguna $penandaTangan, string $id): ?array
    {
        $p = PersetujuanAkses::query()->find($id);

        return match (true) {
            $p === null || ! $this->alur->terlihat($penandaTangan, $p) => ['tidak_ditemukan', 'Persetujuan tidak ditemukan.'],
            ! in_array($p->status, ['disetujui', 'ditolak'], true) || $p->diputuskan_oleh === null => ['belum_diputuskan', 'Persetujuan ini belum Anda putuskan.'],
            $p->diputuskan_oleh !== $penandaTangan->id => ['bukan_pemutus', 'Hanya akun yang memutuskan persetujuan ini yang bisa menandatanganinya.'],
            default => null,
        };
    }

    public function terlihat(Pengguna $pengguna, string $id): bool
    {
        $p = PersetujuanAkses::query()->find($id);

        return $p !== null && $this->alur->terlihat($pengguna, $p);
    }
}
