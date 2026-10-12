<?php

namespace Database\Seeders;

use App\Core\Http\ApiError;
use App\Core\ProfilRs\KelolaProfilRs;
use App\Core\ProfilRs\KontrasWarna;
use App\Core\ProfilRs\PemeriksaBerkas;
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
 * aktif. Semua isian dan berkas diperiksa lebih dulu, jadi paket yang ditolak tidak mengubah profil sama sekali; yang
 * sudah sama tidak disimpan ulang (menjalankan ulang tidak menambah revisi). Format folder: docs/brand/README.md.
 */
final class PaketIdentitasContohSeeder extends Seeder
{
    private const JENIS_BERKAS = ['logo', 'motif', 'foto'];

    private const DASAR = 'Paket identitas instalasi lokal/staging (PaketIdentitasContohSeeder)';

    public function run(KelolaProfilRs $kelola, PemeriksaBerkas $pemeriksa, PenjagaPenyunting $penjaga): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('PaketIdentitasContohSeeder ditolak berjalan di production.');
        }
        $dir = trim((string) config('instalasi.identitas.paket_contoh_dir'));
        if (str_contains($dir, "\0")) {
            throw new RuntimeException('Paket identitas: PAKET_IDENTITAS_CONTOH_DIR berisi karakter yang tidak sah.');
        }
        if ($dir === '' || ! is_dir($dir)) {
            $this->command?->info('Paket identitas dilewati: PAKET_IDENTITAS_CONTOH_DIR kosong atau foldernya tidak ada (identitas netral).');

            return;
        }
        $paket = $this->bacaProfil((string) realpath($dir));

        // Periksa semua lebih dulu (kontras dan berkas) dengan aturan layar 1.8a: paket yang ditolak tidak mengubah apa pun.
        $berkas = [];
        try {
            $kurang = KontrasWarna::periksa($paket['warna']);
            if ($kurang !== []) {
                throw new ApiError(422, 'kontras_kurang', 'Kontras warna tidak cukup untuk dibaca.', $kurang);
            }
            foreach ($paket['berkas'] as $jenis => $jalur) {
                $batas = (int) config("instalasi.identitas.batas_kb.$jenis") * 1024;
                if ((int) filesize($jalur) > $batas) {
                    throw new ApiError(422, 'berkas_terlalu_besar', 'Berkas terlalu besar.', [['kolom' => 'berkas', 'pesan' => sprintf('Maksimum %d KB untuk %s.', $batas / 1024, $jenis)]]);
                }
                $berkas[$jenis] = ['jalur' => $jalur, 'sha256' => hash('sha256', $pemeriksa->periksa($jenis, (string) file_get_contents($jalur))['isi'])];
            }
        } catch (ApiError $e) {
            throw $this->ditolak($e);
        }

        $penyunting = Pengguna::query()->where('status', 'aktif')->orderBy('dibuat_pada')->lazy()
            ->first(fn (Pengguna $p) => $penjaga->superadmin($p, termasukMasaDepan: false));
        if ($penyunting === null) {
            throw new RuntimeException('Paket identitas butuh akun superadmin aktif sebagai pelaku; jalankan AkunSintetisSeeder dulu.');
        }

        $profil = ProfilRs::satu();
        $kontak = $paket['kontak_admin'] ?? ['unit' => $profil->kontak_admin_unit ?? 'admin SIMRS', 'kontak' => $profil?->kontak_admin];
        $diubah = 0;
        try {
            $sama = $profil !== null && $profil->nama === $paket['nama'] && ($profil->paket_identitas['warna'] ?? null) == $paket['warna']
                && $profil->kontak_admin_unit === $kontak['unit'] && $profil->kontak_admin === $kontak['kontak'];
            if (! $sama) {
                $profil = $kelola->ubah($penyunting, KelolaProfilRs::versi($profil), [
                    'nama' => $paket['nama'], 'kontak_admin' => $kontak, 'warna' => $paket['warna'], 'dasar_perubahan' => self::DASAR,
                ]);
                $diubah++;
            }
            foreach ($berkas as $jenis => ['jalur' => $jalur, 'sha256' => $sha]) {
                if (($profil->paket_identitas[$jenis]['sha256'] ?? null) === $sha) {
                    continue;
                }
                $profil = $kelola->gantiBerkas($penyunting, KelolaProfilRs::versi($profil), $jenis, new UploadedFile($jalur, basename($jalur), null, null, true));
                $diubah++;
            }
        } catch (ApiError $e) {
            throw $this->ditolak($e);
        }
        $this->command?->info($diubah === 0
            ? 'Paket identitas sudah terpasang; tidak ada perubahan.'
            : 'Paket identitas dipasang: '.$paket['nama']." ($diubah perubahan, atas nama {$penyunting->nama_pengguna}).");
    }

    /**
     * profil.json: {"nama", "warna": {"brand", "accent"}, "kontak_admin"?: {"unit", "kontak"}, "berkas"?: {"logo"?,
     * "motif"?, "foto"?}}. Batas isian sama dengan PUT /profil-rs; nama berkas relatif terhadap folder dan tidak boleh
     * keluar dari folder itu (juga lewat symlink).
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
        if (mb_strlen($nama) < 3 || mb_strlen($nama) > 200) {
            throw new RuntimeException('Paket identitas: "nama" wajib diisi, 3–200 karakter.');
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
            $unit = is_string($isi['kontak_admin']['unit'] ?? null) ? trim($isi['kontak_admin']['unit']) : '';
            $kontak = $isi['kontak_admin']['kontak'] ?? null;
            $kontak = is_string($kontak) ? (trim($kontak) === '' ? null : trim($kontak)) : $kontak;
            if (mb_strlen($unit) < 2 || mb_strlen($unit) > 100 || ! ($kontak === null || (is_string($kontak) && mb_strlen($kontak) <= 100))) {
                throw new RuntimeException('Paket identitas: "kontak_admin.unit" wajib 2–100 karakter; "kontak" teks paling panjang 100 karakter atau null.');
            }
            $hasil['kontak_admin'] = ['unit' => $unit, 'kontak' => $kontak];
        }
        foreach ((array) ($isi['berkas'] ?? []) as $jenis => $namaBerkas) {
            if (! in_array($jenis, self::JENIS_BERKAS, true) || ! is_string($namaBerkas) || $namaBerkas === '' || str_contains($namaBerkas, "\0")) {
                throw new RuntimeException('Paket identitas: "berkas" hanya berisi logo, motif, foto dengan nama berkas.');
            }
            $jalur = realpath("$folder/$namaBerkas");
            if ($jalur === false || ! is_file($jalur) || ! str_starts_with($jalur, $folder.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException("Paket identitas: berkas $jenis \"$namaBerkas\" tidak ada di dalam folder paket.");
            }
            $hasil['berkas'][$jenis] = $jalur;
        }

        return $hasil;
    }

    /** Galat layanan profil RS (sama dengan layar 1.8a) dengan rinciannya. */
    private function ditolak(ApiError $e): RuntimeException
    {
        $rincian = implode(' ', array_filter(array_map(fn (array $r) => $r['pesan'] ?? null, $e->rincian)));

        return new RuntimeException("Paket identitas ditolak: {$e->getMessage()} $rincian", previous: $e);
    }
}
