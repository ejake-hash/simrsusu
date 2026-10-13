<?php

namespace App\Core\ProfilRs;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ubah profil RS dan berkas paket identitas (tiket 1.8a, FR-54-08 langkah 2). Versi = revisi baris profil
 * (instalasi kosong = "0"); compare-and-set di awal transaksi, 412 bila berubah. Berkas lama tidak dihapus.
 */
final class KelolaProfilRs
{
    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly PemeriksaBerkas $pemeriksa,
    ) {}

    public static function versi(?ProfilRs $profil): string
    {
        return (string) ($profil->revisi ?? 0);
    }

    /** @param  array<string, mixed>  $isi  IsianProfilRs (kontrak) */
    public function ubah(Authenticatable $penyunting, string $versi, array $isi): ProfilRs
    {
        $kurang = KontrasWarna::periksa($isi['warna']);
        if ($kurang !== []) {
            throw new ApiError(422, 'kontras_kurang', 'Kontras warna tidak cukup untuk dibaca.', $kurang);
        }

        return $this->simpan($penyunting, $versi, function (ProfilRs $profil) use ($isi) {
            $profil->fill([
                ...array_intersect_key($isi, array_flip(['nama', 'kode_kemenkes', 'kelas', 'status_blu', 'kode_ppk_bpjs', 'alamat'])),
                'kontak_admin_unit' => $isi['kontak_admin']['unit'],
                'kontak_admin' => $isi['kontak_admin']['kontak'],
            ]);
            $profil->paket_identitas = [...$profil->paket_identitas, 'warna' => $isi['warna']];
            // Tidak dikirim = tetap; null = kembali ke teks bawaan (tiket 1.15).
            if (array_key_exists('halaman_masuk', $isi)) {
                $profil->halaman_masuk = $isi['halaman_masuk'];
            }

            return ['aksi' => 'inti.profil_rs_diubah', 'dasar_perubahan' => $isi['dasar_perubahan']];
        });
    }

    public function gantiBerkas(Authenticatable $penyunting, string $versi, string $jenis, UploadedFile $berkas): ProfilRs
    {
        ['tipe' => $tipe, 'ekstensi' => $ekstensi, 'isi' => $isi] = $this->pemeriksa->periksa($jenis, (string) file_get_contents($berkas->getRealPath()));
        if ($jenis === 'foto') {
            // Panel halaman masuk 16:10; metadata (EXIF/GPS) terbuang karena dienkode ulang (tiket 1.15).
            $isi = PotongFoto::potong($isi, $tipe);
        }

        return $this->simpan($penyunting, $versi, function (ProfilRs $profil) use ($jenis, $tipe, $ekstensi, $isi) {
            // Ditulis sesudah versi lolos (412 tidak meninggalkan berkas yatim). Berkas lama tetap ada.
            $path = "identitas/$jenis/".Str::uuid7().".$ekstensi";
            Storage::disk(config('instalasi.identitas.disk'))->put($path, $isi);
            $data = ['path' => $path, 'tipe' => $tipe, 'ukuran' => strlen($isi), 'sha256' => hash('sha256', $isi), 'diunggah_pada' => now()->toIso8601String()];
            $profil->paket_identitas = [...$profil->paket_identitas, $jenis => $data];

            return ['aksi' => 'inti.profil_rs_berkas_diganti', 'jenis' => $jenis, 'tipe' => $tipe, 'ukuran' => $data['ukuran'], 'sha256' => $data['sha256']];
        });
    }

    /** @param  callable(ProfilRs): array<string, mixed>  $ubah  mengubah model dan mengembalikan isi audit (dengan `aksi`) */
    private function simpan(Authenticatable $penyunting, string $versi, callable $ubah): ProfilRs
    {
        try {
            return DB::transaction(function () use ($penyunting, $versi, $ubah) {
                $profil = ProfilRs::query()->lockForUpdate()->first();
                if (self::versi($profil) !== $versi) {
                    throw $this->versiBerubah();
                }
                $profil ??= new ProfilRs;
                $sebelum = $profil->exists ? $this->isiAudit($profil) : null;

                $audit = $ubah($profil);
                $profil->forceFill([
                    'revisi' => $profil->revisi + 1,
                    'diubah_oleh' => $penyunting->getAuthIdentifier(),
                    'dibuat_oleh' => $profil->exists ? $profil->getAttribute('dibuat_oleh') : $penyunting->getAuthIdentifier(),
                ])->save();

                $aksi = $audit['aksi'];
                unset($audit['aksi']);
                $this->audit->catat($aksi, (string) $penyunting->getAuthIdentifier(), 'inti.profil_rs', $profil->id,
                    [...$this->isiAudit($profil), ...$audit], $sebelum);

                return $profil;
            });
        } catch (QueryException $e) {
            // Dua pengisian pertama bersamaan: yang kalah melanggar indeks profil_rs_tunggal.
            throw $e->getCode() === '23505' ? $this->versiBerubah() : $e;
        }
    }

    /** @return array<string, mixed> */
    private function isiAudit(ProfilRs $profil): array
    {
        return [
            ...$profil->only(['nama', 'kode_kemenkes', 'kelas', 'status_blu', 'kode_ppk_bpjs', 'alamat', 'kontak_admin_unit', 'kontak_admin']),
            'warna' => $profil->paket_identitas['warna'] ?? null,
            'halaman_masuk' => $profil->halaman_masuk,
        ];
    }

    private function versiBerubah(): ApiError
    {
        return new ApiError(412, 'versi_berubah', 'Profil RS diubah orang lain sejak Anda membukanya. Muat ulang untuk melihat perubahan terbaru.');
    }
}
