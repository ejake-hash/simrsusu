<?php

namespace App\Modules\Akses\Application;

use App\Modules\Akses\Database\MenuBawaan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaIzinKhusus;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\Akses\Models\Peran;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Kapan MFA wajib (PRD 10.3.2, 9.2.4): peran aktif mana pun bertanda wajib_mfa atau memuat izin
 * sensitif (superadmin selalu), izin khusus sensitif, akun bertanda wajib_mfa, atau akses dari luar
 * jaringan RS. Admin tidak bisa membebaskan superadmin, izin sensitif, maupun akses luar; keringanan
 * lewat perangkat terdaftar, bukan mematikan MFA.
 *
 * Satu rumus untuk login (wajib) dan layar Akun & akses (sumberWajib), agar keduanya tidak berselisih.
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
        return ! $this->dariJaringanRs($ip) || $this->sumberWajib($pengguna) !== [];
    }

    /**
     * Alasan MFA wajib di dalam jaringan RS. Kosong = di jaringan RS tidak wajib; dari luar jaringan
     * RS selalu wajib dan tidak dicantumkan di sini (kontrak StatusMfaAkun.sumber_wajib).
     *
     * @return list<array{jenis: 'akun'|'peran'|'superadmin'|'izin_sensitif', label: string, dapat_dimatikan: bool}>
     */
    public function sumberWajib(Pengguna $pengguna): array
    {
        $sumber = [];
        if ($pengguna->wajib_mfa) {
            $sumber[] = ['jenis' => 'akun', 'label' => 'Sakelar akun', 'dapat_dimatikan' => true];
        }

        $daftarPeran = Peran::query()->mewajibkanMfa()
            ->whereIn('id', PenggunaPeran::query()->berlaku()->where('pengguna_id', $pengguna->id)->select('peran_id'))
            ->withExists(['izin as memuat_izin_sensitif' => fn ($izin) => $izin->where('sensitif', true)])
            ->orderBy('nama')->get();
        foreach ($daftarPeran as $peran) {
            $sumber[] = match (true) {
                $peran->kode === MenuBawaan::PERAN_SUPERADMIN => ['jenis' => 'superadmin', 'label' => "Peran {$peran->nama}", 'dapat_dimatikan' => false],
                (bool) $peran->getAttribute('memuat_izin_sensitif') => ['jenis' => 'izin_sensitif', 'label' => "Peran {$peran->nama} memuat izin sensitif", 'dapat_dimatikan' => false],
                // Dimatikan dari editor peran, bukan dari akun.
                default => ['jenis' => 'peran', 'label' => "Peran {$peran->nama}", 'dapat_dimatikan' => true],
            };
        }

        $izinKhususSensitif = PenggunaIzinKhusus::query()->berlaku()->where('pengguna_id', $pengguna->id)
            ->where('efek', 'tambah')->whereHas('izin', fn ($izin) => $izin->where('sensitif', true))->exists();
        if ($izinKhususSensitif) {
            $sumber[] = ['jenis' => 'izin_sensitif', 'label' => 'Izin khusus sensitif', 'dapat_dimatikan' => false];
        }

        return $sumber;
    }

    /** @return list<string> */
    private function jaringanRs(): array
    {
        return array_values((array) config('autentikasi.mfa.jaringan_rs'));
    }
}
