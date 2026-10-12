<?php

namespace App\Modules\MasterData\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pemuat paket referensi (tiket 1.8e, kontrak v0.11.0; FR-54-09, K7). Satu pemuatan = snapshot satu versi satu sistem
 * (referensi: per kelompok di berkas): kode baru ditambah, yang berubah diperbarui, yang hilang dinonaktifkan (tidak
 * dihapus), yang muncul lagi diaktifkan. Versi yang sama dengan isi sama = tidak ada perubahan; isi lain = 409.
 */
final class PemuatReferensi
{
    public const SISTEM = ['wilayah', 'referensi', 'icd10', 'icd9cm', 'loinc', 'ucum'];

    public const MAKS_BARIS = 120_000;

    private const MAKS_KESALAHAN = 500;

    private const TINGKAT = [1 => 'provinsi', 2 => 'kabupaten', 3 => 'kecamatan', 4 => 'kelurahan'];

    public function __construct(private readonly CatatAktivitas $audit) {}

    /**
     * @param  array{versi: string, sumber: string, catatan_lisensi?: string|null}  $paket
     * @return array<string, mixed> HasilMuatReferensi
     */
    public function muat(string $sistem, string $isiBerkas, array $paket, bool $terapkan, ?string $olehId): array
    {
        $baris = $this->baca($sistem, $isiBerkas);
        $kesalahan = $this->periksa($sistem, $baris);
        $sidik = $this->sidik($sistem, $baris);
        $ada = DB::table('inti.paket_referensi')->where('sistem', $sistem)->where('versi', $paket['versi'])->first();
        if ($ada !== null && $ada->sidik_isi !== $sidik) {
            throw new ApiError(409, 'versi_sudah_dimuat', "Versi {$paket['versi']} sistem ini sudah dimuat dengan isi yang berbeda. Pakai nomor versi lain.");
        }
        if ($kesalahan !== [] && $terapkan) {
            throw new ApiError(422, 'muat_tidak_valid', 'Berkas punya baris yang salah; tidak ada yang dimuat.', array_slice($kesalahan, 0, self::MAKS_KESALAHAN));
        }
        // Versi yang sama dengan isi sama sudah dimuat: idempoten, tanpa perubahan (kontrak v0.11.0).
        $hitung = $kesalahan === [] && $ada === null ? $this->bandingkan($sistem, $baris) : ['baru' => 0, 'diubah' => 0, 'dinonaktifkan' => 0, 'diaktifkan_lagi' => 0];
        $diterapkan = false;
        if ($terapkan && $ada === null) {
            DB::transaction(function () use ($sistem, $baris, $paket, $olehId, $sidik, $hitung) {
                // Pemuatan besar (wilayah ±90 ribu baris) melebihi batas 5 detik role aplikasi: batas khusus transaksi ini.
                DB::statement("SET LOCAL statement_timeout = '180s'");
                DB::statement("SELECT pg_advisory_xact_lock(hashtext('referensi:muat:' || ?))", [$sistem]);
                $this->simpan($sistem, $baris, $paket['versi']);
                DB::table('inti.paket_referensi')->insert([
                    'id' => (string) Str::uuid7(), 'sistem' => $sistem, 'versi' => $paket['versi'], 'sumber' => $paket['sumber'],
                    'catatan_lisensi' => $paket['catatan_lisensi'] ?? null, 'jumlah_kode' => count($baris), 'sidik_isi' => $sidik,
                    'dimuat_pada' => now(), 'dimuat_oleh' => $olehId,
                ]);
                $this->audit->catat('master_data.referensi_dimuat', $olehId, 'inti.paket_referensi', null, [
                    'sistem' => $sistem, 'versi' => $paket['versi'], 'sumber' => $paket['sumber'], 'jumlah_kode' => count($baris), ...$hitung,
                ]);
            });
            $diterapkan = true;
        }

        return [
            'sistem' => $sistem, 'versi' => $paket['versi'], 'mode' => $terapkan ? 'terapkan' : 'pratinjau', 'diterapkan' => $diterapkan || ($terapkan && $ada !== null),
            'jumlah_baris' => count($baris), 'jumlah_salah' => count(array_unique(array_column($kesalahan, 'baris'))), 'jumlah_baru' => $hitung['baru'], 'jumlah_diubah' => $hitung['diubah'],
            'jumlah_dinonaktifkan' => $hitung['dinonaktifkan'], 'jumlah_diaktifkan_lagi' => $hitung['diaktifkan_lagi'],
            'kesalahan' => array_slice($kesalahan, 0, self::MAKS_KESALAHAN), 'kesalahan_terpotong' => count($kesalahan) > self::MAKS_KESALAHAN,
        ];
    }

