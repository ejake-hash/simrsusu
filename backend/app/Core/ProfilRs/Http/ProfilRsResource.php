<?php

namespace App\Core\ProfilRs\Http;

use App\Core\ProfilRs\KelolaProfilRs;
use App\Core\ProfilRs\ProfilRs;

/**
 * Bentuk kontrak ProfilRs. `rinci` hanya bila pemanggil boleh melihatnya (data yang tidak diizinkan tidak
 * dikirim). URL berkas memuat potongan sha256 agar boleh di-cache lama.
 */
final class ProfilRsResource
{
    /** @return array<string, mixed> */
    public static function bentuk(?ProfilRs $profil, bool $denganRinci): array
    {
        $paket = $profil === null ? [] : $profil->paket_identitas;
        $url = fn (string $jenis) => isset($paket[$jenis]['sha256'])
            ? "/api/v1/profil-rs/berkas/$jenis?v=".substr((string) $paket[$jenis]['sha256'], 0, 12)
            : null;

        $data = [
            'versi' => KelolaProfilRs::versi($profil),
            'nama' => $profil?->nama,
            'paket_identitas' => [
                'warna' => $paket['warna'] ?? ProfilRs::WARNA_BAWAAN,
                'logo_url' => $url('logo'),
                'motif_url' => $url('motif'),
                'foto_url' => $url('foto'),
            ],
            'kontak_admin' => ['unit' => $profil === null ? 'admin SIMRS' : $profil->kontak_admin_unit, 'kontak' => $profil?->kontak_admin],
            'instalasi_demo' => (bool) config('instalasi.demo'),
        ];

        if ($denganRinci) {
            $data['rinci'] = [
                'kode_kemenkes' => $profil?->kode_kemenkes,
                'kelas' => $profil?->kelas,
                'status_blu' => $profil?->status_blu,
                'kode_ppk_bpjs' => $profil?->kode_ppk_bpjs,
                'alamat' => $profil?->alamat,
                'status_penyiapan' => $profil === null ? 'belum' : $profil->status_penyiapan,
                'diubah_pada' => $profil?->diubah_pada->toIso8601String(),
            ];
        }

        return $data;
    }
}
