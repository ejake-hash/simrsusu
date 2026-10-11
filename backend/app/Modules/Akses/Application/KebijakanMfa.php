<?php

namespace App\Modules\Akses\Application;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaPeran;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Kapan MFA wajib (PRD 10.3.2, 9.2.4): peran aktif mana pun bertanda wajib_mfa atau memuat izin
 * sensitif (superadmin selalu), akun bertanda wajib_mfa, atau akses dari luar jaringan RS. Admin
 * tidak bisa membebaskan ketiganya; keringanan lewat perangkat terdaftar, bukan mematikan MFA.
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
        return $pengguna->wajib_mfa || ! $this->dariJaringanRs($ip) || $this->diwajibkanPeran($pengguna);
    }

    private function diwajibkanPeran(Pengguna $pengguna): bool
    {
        return PenggunaPeran::query()->berlaku()->where('pengguna_id', $pengguna->id)
            ->whereHas('peran', fn ($peran) => $peran->mewajibkanMfa())
            ->exists();
    }

    /** @return list<string> */
    private function jaringanRs(): array
    {
        return array_values((array) config('autentikasi.mfa.jaringan_rs'));
    }
}
