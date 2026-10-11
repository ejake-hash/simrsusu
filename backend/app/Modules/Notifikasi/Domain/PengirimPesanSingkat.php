<?php

namespace App\Modules\Notifikasi\Domain;

/** Kanal WhatsApp/SMS (K2): adapter; fase 1 hanya driver log (gateway butuh kontrak pihak ketiga). */
interface PengirimPesanSingkat
{
    public function tersedia(): bool;

    public function kirim(string $penggunaId, string $judul): void;
}
