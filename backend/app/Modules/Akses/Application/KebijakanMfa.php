<?php

namespace App\Modules\Akses\Application;

use App\Modules\Akses\Models\Pengguna;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Kapan MFA wajib (PRD 10.3.2, 9.2.4): akun bertanda wajib_mfa, atau akses dari luar jaringan RS.
 * Sebelum tiket 1.2 penentunya kolom wajib_mfa; sesudahnya diturunkan dari peran.
 */
final class KebijakanMfa
{
    /**
     * Daftar CIDR kosong berarti tidak ada alamat yang dianggap jaringan RS, jadi semua akses wajib
     * MFA. Gagal ke arah yang aman bila instalasi lupa mengisi AUTENTIKASI_MFA_JARINGAN_RS.
     */
    public function wajib(Pengguna $pengguna, ?string $ip): bool
    {
        $jaringanRs = (array) config('autentikasi.mfa.jaringan_rs');

        return $pengguna->wajib_mfa || $ip === null || ! IpUtils::checkIp($ip, $jaringanRs);
    }
}
