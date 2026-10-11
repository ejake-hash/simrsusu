<?php

namespace App\Modules\Akses\Application;

use App\Modules\Akses\Models\Pengguna;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Kapan MFA wajib (PRD 10.3.2, 9.2.4): akun bertanda wajib_mfa, atau akses dari luar jaringan RS.
 * Sebelum tiket 1.2 penentunya kolom wajib_mfa; sesudahnya diturunkan dari peran.
 *
 * Pendaftaran MFA hanya dari jaringan RS (tiket 1.1c): sandi curian tidak bisa dipakai untuk
 * mendaftarkan autentikator dari internet.
 */
final class KebijakanMfa
{
    /**
     * Daftar CIDR kosong = instalasi belum selesai disiapkan: login web ditolak sampai
     * AUTENTIKASI_MFA_JARINGAN_RS diisi (FR-54-08). Superadmin pertama dibuat lewat konsol.
     */
    public function jaringanRsDiisi(): bool
    {
        return $this->jaringanRs() !== [];
    }

    public function dariJaringanRs(?string $ip): bool
    {
        return $ip !== null && IpUtils::checkIp($ip, $this->jaringanRs());
    }

    public function wajib(Pengguna $pengguna, ?string $ip): bool
    {
        return $pengguna->wajib_mfa || ! $this->dariJaringanRs($ip);
    }

    /** @return list<string> */
    private function jaringanRs(): array
    {
        return array_values((array) config('autentikasi.mfa.jaringan_rs'));
    }
}
