<?php

namespace App\Modules\MasterData\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Events\KapasitasTempatTidurBerubah;
use App\Modules\MasterData\Models\Lokasi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Impor lokasi, kamar, dan TT dari CSV (tiket 1.8b, FR-54-14; kontrak v0.5.0 /lokasi/impor). Pratinjau tidak menyimpan
 * apa pun; terapkan memeriksa ulang di bawah kunci pohon lalu menyimpan semuanya dalam satu transaksi, atau tidak
 * sama sekali. kode_lengkap baris = induk + "-" + kode, jadi rujukan melingkar tidak bisa membentuk pohon: berakhir
 * sebagai induk yang tidak ditemukan.
 */
final class ImporLokasi
{
    public const KOLOM = ['jenis', 'kode', 'induk', 'nama', 'fungsi', 'unit', 'bangsal', 'kelas_rawat', 'kapasitas_maksimum', 'ekstra'];

    public const MAKS_BARIS = 5000;

    private const MAKS_KESALAHAN = 500;

    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly PohonLokasi $pohon,
    ) {}

    /** @return array<string, mixed> HasilImporLokasi */
    public function pratinjau(string $isiBerkas): array
    {
        $baris = $this->baca($isiBerkas);

        return $this->hasil('pratinjau', false, $baris, $this->periksa($baris)['kesalahan']);
    }

    /** @return array<string, mixed> HasilImporLokasi */
    public function terapkan(Pengguna $penyunting, string $isiBerkas, string $dasar): array
    {
        $baris = $this->baca($isiBerkas);

        return DB::transaction(function () use ($penyunting, $baris, $dasar) {
            DB::statement("SET LOCAL statement_timeout = '60s'");
            $this->pohon->kunci();
            $periksa = $this->periksa($baris);
            if ($periksa['kesalahan'] !== []) {
                $hasil = $this->hasil('terapkan', false, $baris, $periksa['kesalahan']);
                throw new ApiError(422, 'impor_tidak_valid', 'Ada baris yang salah; tidak ada yang disimpan. Perbaiki lalu unggah ulang.', $hasil['kesalahan']);
            }
            $bangsal = $this->simpan($penyunting, $periksa['siap']);
            foreach ($bangsal as $id) {
                KapasitasTempatTidurBerubah::dispatch($id, SuntinganBerversi::hariIni());
            }
            $hasil = $this->hasil('terapkan', true, $baris, []);
            $this->audit->catat('master_data.lokasi_diimpor', $penyunting->id, 'inti.lokasi', null,
                ['jumlah_baris' => $hasil['jumlah_baris'], 'per_jenis' => $hasil['per_jenis'], 'dasar_perubahan' => $dasar]);

            return $hasil;
        });
    }

    /**
     * Baris data (nomor baris berkas => kolom). 422 berkas_tidak_valid bila bukan CSV UTF-8 berjudul kolom baku.
     *
     * @return array<int, array<string, string>>
     */
    private function baca(string $isi): array
    {
        $isi = preg_replace('/^\xEF\xBB\xBF/', '', $isi) ?? '';
        if (! mb_check_encoding($isi, 'UTF-8')) {
            throw $this->berkasTidakValid('Berkas harus CSV berenkode UTF-8.');
        }
        $aliran = fopen('php://memory', 'r+');
        fwrite($aliran, $isi);
        rewind($aliran);
        // Excel ber-locale Indonesia menyimpan CSV dengan pemisah titik koma.
        $barisPertama = strtok($isi, "\n");
        $pemisah = is_string($barisPertama) && str_contains($barisPertama, ';') && ! str_contains($barisPertama, ',') ? ';' : ',';
        $judul = fgetcsv($aliran, separator: $pemisah, escape: '');
        if ($judul === false || array_map(fn ($k) => trim((string) $k), $judul) !== self::KOLOM) {
            fclose($aliran);
            throw $this->berkasTidakValid('Judul kolom harus: '.implode(',', self::KOLOM).'.');
        }
        $baris = [];
        $nomor = 1;
        while (($kolom = fgetcsv($aliran, separator: $pemisah, escape: '')) !== false) {
            $nomor++;
            if ($kolom === [null]) {
                continue;
            }
            if (count($baris) >= self::MAKS_BARIS) {
                fclose($aliran);
                throw $this->berkasTidakValid('Paling banyak '.self::MAKS_BARIS.' baris data per berkas; pecah berkasnya.');
            }
            $baris[$nomor] = array_combine(self::KOLOM, array_map(fn ($n) => trim((string) $n), array_pad(array_slice($kolom, 0, count(self::KOLOM)), count(self::KOLOM), '')));
        }
        fclose($aliran);
        if ($baris === []) {
            throw $this->berkasTidakValid('Berkas tidak berisi baris data.');
        }

        return $baris;
    }

    /**
     * @param  array<int, array<string, string>>  $baris
     * @return array{kesalahan: list<array{baris: int, kolom: string|null, kode: string, pesan: string}>, siap: array<int, array<string, mixed>>}
     */
    private function periksa(array $baris): array
    {
        $kesalahan = [];
        $salah = function (int $n, ?string $kolom, string $kode, string $pesan) use (&$kesalahan) {
            $kesalahan[] = ['baris' => $n, 'kolom' => $kolom, 'kode' => $kode, 'pesan' => $pesan];
        };
        $unit = DB::table('inti.unit_organisasi')->where('status', 'aktif')->pluck('id', 'kode')->all();
        $bangsal = DB::table('inti.bangsal')->where('status', 'aktif')->pluck('id', 'kode')->all();
        $kelas = DB::table('inti.kelas_rawat')->where('status', 'aktif')->pluck('id', 'kode')->all();

        // Kolom per baris, dan kode_lengkap yang akan terbentuk.
        $siap = [];
        foreach ($baris as $n => $b) {
            $galat = count($kesalahan);
            foreach (['jenis', 'kode'] as $wajib) {
                if ($b[$wajib] === '') {
                    $salah($n, $wajib, 'kolom_wajib', "Kolom $wajib wajib diisi.");
                }
            }
            if ($b['jenis'] !== '' && ! in_array($b['jenis'], Lokasi::JENIS, true)) {
                $salah($n, 'jenis', 'jenis_tidak_dikenal', 'Jenis harus salah satu: '.implode(', ', Lokasi::JENIS).'.');
            }
            if ($b['kode'] !== '' && preg_match('/^[A-Z0-9]{1,10}$/', $b['kode']) !== 1) {
                $salah($n, 'kode', 'kode_tidak_valid', 'Kode 1–10 huruf besar/angka tanpa tanda hubung.');
            }
            $tt = $b['jenis'] === 'tempat_tidur';
            if (! $tt && $b['nama'] === '') {
                $salah($n, 'nama', 'kolom_wajib', 'Kolom nama wajib diisi.');
            }
            if ($b['jenis'] === 'ruang' && $b['fungsi'] === '') {
                $salah($n, 'fungsi', 'fungsi_wajib', 'Fungsi ruang wajib diisi.');
            } elseif ($b['jenis'] === 'ruang' && ! in_array($b['fungsi'], Lokasi::FUNGSI, true)) {
                $salah($n, 'fungsi', 'nilai_tidak_valid', 'Fungsi harus salah satu: '.implode(', ', Lokasi::FUNGSI).'.');
            } elseif ($b['jenis'] !== 'ruang' && $b['fungsi'] !== '') {
                $salah($n, 'fungsi', 'fungsi_hanya_ruang', 'Fungsi hanya untuk ruang.');
            }
            if ($b['unit'] !== '' && ! isset($unit[$b['unit']])) {
                $salah($n, 'unit', 'unit_tidak_valid', 'Unit tidak ada atau nonaktif.');
            }
            if ($b['bangsal'] !== '' && ($b['jenis'] !== 'ruang' || ! isset($bangsal[$b['bangsal']]))) {
                $salah($n, 'bangsal', 'bangsal_tidak_valid', $b['jenis'] !== 'ruang' ? 'Bangsal hanya diisi untuk ruang.' : 'Bangsal tidak ada atau nonaktif.');
            }
            if ($tt && ! isset($kelas[$b['kelas_rawat']])) {
                $salah($n, 'kelas_rawat', 'kelas_rawat_tidak_valid', 'Kelas rawat wajib, harus ada dan aktif.');
            }
            if ($b['kapasitas_maksimum'] !== '' && (! ctype_digit($b['kapasitas_maksimum']) || (int) $b['kapasitas_maksimum'] < 1
                || (int) $b['kapasitas_maksimum'] > 99 || $b['bangsal'] === '')) {
                $salah($n, 'kapasitas_maksimum', 'nilai_tidak_valid', 'Kapasitas 1–99, hanya untuk ruang yang diisi bangsal.');
            }
            if (! in_array($b['ekstra'], ['', 'ya', 'tidak'], true) || ($b['ekstra'] !== '' && ! $tt)) {
                $salah($n, 'ekstra', 'nilai_tidak_valid', 'Ekstra: ya atau tidak, hanya untuk tempat_tidur.');
            }
            $siap[$n] = [...$b, 'kode_lengkap' => PohonLokasi::kodeLengkap($b['induk'] === '' ? null : $b['induk'], $b['kode']),
                'unit_id' => $unit[$b['unit']] ?? null, 'bangsal_id' => $bangsal[$b['bangsal']] ?? null,
                'kelas_id' => $kelas[$b['kelas_rawat']] ?? null, 'sah' => count($kesalahan) === $galat];
        }

        // Ganda di berkas, sudah dipakai di basis data, dan induk.
        $perKode = [];
        foreach ($siap as $n => $s) {
            $perKode[$s['kode_lengkap']][] = $n;
        }
        $semuaKode = array_keys($perKode);
        $ada = DB::table('inti.lokasi')->whereIn('kode_lengkap', array_unique([...$semuaKode, ...array_filter(array_column($siap, 'induk'))]))
            ->get(['id', 'jenis', 'kode_lengkap', 'status', 'unit_organisasi_id'])->keyBy('kode_lengkap');
        $kodeTt = DB::table('inti.tempat_tidur')->whereIn('kode', $semuaKode)->pluck('kode')->flip();
        $kamarAda = DB::table('inti.kamar')->whereIn('lokasi_id', $ada->pluck('id'))->pluck('id', 'lokasi_id');

        foreach ($siap as $n => &$s) {
            if (count($perKode[$s['kode_lengkap']]) > 1) {
                $salah($n, 'kode', 'kode_ganda', 'Kode '.$s['kode_lengkap'].' muncul lebih dari sekali di berkas.');
                $s['sah'] = false;
            }
            $dipakai = $ada->get($s['kode_lengkap']);
            if ($dipakai !== null || ($s['jenis'] === 'tempat_tidur' && isset($kodeTt[$s['kode_lengkap']]))) {
                $salah($n, 'kode', 'kode_sudah_dipakai', 'Kode '.$s['kode_lengkap'].' sudah dipakai.');
                $s['sah'] = false;
            }
        }
        unset($s);

        // Induk: di basis data, atau baris lain berkas (yang juga harus sah). Diulang sampai stabil agar baris yang
        // induknya salah ikut ditolak.
        do {
            $berubah = false;
            foreach ($siap as $n => &$s) {
                if (! $s['sah'] || $s['jenis'] === '' || ! in_array($s['jenis'], Lokasi::JENIS, true)) {
                    continue;
                }
                $induk = $this->induk($s['induk'], $siap, $perKode, $ada, $kamarAda);
                $kode = match (true) {
                    $induk === false => ['induk_tidak_ditemukan', 'Induk '.$s['induk'].' tidak ada di basis data maupun di baris yang benar dalam berkas.'],
                    ! Lokasi::bolehAnak($induk['jenis'] ?? null, $s['jenis']) => ['jenis_induk_tidak_sesuai', 'Jenis '.$s['jenis'].' tidak bisa di bawah '.($induk['jenis'] ?? 'akar').'.'],
                    ($induk['status'] ?? 'aktif') === 'ditutup' => ['induk_tidak_aktif', 'Induk sudah ditutup.'],
                    $s['jenis'] === 'tempat_tidur' && ! ($induk['kamar'] ?? false) => ['bukan_kamar', 'Ruang induk TT harus menjadi kamar (isi kolom bangsal pada ruangnya).'],
                    default => null,
                };
                if ($kode !== null) {
                    $salah($n, 'induk', $kode[0], $kode[1]);
                    $s['sah'] = false;
                    $berubah = true;
                }
            }
            unset($s);
        } while ($berubah);

        usort($kesalahan, fn ($a, $b) => [$a['baris'], (string) $a['kolom']] <=> [$b['baris'], (string) $b['kolom']]);

        return ['kesalahan' => $kesalahan, 'siap' => $siap];
    }

    /**
     * Induk sebuah baris: null = akar, false = tidak ditemukan/salah, selain itu jenis/status/unit/kamar induk.
     *
     * @param  array<int, array<string, mixed>>  $siap
     * @param  array<string, list<int>>  $perKode
     * @param  Collection<array-key, \stdClass>  $ada
     * @param  Collection<array-key, mixed>  $kamarAda
     * @return array{jenis: string, status: string, unit_id: string|null, kamar: bool}|false|null
     */
    private function induk(string $kode, array $siap, array $perKode, $ada, $kamarAda): array|false|null
    {
        if ($kode === '') {
            return null;
        }
        if (($db = $ada->get($kode)) !== null) {
            return ['jenis' => $db->jenis, 'status' => $db->status, 'unit_id' => $db->unit_organisasi_id, 'kamar' => $kamarAda->has($db->id)];
        }
        $n = $perKode[$kode][0] ?? null;
        if ($n === null || ! $siap[$n]['sah']) {
            return false;
        }

        return ['jenis' => $siap[$n]['jenis'], 'status' => 'aktif', 'unit_id' => $siap[$n]['unit_id'], 'kamar' => $siap[$n]['bangsal_id'] !== null];
    }

    /**
     * Simpan semua baris (sudah diperiksa di bawah kunci pohon). Induk lebih dulu (urut kedalaman kode_lengkap).
     *
     * @param  array<int, array<string, mixed>>  $siap
     * @return list<string> bangsal yang TT-nya bertambah
     */
    private function simpan(Pengguna $penyunting, array $siap): array
    {
        uasort($siap, fn ($a, $b) => substr_count($a['kode_lengkap'], '-') <=> substr_count($b['kode_lengkap'], '-'));
        $hariIni = SuntinganBerversi::hariIni();
        $sekarang = now();
        $standar = ['dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id, 'dibuat_pada' => $sekarang, 'diubah_pada' => $sekarang, 'revisi' => 0];
        $induk = DB::table('inti.lokasi')->whereIn('kode_lengkap', array_filter(array_column($siap, 'induk')))->get(['id', 'kode_lengkap', 'unit_organisasi_id']);
        $idLokasi = $induk->pluck('id', 'kode_lengkap')->all();
        // Unit kosong = mengikuti induk; induk lebih dulu diproses, jadi unitnya sudah diketahui.
        $unitLokasi = $induk->pluck('unit_organisasi_id', 'kode_lengkap')->all();
        $idKamar = DB::table('inti.kamar')->whereIn('lokasi_id', array_values($idLokasi))->pluck('id', 'lokasi_id')->all();
        [$lokasi, $kamar, $tt, $bangsal] = [[], [], [], []];

        foreach ($siap as $s) {
            $id = (string) Str::uuid7();
            $idLokasi[$s['kode_lengkap']] = $id;
            $s['unit_id'] ??= $s['induk'] === '' ? null : ($unitLokasi[$s['induk']] ?? null);
            $unitLokasi[$s['kode_lengkap']] = $s['unit_id'];
            $tempatTidur = $s['jenis'] === 'tempat_tidur';
            $lokasi[] = [
                'id' => $id, 'induk_id' => $s['induk'] === '' ? null : $idLokasi[$s['induk']], 'jenis' => $s['jenis'], 'kode' => $s['kode'],
                'kode_lengkap' => $s['kode_lengkap'], 'nama' => $s['nama'] !== '' ? $s['nama'] : 'TT '.$s['kode'],
                'fungsi' => $s['jenis'] === 'ruang' ? $s['fungsi'] : null, 'unit_organisasi_id' => $s['unit_id'], 'status' => 'aktif',
                'status_berlaku_dari' => $hariIni, ...$standar,
            ];
            if ($s['jenis'] === 'ruang' && $s['bangsal_id'] !== null) {
                $idKamar[$id] = (string) Str::uuid7();
                $kamar[] = ['id' => $idKamar[$id], 'bangsal_id' => $s['bangsal_id'], 'lokasi_id' => $id,
                    'kapasitas_maksimum' => $s['kapasitas_maksimum'] === '' ? null : (int) $s['kapasitas_maksimum'], 'atribut_standar' => '{}', ...$standar];
            }
            if ($tempatTidur) {
                $kamarId = $idKamar[$idLokasi[$s['induk']]];
                $tt[] = ['id' => (string) Str::uuid7(), 'kamar_id' => $kamarId, 'lokasi_id' => $id, 'kode' => $s['kode_lengkap'],
                    'kelas_rawat_id' => $s['kelas_id'], 'aturan_jenis_kelamin' => 'campur', 'isolasi' => false, 'ekstra' => $s['ekstra'] === 'ya',
                    'berlaku_dari' => $hariIni, ...$standar];
                $bangsal[$kamarId] = true;
            }
        }
        foreach (array_chunk($lokasi, 500) as $potong) {
            DB::table('inti.lokasi')->insert($potong);
        }
        foreach (array_chunk($kamar, 500) as $potong) {
            DB::table('inti.kamar')->insert($potong);
        }
        foreach (array_chunk($tt, 500) as $potong) {
            DB::table('inti.tempat_tidur')->insert($potong);
        }

        return DB::table('inti.kamar')->whereIn('id', array_keys($bangsal))->distinct()->pluck('bangsal_id')->all();
    }

    /**
     * @param  array<int, array<string, string>>  $baris
     * @param  list<array{baris: int, kolom: string|null, kode: string, pesan: string}>  $kesalahan
     * @return array<string, mixed>
     */
    private function hasil(string $mode, bool $diterapkan, array $baris, array $kesalahan): array
    {
        $perJenis = array_fill_keys([...Lokasi::JENIS, 'kamar'], 0);
        foreach ($baris as $b) {
            if (isset($perJenis[$b['jenis']]) && $b['jenis'] !== 'kamar') {
                $perJenis[$b['jenis']]++;
            }
            if ($b['jenis'] === 'ruang' && $b['bangsal'] !== '') {
                $perJenis['kamar']++;
            }
        }

        return [
            'mode' => $mode,
            'diterapkan' => $diterapkan,
            'jumlah_baris' => count($baris),
            'jumlah_salah' => count(array_unique(array_column($kesalahan, 'baris'))),
            'per_jenis' => $perJenis,
            'kesalahan' => array_slice($kesalahan, 0, self::MAKS_KESALAHAN),
            'kesalahan_terpotong' => count($kesalahan) > self::MAKS_KESALAHAN,
        ];
    }

    private function berkasTidakValid(string $pesan): ApiError
    {
        return new ApiError(422, 'berkas_tidak_valid', $pesan, [['kolom' => 'berkas', 'pesan' => $pesan]]);
    }
}