    /** @return list<string> */
    public static function kolom(string $sistem): array
    {
        return match ($sistem) {
            'wilayah' => ['kode', 'nama'],
            'referensi' => ['kelompok', 'kode', 'label'],
            default => ['kode', 'tampilan', 'kode_induk'],
        };
    }

    /** @return array<int, array<string, string>> nomor baris => kolom */
    private function baca(string $sistem, string $isi): array
    {
        $kolom = self::kolom($sistem);
        $isi = preg_replace('/^\xEF\xBB\xBF/', '', $isi) ?? '';
        if (! mb_check_encoding($isi, 'UTF-8') || str_contains($isi, "\0")) {
            throw $this->berkasTidakValid('Berkas harus CSV berenkode UTF-8.');
        }
        $aliran = fopen('php://memory', 'r+');
        fwrite($aliran, $isi);
        rewind($aliran);
        $barisPertama = strtok($isi, "\n");
        $pemisah = is_string($barisPertama) && str_contains($barisPertama, ';') && ! str_contains($barisPertama, ',') ? ';' : ',';
        $judul = fgetcsv($aliran, separator: $pemisah, escape: '');
        if ($judul === false || array_map(fn ($k) => trim((string) $k), $judul) !== $kolom) {
            fclose($aliran);
            throw $this->berkasTidakValid('Judul kolom harus: '.implode(',', $kolom).'.');
        }
        $baris = [];
        $nomor = 1;
        while (($isiBaris = fgetcsv($aliran, separator: $pemisah, escape: '')) !== false) {
            $nomor++;
            if ($isiBaris === [null]) {
                continue;
            }
            if (count($baris) >= self::MAKS_BARIS) {
                fclose($aliran);
                throw $this->berkasTidakValid('Paling banyak '.self::MAKS_BARIS.' baris data per berkas.');
            }
            $baris[$nomor] = array_combine($kolom, array_map(fn ($n) => trim((string) $n), array_pad(array_slice($isiBaris, 0, count($kolom)), count($kolom), '')));
        }
        fclose($aliran);
        if ($baris === []) {
            throw $this->berkasTidakValid('Berkas tidak berisi baris data.');
        }

        return $baris;
    }

    /**
     * @param  array<int, array<string, string>>  $baris
     * @return list<array{baris: int, kolom: string|null, kode: string, pesan: string}>
     */
    private function periksa(string $sistem, array $baris): array
    {
        $kesalahan = [];
        $salah = function (int $n, ?string $kolom, string $kode, string $pesan) use (&$kesalahan) {
            $kesalahan[] = ['baris' => $n, 'kolom' => $kolom, 'kode' => $kode, 'pesan' => $pesan];
        };
        $kunci = [];
        foreach ($baris as $n => $b) {
            foreach (self::kolom($sistem) as $k) {
                if ($b[$k] === '' && $k !== 'kode_induk') {
                    $salah($n, $k, 'kolom_wajib', "Kolom $k wajib diisi.");

                    continue 2;
                }
            }
            $valid = match ($sistem) {
                'wilayah' => preg_match('/^\d{2}(\.\d{2}(\.\d{2}(\.\d{4})?)?)?$/', $b['kode']) === 1,
                'referensi' => preg_match('/^[a-z][a-z0-9_]{1,49}$/', $b['kelompok']) === 1 && mb_strlen($b['kode']) <= 50,
                default => mb_strlen($b['kode']) <= 50 && preg_match('/^\S+$/u', $b['kode']) === 1,
            };
            if (! $valid) {
                $salah($n, $sistem === 'referensi' && preg_match('/^[a-z][a-z0-9_]{1,49}$/', $b['kelompok']) !== 1 ? 'kelompok' : 'kode', 'kode_tidak_valid', 'Bentuk kode tidak sesuai.');

                continue;
            }
            $teks = $b['nama'] ?? $b['label'] ?? $b['tampilan'] ?? '';
            if (mb_strlen($teks) > 1000) {
                $salah($n, $sistem === 'wilayah' ? 'nama' : ($sistem === 'referensi' ? 'label' : 'tampilan'), 'nilai_tidak_valid', 'Teks paling panjang 1000 karakter.');

                continue;
            }
            $k = $this->kunci($sistem, $b);
            if (isset($kunci[$k])) {
                $salah($n, 'kode', 'kode_ganda', "Kode sama dengan baris {$kunci[$k]}.");

                continue;
            }
            $kunci[$k] = $n;
        }
        // Induk: ada di berkas yang sama (snapshot), selain referensi yang tidak berinduk.
        if ($sistem !== 'referensi') {
            foreach ($baris as $n => $b) {
                $induk = $sistem === 'wilayah' ? $this->indukWilayah($b['kode']) : ($b['kode_induk'] !== '' ? $b['kode_induk'] : null);
                if ($induk !== null && ! isset($kunci[$induk])) {
                    $salah($n, $sistem === 'wilayah' ? 'kode' : 'kode_induk', 'induk_tidak_ditemukan', "Induk $induk tidak ada di berkas versi ini.");
                }
            }
        }
        usort($kesalahan, fn ($a, $b) => $a['baris'] <=> $b['baris']);

        return $kesalahan;
    }

