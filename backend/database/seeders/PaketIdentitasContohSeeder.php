<?php

namespace Database\Seeders;

use App\Core\Http\ApiError;
use App\Core\ProfilRs\KelolaProfilRs;
use App\Core\ProfilRs\ProfilRs;
use App\Modules\Akses\Application\PenjagaPenyunting;
use App\Modules\Akses\Models\Pengguna;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Paket identitas RS untuk instalasi lokal/staging dari folder di LUAR repo (repo publik: nama, berkas, dan warna RS
 * nyata tidak pernah di-commit). PAKET_IDENTITAS_CONTOH_DIR kosong atau foldernya tidak ada = dilewati, identitas tetap
 * netral. Isi folder (profil.json + berkas) disimpan lewat KelolaProfilRs yang sama dengan Pengaturan → Profil rumah
 * sakit (tiket 1.8a): cek kontras warna, pemeriksaan tipe dan isi berkas, versi, dan jejak audit atas nama superadmin
 * aktif. Format folder: docs/brand/README.md.
 */
final class PaketIdentitasContohSeeder extends Seeder
{
    private const JENIS_BERKAS = ['logo', 'motif', 'foto'];

    private const DASAR = 'Paket identitas instalasi lokal/staging (PaketIdentitasContohSeeder)';

    public function run(KelolaProfilRs $kelola, PenjagaPenyunting $penjaga): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('PaketIdentitasContohSeeder ditolak berjalan di production.');
        }
        $dir = trim((string) config('instalasi.identitas.paket_contoh_dir'));
        if ($dir === '' || ! is_dir($dir)) {
            $this->command?->info('Paket identitas dilewati: PAKET_IDENTITAS_CONTOH_DIR kosong atau foldernya tidak ada (identitas netral).');

            return;
        }
        $folder = (string) realpath($dir);
        $paket = $this->bacaProfil($folder);

        $penyunting = Pengguna::query()->where('status', 'aktif')->orderBy('dibuat_pada')->lazy()
            ->first(fn (Pengguna $p) => $penjaga->superadmin($p, termasukMasaDepan: false));
        if ($penyunting === null) {
            throw new RuntimeException('Paket identitas butuh akun superadmin aktif sebagai pelaku; jalankan AkunSintetisSeeder dulu.');
        }

        $profil = ProfilRs::satu();
        try {
            $profil = $kelola->ubah($penyunting, KelolaProfilRs::versi($profil), [
                'nama' => $paket['nama'],
                'kontak_admin' => $paket['kontak_admin'] ?? ['unit' => $profil->kontak_admin_unit ?? 'Instalasi SIMRS', 'kontak' => $profil?->kontak_admin],
                'warna' => $paket['warna'],
                'dasar_perubahan' => self::DASAR,
            ]);
            foreach ($paket['berkas'] as $jenis => $jalur) {
                $profil = $kelola->gantiBerkas($penyunting, KelolaProfilRs::versi($profil), $jenis,
                    new UploadedFile($jalur, basename($jalur), null, null, true));
            }
        } catch (ApiError $e) {
            // Galat yang sama dengan layar 1.8a (kontras kurang, tipe/isi berkas ditolak), dengan rinciannya.
            $rincian = implode(' ', array_filter(array_map(fn (array $r) => $r['pesan'] ?? null, $e->rincian)));
            throw new RuntimeException("Paket identitas ditolak: {$e->getMessage()} $rincian", previous: $e);
        }
        $this->command?->info('Paket identitas dipasang: '.$paket['nama'].' ('.count($paket['berkas']).' berkas).');
    }

    /**
     * profil.json: {"nama", "warna": {"brand", "accent"}, "kontak_admin"?: {"unit", "kontak"}, "berkas"?: {"logo"?,
     * "motif"?, "foto"?}}. Nama berkas relatif terhadap folder dan tidak boleh keluar dari folder itu.
     *
     * @return array{nama: string, warna: array{brand: string, accent: string}, kontak_admin?: array{unit: string, kontak: string|null}, berkas: array<string, string>}
     */
    private function bacaProfil(string $folder): array
    {
        $isi = json_decode((string) @file_get_contents("$folder/profil.json"), true);
        if (! is_array($isi)) {
            throw new RuntimeException('Paket identitas: profil.json tidak ada atau bukan JSON yang sah.');
        }
        $nama = is_string($isi['nama'] ?? null) ? trim($isi['nama']) : '';
        if ($nama === '' || mb_strlen($nama) > 200) {
            throw new RuntimeException('Paket identitas: "nama" wajib diisi (paling panjang 200 karakter).');
        }
        $warna = [];
        foreach (['brand', 'accent'] as $k) {
            $nilai = $isi['warna'][$k] ?? null;
            if (! is_string($nilai) || preg_match('/^#[0-9A-Fa-f]{6}$/', $nilai) !== 1) {
                throw new RuntimeException("Paket identitas: \"warna.$k\" wajib berbentuk #RRGGBB.");
            }
            $warna[$k] = strtoupper($nilai);
        }
        $hasil = ['nama' => $nama, 'warna' => $warna, 'berkas' => []];
        if (isset($isi['kontak_admin'])) {
            $unit = $isi['kontak_admin']['unit'] ?? null;
            $kontak = $isi['kontak_admin']['kontak'] ?? null;
            if (! is_string($unit) || trim($unit) === '' || ! (is_string($kontak) || $kontak === null)) {
                throw new RuntimeException('Paket identitas: "kontak_admin" berisi "unit" (wajib) dan "kontak" (teks atau null).');
            }
            $hasil['kontak_admin'] = ['unit' => trim($unit), 'kontak' => $kontak === null ? null : trim($kontak)];
        }
        foreach ((array) ($isi['berkas'] ?? []) as $jenis => $nama) {
            if (! in_array($jenis, self::JENIS_BERKAS, true) || ! is_string($nama)) {
                throw new RuntimeException('Paket identitas: "berkas" hanya berisi logo, motif, foto dengan nama berkas.');
            }
            $jalur = realpath("$folder/$nama");
            if ($jalur === false || ! is_file($jalur) || ! str_starts_with($jalur, $folder.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException("Paket identitas: berkas $jenis \"$nama\" tidak ada di dalam folder paket.");
            }
            $hasil['berkas'][$jenis] = $jalur;
        }

        return $hasil;
    }
}
