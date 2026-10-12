<?php

namespace App\Core\Instalasi;

/** Penjaga siap melayani dari wizard penyiapan (tiket 1.8f): terkunci sejak langkah 1–6 pertama kali selesai. */
final class StatusPenyiapan implements StatusInstalasi
{
    public function __construct(private readonly Penyiapan $penyiapan) {}

    public function siapMelayani(): bool
    {
        return $this->penyiapan->siapMelayani();
    }
}
