<?php

namespace App\Modules\Integrasi\Application;

use App\Core\Instalasi\SyaratKredensialIntegrasi;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use Illuminate\Support\Facades\DB;

/** Langkah 8 penyiapan: lingkungan aktif lengkap + uji koneksi terakhir lingkungan itu berhasil, per sistem fase 1. */
final class SyaratPenyiapan implements SyaratKredensialIntegrasi
{
    public function __construct(private readonly KelolaKredensial $kredensial) {}

    public function periksa(): array
    {
        $uji = DB::table('integrasi.status_sistem')->pluck('uji_koneksi_terakhir', 'tujuan');
        $siap = 0;
        foreach (SistemIntegrasi::cases() as $s) {
            $aktif = $this->kredensial->lingkunganAktif($s);
            $hasil = isset($uji[$s->value]) ? json_decode((string) $uji[$s->value], true) : null;
            if ($aktif !== null && $this->kredensial->kurang($s, $aktif) === [] && ($hasil['berhasil'] ?? false) === true && ($hasil['lingkungan'] ?? null) === $aktif) {
                $siap++;
            }
        }
        $jumlah = count(SistemIntegrasi::cases());

        return ['terpenuhi' => $siap === $jumlah, 'keterangan' => "$siap dari $jumlah"];
    }
}
