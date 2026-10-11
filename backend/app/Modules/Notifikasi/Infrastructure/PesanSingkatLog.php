<?php

namespace App\Modules\Notifikasi\Infrastructure;

use App\Modules\Notifikasi\Domain\PengirimPesanSingkat;
use Illuminate\Support\Facades\Log;

/** Driver `log` (fase 1): mencatat bahwa pesan akan dikirim, tanpa isi dan tanpa nomor tujuan. Kanal belum tersedia bagi pengguna. */
final class PesanSingkatLog implements PengirimPesanSingkat
{
    public function tersedia(): bool
    {
        return false;
    }

    public function kirim(string $penggunaId, string $judul): void
    {
        Log::info('Pesan singkat (driver log)', ['pengguna_id' => $penggunaId]);
    }
}
