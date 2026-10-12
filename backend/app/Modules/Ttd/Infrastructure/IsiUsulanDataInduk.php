<?php

namespace App\Modules\Ttd\Infrastructure;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Application\MesinUsulan;
use App\Modules\MasterData\Models\UsulanDataInduk;
use App\Modules\Ttd\Application\PenyediaIsiTtd;
use Illuminate\Support\Facades\Gate;

/**
 * Isi kanonik usulan data induk (tiket 1.8c, kontrak v0.9.0): isi putaran ini tanpa label dan hasil penerapan.
 * Ditandatangani pengusul, pemverifikasi, atau penyetuju putaran itu sesudah tindakannya.
 */
final class IsiUsulanDataInduk implements PenyediaIsiTtd
{
    public function jenis(): string
    {
        return 'usulan_data_induk';
    }

    public function isi(string $id): ?array
    {
        $u = UsulanDataInduk::query()->find($id);

        return $u === null ? null : MesinUsulan::isiKanonik($u);
    }

    public function alasanTidakBoleh(Pengguna $penandaTangan, string $id): ?array
    {
        $u = UsulanDataInduk::query()->find($id);
        if ($u === null || ! $this->bolehLihat($penandaTangan, $u)) {
            return ['tidak_ditemukan', 'Usulan tidak ditemukan.'];
        }
        $pelaku = in_array($penandaTangan->id, array_filter([$u->diusulkan_oleh, $u->diverifikasi_oleh, $u->disetujui_oleh]), true);

        return $pelaku ? null : ['bukan_pemutus', 'Hanya pengusul, pemverifikasi, atau penyetuju usulan ini yang bisa menandatanganinya.'];
    }

    public function terlihat(Pengguna $pengguna, string $id): bool
    {
        $u = UsulanDataInduk::query()->find($id);

        return $u !== null && $this->bolehLihat($pengguna, $u);
    }

    private function bolehLihat(Pengguna $pengguna, UsulanDataInduk $u): bool
    {
        return in_array($pengguna->id, array_filter([$u->diusulkan_oleh, $u->diverifikasi_oleh, $u->disetujui_oleh]), true)
            || Gate::forUser($pengguna)->allows('master_data.usulan.lihat', Cakupan::rs());
    }
}
