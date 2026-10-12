<?php

namespace App\Core\Instalasi;

/** Sampai wizard penyiapan (1.8f) dibangun, instalasi dianggap belum siap melayani pasien. */
final class BelumSiapMelayani implements StatusInstalasi
{
    public function siapMelayani(): bool
    {
        return false;
    }
}
