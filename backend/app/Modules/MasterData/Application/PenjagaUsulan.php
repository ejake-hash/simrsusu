<?php

namespace App\Modules\MasterData\Application;

use App\Core\Http\ApiError;
use App\Core\Instalasi\StatusInstalasi;

/**
 * Sesudah instalasi siap melayani (1.8f), perubahan langsung ruang/kamar/TT harus lewat usulan berjenjang (tiket 1.8c),
 * kecuali tambah/tutup TT ekstra saat lonjakan (tetap tercatat audit dan versi).
 */
final class PenjagaUsulan
{
    public function __construct(private readonly StatusInstalasi $instalasi) {}

    public function pastikanBolehLangsung(string $objek = 'ruang, kamar, dan tempat tidur'): void
    {
        if ($this->instalasi->siapMelayani()) {
            throw new ApiError(409, 'harus_lewat_usulan', "Instalasi sudah melayani pasien: perubahan $objek diajukan lewat Master Data → Usulan perubahan.");
        }
    }
}
