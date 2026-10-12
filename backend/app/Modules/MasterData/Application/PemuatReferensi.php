<?php

namespace App\Modules\MasterData\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pemuat paket referensi (tiket 1.8e, kontrak v0.11.0; FR-54-09, K7). Satu pemuatan = snapshot satu versi satu sistem
 * (referensi: per kelompok di berkas): kode baru ditambah, yang berubah diperbarui, yang hilang dinonaktifkan (tidak
 * dihapus), yang muncul lagi diaktifkan. Versi sama dengan isi sama yang merupakan pemuatan terakhir sistem itu = tidak
 * ada perubahan; isi lain, atau versi yang sudah digantikan = 409.
 *
 * Memori (wilayah ±91 ribu baris, `memory_limit` bawaan 128 MB): baris berkas dan kode yang sudah ada disimpan sebagai
 * satu string per baris, bukan array per baris.
 */
final class PemuatReferensi
{
    public const SISTEM = ['wilayah', 'referensi', 'icd10', 'icd9cm', 'loinc', 'ucum'];

    public const MAKS_BARIS = 120_000;

    private const MAKS_KESALAHAN = 500;

    /** Pemisah kolom di string baris; ditolak bila muncul di berkas. */
    private const PEMISAH = "\x1F";

    /** Baris per upsert (8 kolom = 4.000 binding per query). */
    private const POTONGAN = 500;

    private const NOL = ['baru' => 0, 'diubah' => 0, 'dinonaktifkan' => 0, 'diaktifkan_lagi' => 0];

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
        $ulang = $this->pemuatanUlang($sistem, $paket['versi'], $sidik);
        if ($kesalahan !== [] && $terapkan) {
            throw new ApiError(422, 'muat_tidak_valid', 'Berkas punya baris yang salah; tidak ada yang dimuat.', array_slice($kesalahan, 0, self::MAKS_KESALAHAN));
        }
        // Pemuatan ulang versi terakhir dengan isi sama: idempoten, tanpa perubahan (kontrak v0.11.0).
        $hitung = self::NOL;
        if ($kesalahan === [] && ! $ulang) {
            $hitung = $terapkan
                ? $this->terapkan($sistem, $baris, $paket, $sidik, $olehId)
                : $this->bandingkan($sistem, $baris, $this->yangAda($sistem, $baris));
        }

