<?php

namespace App\Modules\Akses\Application;

use App\Core\Http\ApiError;
use App\Modules\Akses\Database\MenuBawaan;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\LabelSensitivitas;
use App\Modules\Akses\Models\Peran;
use Illuminate\Support\Collection;

/**
 * Peran penyetuju yang dibutuhkan sebuah pemberian (tiket 1.2d; K3, K4, K13). Dipakai saat diajukan dan dihitung
 * ulang saat diterapkan, agar perubahan isi peran selagi menunggu tidak melewati penyetuju yang baru dibutuhkan.
 */
final class KebutuhanPersetujuan
{
    /**
     * Peran penyetuju tiap izin sensitif; 422 penyetuju_belum_ditetapkan bila ada yang belum (tidak ada yang disimpan).
     *
     * @param  Collection<int, Izin>  $izin
     * @return array<string, string> izin id => peran penyetuju id
     */
    public function penyetujuIzin(Collection $izin): array
    {
        $tanpa = $izin->filter(fn (Izin $i) => $i->peran_penyetuju_id === null);
        if ($tanpa->isNotEmpty()) {
            throw new ApiError(422, 'penyetuju_belum_ditetapkan', 'Izin sensitif ini belum punya penyetuju (pemilik data). Tetapkan dulu di pengaturan izin.',
                $tanpa->map(fn (Izin $i) => ['kolom' => 'izin', 'kode' => $i->kode, 'pesan' => 'Penyetuju belum ditetapkan'])->values()->all());
        }

        return $izin->mapWithKeys(fn (Izin $i) => [$i->id => (string) $i->peran_penyetuju_id])->all();
    }

    public function peranSuperadmin(): string
    {
        return (string) Peran::query()->where('kode', MenuBawaan::PERAN_SUPERADMIN)->value('id');
    }

    /**
     * Penyetuju penugasan sebuah peran: superadmin lain untuk peran superadmin (K4); pemilik data tiap izin sensitif
     * yang dimuatnya (K3); superadmin lain bila peran itu penyetuju izin sensitif atau label sensitivitas (K13, tiket
     * 1.5). Kosong = langsung berlaku.
     * $kunci: baris izin yang menentukan dikunci (dipakai saat penerapan, di dalam transaksi).
     *
     * @return list<string> peran penyetuju id, unik
     */
    public function penugasan(Peran $peran, bool $kunci = false): array
    {
        if ($peran->kode === MenuBawaan::PERAN_SUPERADMIN) {
            return [$this->peranSuperadmin()];
        }
        $idDimuat = $peran->izin()->pluck('akses.izin.id')->all();
        $sensitif = Izin::query()->where('sensitif', true)
            ->where(fn ($q) => $q->whereIn('id', $idDimuat)->orWhere('peran_penyetuju_id', $peran->id))
            ->when($kunci, fn ($q) => $q->orderBy('id')->lockForUpdate())->get();
        $dimuat = $sensitif->filter(fn (Izin $i) => in_array($i->id, $idDimuat, true))->values();
        $penyetuju = array_values($this->penyetujuIzin($dimuat));
        $penyetujuLabel = LabelSensitivitas::query()->where('peran_penyetuju_id', $peran->id)
            ->when($kunci, fn ($q) => $q->orderBy('id')->lockForUpdate())->exists();
        if ($penyetujuLabel || $sensitif->contains(fn (Izin $i) => $i->peran_penyetuju_id === $peran->id)) {
            $penyetuju[] = $this->peranSuperadmin();
        }

        return array_values(array_unique($penyetuju));
    }
}