    /**
     * Hitung perubahan terhadap basis data.
     *
     * @param  array<int, array<string, string>>  $baris
     * @return array{baru: int, diubah: int, dinonaktifkan: int, diaktifkan_lagi: int}
     */
    private function bandingkan(string $sistem, array $baris): array
    {
        $ada = $this->yangAda($sistem, $baris);
        $hitung = ['baru' => 0, 'diubah' => 0, 'dinonaktifkan' => 0, 'diaktifkan_lagi' => 0];
        $diBerkas = [];
        foreach ($baris as $b) {
            $k = $this->kunci($sistem, $b);
            $diBerkas[$k] = true;
            $lama = $ada[$k] ?? null;
            if ($lama === null) {
                $hitung['baru']++;

                continue;
            }
            if (! $lama['aktif']) {
                $hitung['diaktifkan_lagi']++;
            } elseif ($lama['isi'] !== $this->isi($sistem, $b)) {
                $hitung['diubah']++;
            }
        }
        foreach ($ada as $k => $lama) {
            if ($lama['aktif'] && ! isset($diBerkas[$k])) {
                $hitung['dinonaktifkan']++;
            }
        }

        return $hitung;
    }

    /** @param  array<int, array<string, string>>  $baris */
    private function simpan(string $sistem, array $baris, string $versi): void
    {
        $sekarang = now();
        $ada = $this->yangAda($sistem, $baris);
        $diBerkas = [];
        if ($sistem === 'wilayah') {
            // Induk lebih dulu (tingkat naik) agar induk_id bisa diisi.
            uasort($baris, fn ($a, $b) => substr_count($a['kode'], '.') <=> substr_count($b['kode'], '.'));
            $id = array_map(fn ($l) => $l['id'], $ada);
        }
        [$tabel, $unik, $ubah] = match ($sistem) {
            'wilayah' => ['inti.wilayah', ['kode_kemendagri'], ['tingkat', 'nama', 'induk_id']],
            'referensi' => ['inti.referensi', ['kelompok', 'kode'], ['label']],
            default => ['inti.terminologi', ['sistem', 'kode'], ['tampilan', 'kode_induk']],
        };
        // Ditulis per potongan 1.000 baris agar memori tetap kecil (wilayah ±91 ribu baris).
        $potong = [];
        $tulis = function () use (&$potong, $tabel, $unik, $ubah) {
            if ($potong !== []) {
                DB::table($tabel)->upsert($potong, $unik, [...$ubah, 'aktif', 'versi_terakhir', 'dinonaktifkan_pada', 'diubah_pada']);
                $potong = [];
            }
        };
        foreach ($baris as $b) {
            $k = $this->kunci($sistem, $b);
            $diBerkas[$k] = true;
            $idBaris = $ada[$k]['id'] ?? (string) Str::uuid7();
            $umum = ['id' => $idBaris, 'aktif' => true, 'versi_terakhir' => $versi, 'dinonaktifkan_pada' => null, 'diubah_pada' => $sekarang];
            $potong[] = match ($sistem) {
                'wilayah' => [...$umum, 'kode_kemendagri' => $b['kode'], 'tingkat' => self::TINGKAT[substr_count($b['kode'], '.') + 1],
                    'nama' => $b['nama'], 'induk_id' => ($i = $this->indukWilayah($b['kode'])) === null ? null : ($id[$i] ?? null)],
                'referensi' => [...$umum, 'kelompok' => $b['kelompok'], 'kode' => $b['kode'], 'label' => $b['label']],
                default => [...$umum, 'sistem' => $sistem, 'kode' => $b['kode'], 'tampilan' => $b['tampilan'], 'kode_induk' => $b['kode_induk'] !== '' ? $b['kode_induk'] : null],
            };
            if ($sistem === 'wilayah') {
                $id[$b['kode']] = $idBaris;
            }
            if (count($potong) >= 1000) {
                $tulis();
            }
        }
        $tulis();
        $hilang = array_values(array_map(fn ($l) => $l['id'], array_filter($ada, fn ($l, $k) => $l['aktif'] && ! isset($diBerkas[$k]), ARRAY_FILTER_USE_BOTH)));
        foreach (array_chunk($hilang, 1000) as $potong) {
            DB::table($tabel)->whereIn('id', $potong)->update(['aktif' => false, 'dinonaktifkan_pada' => $sekarang, 'diubah_pada' => $sekarang]);
        }
    }