        return [
            'sistem' => $sistem, 'versi' => $paket['versi'], 'mode' => $terapkan ? 'terapkan' : 'pratinjau', 'diterapkan' => $terapkan,
            'jumlah_baris' => count($baris), 'jumlah_salah' => count(array_unique(array_column($kesalahan, 'baris'))), 'jumlah_baru' => $hitung['baru'], 'jumlah_diubah' => $hitung['diubah'],
            'jumlah_dinonaktifkan' => $hitung['dinonaktifkan'], 'jumlah_diaktifkan_lagi' => $hitung['diaktifkan_lagi'],
            'kesalahan' => array_slice($kesalahan, 0, self::MAKS_KESALAHAN), 'kesalahan_terpotong' => count($kesalahan) > self::MAKS_KESALAHAN,
        ];
    }

    /**
     * @param  array<int, string>  $baris
     * @param  array{versi: string, sumber: string, catatan_lisensi?: string|null}  $paket
     * @return array{baru: int, diubah: int, dinonaktifkan: int, diaktifkan_lagi: int}
     */
    private function terapkan(string $sistem, array $baris, array $paket, string $sidik, ?string $olehId): array
    {
        return DB::transaction(function () use ($sistem, $baris, $paket, $olehId, $sidik) {
            // Pemuatan besar (wilayah ±90 ribu baris) melebihi batas 5 detik role aplikasi: batas khusus transaksi ini.
            DB::statement("SET LOCAL statement_timeout = '180s'");
            // Pemeriksaan FK induk wilayah berjalan per baris dengan rencana yang di-cache saat tabel masih kecil (seq scan):
            // tanpa ini memuat ulang ±90 ribu baris menjadi kuadratik. Rencana custom mengikuti ukuran tabel saat itu.
            DB::statement("SET LOCAL plan_cache_mode = 'force_custom_plan'");
            DB::statement("SELECT pg_advisory_xact_lock(hashtext('referensi:muat:' || ?))", [$sistem]);
            // Diperiksa ulang di bawah kunci: pemuatan bersamaan versi yang sama.
            if ($this->pemuatanUlang($sistem, $paket['versi'], $sidik)) {
                return self::NOL;
            }
            $ada = $this->yangAda($sistem, $baris);
            $hitung = $this->bandingkan($sistem, $baris, $ada);
            $this->simpan($sistem, $baris, $ada, $paket['versi']);
            DB::table('inti.paket_referensi')->insert([
                'id' => (string) Str::uuid7(), 'sistem' => $sistem, 'versi' => $paket['versi'], 'sumber' => $paket['sumber'],
                'catatan_lisensi' => $paket['catatan_lisensi'] ?? null, 'jumlah_kode' => count($baris), 'sidik_isi' => $sidik,
                'dimuat_pada' => now(), 'dimuat_oleh' => $olehId,
            ]);
            $this->audit->catat('master_data.referensi_dimuat', $olehId, 'inti.paket_referensi', null, [
                'sistem' => $sistem, 'versi' => $paket['versi'], 'sumber' => $paket['sumber'], 'jumlah_kode' => count($baris), ...$hitung,
            ]);

            return $hitung;
        });
    }

    /**
     * true = versi ini sudah dimuat dengan isi sama dan merupakan pemuatan terakhir sistem itu (idempoten). Versi yang
     * sudah dimuat dengan isi lain, atau sudah digantikan versi lain = 409.
     */
    private function pemuatanUlang(string $sistem, string $versi, string $sidik): bool
    {
        $ada = DB::table('inti.paket_referensi')->where('sistem', $sistem)->where('versi', $versi)->first(['id', 'sidik_isi']);
        if ($ada === null) {
            return false;
        }
        if ($ada->sidik_isi !== $sidik) {
            throw new ApiError(409, 'versi_sudah_dimuat', "Versi $versi sistem ini sudah dimuat dengan isi yang berbeda. Pakai nomor versi lain.");
        }
        $terakhir = DB::table('inti.paket_referensi')->where('sistem', $sistem)->orderByDesc('dimuat_pada')->orderByDesc('id')->value('id');
        if ($terakhir !== $ada->id) {
            throw new ApiError(409, 'versi_sudah_dimuat', "Versi $versi sudah digantikan versi yang dimuat sesudahnya. Pakai nomor versi baru.");
        }

        return true;
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

    /** @return array<int, string> nomor baris => kolom digabung PEMISAH (lihat pecah()) */
    private function baca(string $sistem, string $isi): array
    {
        $kolom = self::kolom($sistem);
        $isi = preg_replace('/^\xEF\xBB\xBF/', '', $isi) ?? '';
        if (! mb_check_encoding($isi, 'UTF-8') || str_contains($isi, "\0") || str_contains($isi, self::PEMISAH)) {
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
            $baris[$nomor] = implode(self::PEMISAH, array_map(fn ($n) => trim((string) $n), array_pad(array_slice($isiBaris, 0, count($kolom)), count($kolom), '')));
        }
        fclose($aliran);
        if ($baris === []) {
            throw $this->berkasTidakValid('Berkas tidak berisi baris data.');
        }

        return $baris;
    }

    /**
     * @param  array<int, string>  $baris
     * @return list<array{baris: int, kolom: string|null, kode: string, pesan: string}>
     */
    private function periksa(string $sistem, array $baris): array
    {
        $kesalahan = [];
        $salah = function (int $n, ?string $kolom, string $kode, string $pesan) use (&$kesalahan) {
            $kesalahan[] = ['baris' => $n, 'kolom' => $kolom, 'kode' => $kode, 'pesan' => $pesan];
        };
        $kunci = [];
        foreach ($baris as $n => $isiBaris) {
            $b = $this->pecah($sistem, $isiBaris);
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
            foreach ($baris as $n => $isiBaris) {
                $b = $this->pecah($sistem, $isiBaris);
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
     * @param  array<int, string>  $baris
     * @param  array<string, string>  $ada  dari yangAda()
     * @return array{baru: int, diubah: int, dinonaktifkan: int, diaktifkan_lagi: int}
     */
    private function bandingkan(string $sistem, array $baris, array $ada): array
    {
        $hitung = self::NOL;
        foreach ($baris as $isiBaris) {
            $b = $this->pecah($sistem, $isiBaris);
            $k = $this->kunci($sistem, $b);
            $lama = $ada[$k] ?? null;
            if ($lama === null) {
                $hitung['baru']++;

                continue;
            }
            unset($ada[$k]); // yang tersisa = kode yang tidak ada di berkas
            if ($lama[0] !== '1') {
                $hitung['diaktifkan_lagi']++;
            } elseif (substr($lama, 37) !== $this->isi($sistem, $b)) {
                $hitung['diubah']++;
            }
        }
        foreach ($ada as $lama) {
            if ($lama[0] === '1') {
                $hitung['dinonaktifkan']++;
            }
        }

        return $hitung;
    }

    /**
     * @param  array<int, string>  $baris
     * @param  array<string, string>  $ada  dari yangAda()
     */
    private function simpan(string $sistem, array $baris, array $ada, string $versi): void
    {
        // Waktu dari basis data (now() = awal transaksi), bukan binding per baris: binding ikut tertahan listener debug Laravel.
        $sekarang = DB::raw('now()');
        $induk = []; // wilayah: kode => id, hanya tingkat yang bisa menjadi induk (provinsi s.d. kecamatan)
        [$tabel, $unik, $ubah] = match ($sistem) {
            'wilayah' => ['inti.wilayah', ['kode_kemendagri'], ['tingkat', 'nama', 'induk_id']],
            'referensi' => ['inti.referensi', ['kelompok', 'kode'], ['label']],
            default => ['inti.terminologi', ['sistem', 'kode'], ['tampilan', 'kode_induk']],
        };
        $ubah = [...$ubah, 'aktif', 'versi_terakhir', 'dinonaktifkan_pada', 'diubah_pada'];
        $potong = [];
        foreach ($this->urutan($sistem, $baris) as $isiBaris) {
            $b = $this->pecah($sistem, $isiBaris);
            $k = $this->kunci($sistem, $b);
            $idBaris = isset($ada[$k]) ? substr($ada[$k], 1, 36) : (string) Str::uuid7();
            unset($ada[$k]); // yang tersisa = kode yang tidak ada di berkas
            $umum = ['id' => $idBaris, 'aktif' => true, 'versi_terakhir' => $versi, 'dinonaktifkan_pada' => null, 'diubah_pada' => $sekarang];
            $potong[] = match ($sistem) {
                'wilayah' => [...$umum, 'kode_kemendagri' => $b['kode'], 'tingkat' => self::TINGKAT[substr_count($b['kode'], '.') + 1],
                    'nama' => $b['nama'], 'induk_id' => ($i = $this->indukWilayah($b['kode'])) === null ? null : ($induk[$i] ?? null)],
                'referensi' => [...$umum, 'kelompok' => $b['kelompok'], 'kode' => $b['kode'], 'label' => $b['label']],
                default => [...$umum, 'sistem' => $sistem, 'kode' => $b['kode'], 'tampilan' => $b['tampilan'], 'kode_induk' => $b['kode_induk'] !== '' ? $b['kode_induk'] : null],
            };
            if ($sistem === 'wilayah' && substr_count($b['kode'], '.') < 3) {
                $induk[$b['kode']] = $idBaris;
            }
            if (count($potong) >= self::POTONGAN) {
                DB::table($tabel)->upsert($potong, $unik, $ubah);
                $potong = [];
            }
        }
        if ($potong !== []) {
            DB::table($tabel)->upsert($potong, $unik, $ubah);
        }
        $hilang = [];
        foreach ($ada as $lama) {
            if ($lama[0] === '1') {
                $hilang[] = substr($lama, 1, 36);
            }
        }
        foreach (array_chunk($hilang, self::POTONGAN) as $potong) {
            DB::table($tabel)->whereIn('id', $potong)->update(['aktif' => false, 'dinonaktifkan_pada' => $sekarang, 'diubah_pada' => $sekarang]);
        }
    }

    /**
     * Urutan simpan. Wilayah: induk lebih dulu (tingkat naik, urutan berkas di tiap tingkat) agar induk_id bisa diisi.
     *
     * @param  array<int, string>  $baris
     * @return \Generator<int, string>
     */
    private function urutan(string $sistem, array $baris): \Generator
    {
        if ($sistem !== 'wilayah') {
            yield from $baris;

            return;
        }
        for ($titik = 0; $titik <= 3; $titik++) {
            foreach ($baris as $n => $isiBaris) {
                if (substr_count((string) strstr($isiBaris, self::PEMISAH, true), '.') === $titik) {
                    yield $n => $isiBaris;
                }
            }
        }
    }

    /**
     * Kode yang sudah ada untuk sistem ini (referensi: hanya kelompok yang ada di berkas), satu string per kode:
     * '1'/'0' (aktif) + id (36 karakter) + isi().
     *
     * @param  array<int, string>  $baris
     * @return array<string, string> kunci() => string padat
     */
    private function yangAda(string $sistem, array $baris): array
    {
        $hasil = [];
        $query = match ($sistem) {
            'wilayah' => DB::table('inti.wilayah')->select(['id', 'aktif', 'kode_kemendagri AS kode', 'nama']),
            'referensi' => DB::table('inti.referensi')->whereIn('kelompok', array_keys(array_flip(array_map(fn ($s) => (string) strstr($s, self::PEMISAH, true), $baris))))->select(['id', 'aktif', 'kelompok', 'kode', 'label']),
            default => DB::table('inti.terminologi')->where('sistem', $sistem)->select(['id', 'aktif', 'kode', 'tampilan', 'kode_induk']),
        };
        foreach ($query->cursor() as $r) {
            $b = match ($sistem) {
                'wilayah' => ['kode' => $r->kode, 'nama' => $r->nama],
                'referensi' => ['kelompok' => $r->kelompok, 'kode' => $r->kode, 'label' => $r->label],
                default => ['kode' => $r->kode, 'tampilan' => $r->tampilan, 'kode_induk' => (string) $r->kode_induk],
            };
            $hasil[$this->kunci($sistem, $b)] = ($r->aktif ? '1' : '0').$r->id.$this->isi($sistem, $b);
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

    /** SHA-256 isi ternormalisasi (baris kunci + isi, terurut): versi sama dengan isi sama = idempoten. @param  array<int, string>  $baris */
    private function sidik(string $sistem, array $baris): string
    {
        $isi = [];
        foreach ($baris as $isiBaris) {
            $b = $this->pecah($sistem, $isiBaris);
            $isi[] = $this->kunci($sistem, $b).self::PEMISAH.$this->isi($sistem, $b);
        }
        sort($isi, SORT_STRING);
        $hash = hash_init('sha256');
        foreach ($isi as $i) {
            hash_update($hash, $i."\n");
        }

        return hash_final($hash);
    }

    /** @return array<string, string> */
    private function pecah(string $sistem, string $baris): array
    {
        return array_combine(self::kolom($sistem), explode(self::PEMISAH, $baris));
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
