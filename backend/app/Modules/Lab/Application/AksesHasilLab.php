<?php

namespace App\Modules\Lab\Application;

use App\Modules\Akses\Application\AksesDaruratAktif;
use App\Modules\Akses\Domain\RelasiPerawatan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\RekamMedis\Application\AksesCatatan;
use App\Modules\RekamMedis\Application\KelolaPermintaanPemeriksaan;
use Illuminate\Support\Facades\Gate;

/**
 * Siapa yang boleh membuka linimasa hasil lab satu episode (tiket 2.31, keputusan pemilik 13 Nov 2026 "Relasi perawatan"):
 * izin rawat_jalan.pemeriksaan.lihat (cakupan mana pun), lalu cakupan unit kunjungan episode (pola order 2.16, termasuk akses
 * darurat) ATAU relasi perawatan aktif dengan pasien episode (lapis 4, mis. DPJP rawat inap sejak penempatan TT). Dipakai
 * juga untuk memilih penerima tugas tindak lanjut: tugas hanya ke akun yang bisa membuka hasilnya.
 */
final class AksesHasilLab
{
    public function __construct(
        private readonly AksesCatatan $akses,
        private readonly RelasiPerawatan $relasi,
    ) {}

    public function izinDasar(Pengguna $pengguna): bool
    {
        return Gate::forUser($pengguna)->allows(KelolaPermintaanPemeriksaan::IZIN_LIHAT);
    }

    public function bolehBukaEpisode(Pengguna $pengguna, string $episodeId): bool
    {
        if (! $this->izinDasar($pengguna)) {
            return false;
        }
        if ($this->akses->bolehBacaKunjungan($pengguna, [KelolaPermintaanPemeriksaan::IZIN_LIHAT], ['episode_id' => $episodeId])) {
            return true;
        }
        $pasien = AksesDaruratAktif::pasienEpisode($episodeId);

        return $pasien !== [] && $this->relasi->merawat($pengguna, $pasien[0]);
    }
}