    /**
     * Kode yang sudah ada untuk sistem ini (referensi: hanya kelompok yang ada di berkas).
     *
     * @param  array<int, array<string, string>>  $baris
     * @return array<string, array{id: string, aktif: bool, isi: string}>
     */
    private function yangAda(string $sistem, array $baris): array
    {
        $hasil = [];
        $query = match ($sistem) {
            'wilayah' => DB::table('inti.wilayah')->select(['id', 'aktif', 'kode_kemendagri AS kode', 'nama', 'induk_id']),
            'referensi' => DB::table('inti.referensi')->whereIn('kelompok', array_values(array_unique(array_column($baris, 'kelompok'))))->select(['id', 'aktif', 'kelompok', 'kode', 'label']),
            default => DB::table('inti.terminologi')->where('sistem', $sistem)->select(['id', 'aktif', 'kode', 'tampilan', 'kode_induk']),
        };
        foreach ($query->cursor() as $r) {
            $b = match ($sistem) {
                'wilayah' => ['kode' => $r->kode, 'nama' => $r->nama],
                'referensi' => ['kelompok' => $r->kelompok, 'kode' => $r->kode, 'label' => $r->label],
                default => ['kode' => $r->kode, 'tampilan' => $r->tampilan, 'kode_induk' => (string) $r->kode_induk],
            };
            $hasil[$this->kunci($sistem, $b)] = ['id' => $r->id, 'aktif' => (bool) $r->aktif, 'isi' => $this->isi($sistem, $b)];
        }

        return $hasil;
    }

    /** @param  array<string, string>  $b */
    private function kunci(string $sistem, array $b): string
    {
        return $sistem === 'referensi' ? $b['kelompok']."\x1F".$b['kode'] : $b['kode'];
    }

    /** Isi yang dibandingkan antarversi (nama/label/tampilan dan induk). @param  array<string, string>  $b */
    private function isi(string $sistem, array $b): string
    {
        return match ($sistem) {
            'wilayah' => $b['nama'],
            'referensi' => $b['label'],
            default => $b['tampilan']."\x1F".$b['kode_induk'],
        };
    }

    /** SHA-256 isi ternormalisasi (urut kunci): versi sama dengan isi sama = idempoten. @param  array<int, array<string, string>>  $baris */
    private function sidik(string $sistem, array $baris): string
    {
        $isi = [];
        foreach ($baris as $b) {
            $isi[$this->kunci($sistem, $b)] = $this->isi($sistem, $b);
        }
        ksort($isi, SORT_STRING);

        return hash('sha256', (string) json_encode($isi, JSON_UNESCAPED_UNICODE));
    }

    private function indukWilayah(string $kode): ?string
    {
        $titik = strrpos($kode, '.');

        return $titik === false ? null : substr($kode, 0, $titik);
    }

    private function berkasTidakValid(string $pesan): ApiError
    {
        return new ApiError(422, 'berkas_tidak_valid', $pesan, [['kolom' => 'berkas', 'pesan' => $pesan]]);
    }
}
