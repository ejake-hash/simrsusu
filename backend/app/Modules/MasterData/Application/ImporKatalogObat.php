<?php

namespace App\Modules\MasterData\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\Barang;
use App\Modules\MasterData\Models\Formularium;
use Illuminate\Support\Facades\DB;

/**
 * Impor CSV katalog barang dan item formularium (tiket 2.11, kontrak v0.23.0; aturan berkas = POST /lokasi/impor).
 * Pratinjau tidak menyimpan; terapkan memeriksa ulang lalu menyimpan semua dalam satu transaksi atau tidak sama sekali.
 * Audit hanya jumlah baris dan dasar perubahan, tanpa isi baris.
 */
final class ImporKatalogObat
{
    public const KOLOM_BARANG = ['kode', 'nama', 'jenis', 'generik_paten', 'zat_aktif', 'kekuatan', 'sediaan', 'satuan_terkecil', 'kode_kfa', 'kewaspadaan_tinggi', 'narkotika', 'lasa'];

    public const KOLOM_ITEM = ['kode_barang', 'restriksi', 'maks_per_resep', 'kronis'];

    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly KelolaFormularium $formularium,
        private readonly PenjagaUsulan $penjaga,
    ) {}

    /** @return array<string, mixed> HasilImpor */
    public function barang(Pengguna $penyunting, string $mode, string $isiBerkas, string $dasar): array
    {
        $baris = BerkasCsv::baca($isiBerkas, self::KOLOM_BARANG);
        if ($mode === 'pratinjau') {
            return BerkasCsv::hasil('pratinjau', false, count($baris), $this->periksaBarang($baris)['kesalahan']);
        }

        return $this->terapkan($baris, fn () => $this->periksaBarang($baris), function (array $siap) use ($penyunting, $dasar) {
            foreach ($siap as $isi) {
                (new Barang($isi))->forceFill(['dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id])->save();
            }
            $this->audit->catat('master_data.barang_diimpor', $penyunting->id, 'logistik.barang', null, ['jumlah_baris' => count($siap), 'dasar_perubahan' => $dasar]);
        });
    }

    /** @return array<string, mixed> HasilImpor */
    public function item(Pengguna $penyunting, Formularium $f, string $mode, string $isiBerkas, string $dasar): array
    {
        $this->penjaga->pastikanBolehLangsung('formularium');
        $baris = BerkasCsv::baca($isiBerkas, self::KOLOM_ITEM);
        if ($mode === 'pratinjau') {
            return BerkasCsv::hasil('pratinjau', false, count($baris), $this->periksaItem($f, $baris, false)['kesalahan']);
        }

        return $this->terapkan($baris, fn () => $this->periksaItem($f, $baris, true), function (array $siap) use ($penyunting, $f, $dasar) {
            foreach ($siap as $isi) {
                $this->formularium->simpanItem($penyunting, $f, $isi['barang_id'], $isi);
            }
            $this->audit->catat('master_data.formularium_diimpor', $penyunting->id, 'farmasi.formularium', $f->id, ['jumlah_baris' => count($siap), 'dasar_perubahan' => $dasar]);
        });
    }

    /**
     * @param  array<int, array<string, string>>  $baris
     * @param  callable(): array{kesalahan: list<array{baris: int, kolom: string|null, kode: string, pesan: string}>, siap: list<array<string, mixed>>}  $periksa
     * @param  callable(list<array<string, mixed>>): void  $simpan
     * @return array<string, mixed>
     */
    private function terapkan(array $baris, callable $periksa, callable $simpan): array
    {
        return DB::transaction(function () use ($baris, $periksa, $simpan) {
            // 5.000 baris melebihi statement_timeout 5 detik role aplikasi (14.1.1); hanya transaksi ini yang dinaikkan.
            DB::statement("SET LOCAL statement_timeout = '60s'");
            $hasil = $periksa();
            if ($hasil['kesalahan'] !== []) {
                throw new ApiError(422, 'impor_tidak_valid', 'Ada baris yang salah; tidak ada yang disimpan. Perbaiki lalu unggah ulang.',
                    BerkasCsv::hasil('terapkan', false, count($baris), $hasil['kesalahan'])['kesalahan']);
            }
            $simpan($hasil['siap']);

            return BerkasCsv::hasil('terapkan', true, count($baris), []);
        });
    }

    /**
     * @param  array<int, array<string, string>>  $baris
     * @return array{kesalahan: list<array{baris: int, kolom: string|null, kode: string, pesan: string}>, siap: list<array<string, mixed>>}
     */
    private function periksaBarang(array $baris): array
    {
        $kesalahan = [];
        $siap = [];
        $kodeBerkas = [];
        $sudahAda = Barang::query()->whereIn('kode', array_column($baris, 'kode'))->pluck('kode')->flip()->all();
        foreach ($baris as $n => $b) {
            $galat = count($kesalahan);
            $salah = function (string $kolom, string $kode, string $pesan) use (&$kesalahan, $n) {
                $kesalahan[] = ['baris' => $n, 'kolom' => $kolom, 'kode' => $kode, 'pesan' => $pesan];
            };
            foreach (['kode', 'nama', 'jenis', 'satuan_terkecil'] as $wajib) {
                if ($b[$wajib] === '') {
                    $salah($wajib, 'kolom_wajib', "Kolom $wajib wajib diisi.");
                }
            }
            if ($b['kode'] !== '' && preg_match('/^[A-Z0-9][A-Z0-9._-]{0,29}$/', $b['kode']) !== 1) {
                $salah('kode', 'kode_tidak_valid', 'Kode: huruf besar, angka, titik, garis bawah, atau tanda hubung; paling panjang 30.');
            } elseif (isset($kodeBerkas[$b['kode']])) {
                $salah('kode', 'kode_ganda', "Kode sama dengan baris {$kodeBerkas[$b['kode']]}.");
            } elseif (isset($sudahAda[$b['kode']])) {
                $salah('kode', 'kode_sudah_dipakai', 'Kode sudah dipakai barang lain.');
            }
            $kodeBerkas[$b['kode']] ??= $n;
            if ($b['jenis'] !== '' && ! in_array($b['jenis'], Barang::JENIS, true)) {
                $salah('jenis', 'jenis_tidak_dikenal', 'Jenis harus salah satu: '.implode(', ', Barang::JENIS).'.');
            }
            if ($b['generik_paten'] !== '' && ! in_array($b['generik_paten'], ['generik', 'paten'], true)) {
                $salah('generik_paten', 'nilai_tidak_valid', 'Isi generik atau paten.');
            } elseif ($b['jenis'] === 'obat' && $b['generik_paten'] === '') {
                $salah('generik_paten', 'kolom_wajib', 'Generik atau paten wajib diisi untuk obat.');
            }
            $panjang = ['nama' => 200, 'zat_aktif' => 300, 'kekuatan' => 100, 'sediaan' => 100, 'satuan_terkecil' => 30, 'kode_kfa' => 20];
            foreach ($panjang as $kolom => $maks) {
                if (mb_strlen($b[$kolom]) > $maks) {
                    $salah($kolom, 'nilai_tidak_valid', "Paling panjang $maks karakter.");
                }
            }
            $bendera = [];
            foreach (['kewaspadaan_tinggi', 'narkotika', 'lasa'] as $kolom) {
                $bendera[$kolom] = BerkasCsv::yaTidak($b[$kolom]);
                if ($bendera[$kolom] === null) {
                    $salah($kolom, 'nilai_tidak_valid', 'Isi ya atau tidak.');
                }
            }
            if (count($kesalahan) === $galat) {
                $siap[] = [
                    'kode' => $b['kode'], 'nama' => $b['nama'], 'jenis' => $b['jenis'], 'generik_paten' => $b['generik_paten'] ?: null,
                    'zat_aktif' => $b['zat_aktif'] ?: null, 'kekuatan' => $b['kekuatan'] ?: null, 'sediaan' => $b['sediaan'] ?: null,
                    'satuan_terkecil' => $b['satuan_terkecil'], 'kode_kfa' => $b['kode_kfa'] ?: null, ...$bendera,
                ];
            }
        }

        return ['kesalahan' => $kesalahan, 'siap' => $siap];
    }

    /**
     * @param  array<int, array<string, string>>  $baris
     * @return array{kesalahan: list<array{baris: int, kolom: string|null, kode: string, pesan: string}>, siap: list<array<string, mixed>>}
     */
    private function periksaItem(Formularium $f, array $baris, bool $kunci): array
    {
        $kesalahan = [];
        $siap = [];
        $kodeBerkas = [];
        $barang = Barang::query()->whereIn('kode', array_column($baris, 'kode_barang'))->when($kunci, fn ($q) => $q->sharedLock())
            ->get(['id', 'kode', 'status'])->keyBy('kode');
        $sudahAda = DB::table('farmasi.formularium_item')->where('formularium_id', $f->id)->pluck('barang_id')->flip()->all();
        foreach ($baris as $n => $b) {
            $galat = count($kesalahan);
            $salah = function (string $kolom, string $kode, string $pesan) use (&$kesalahan, $n) {
                $kesalahan[] = ['baris' => $n, 'kolom' => $kolom, 'kode' => $kode, 'pesan' => $pesan];
            };
            $brg = $barang->get($b['kode_barang']);
            if ($b['kode_barang'] === '') {
                $salah('kode_barang', 'kolom_wajib', 'Kolom kode_barang wajib diisi.');
            } elseif (isset($kodeBerkas[$b['kode_barang']])) {
                $salah('kode_barang', 'kode_ganda', "Kode sama dengan baris {$kodeBerkas[$b['kode_barang']]}.");
            } elseif ($brg === null) {
                $salah('kode_barang', 'barang_tidak_ditemukan', 'Barang dengan kode ini belum ada di katalog.');
            } elseif ($brg->status !== 'aktif') {
                $salah('kode_barang', 'barang_tidak_aktif', 'Barang nonaktif.');
            } elseif (isset($sudahAda[$brg->id])) {
                $salah('kode_barang', 'sudah_di_formularium', 'Barang sudah ada di formularium ini.');
            }
            $kodeBerkas[$b['kode_barang']] ??= $n;
            $maks = $b['maks_per_resep'] === '' ? null : (ctype_digit($b['maks_per_resep']) && (int) $b['maks_per_resep'] >= 1 ? (int) $b['maks_per_resep'] : false);
            if ($maks === false) {
                $salah('maks_per_resep', 'nilai_tidak_valid', 'Isi bilangan bulat ≥ 1 atau kosongkan.');
            }
            $kronis = BerkasCsv::yaTidak($b['kronis']);
            if ($kronis === null) {
                $salah('kronis', 'nilai_tidak_valid', 'Isi ya atau tidak.');
            }
            if (mb_strlen($b['restriksi']) > 1000) {
                $salah('restriksi', 'nilai_tidak_valid', 'Paling panjang 1000 karakter.');
            }
            if (count($kesalahan) === $galat && $brg !== null) {
                $siap[] = ['barang_id' => $brg->id, 'restriksi' => $b['restriksi'] ?: null, 'maks_per_resep' => $maks, 'kronis' => $kronis];
            }
        }

        return ['kesalahan' => $kesalahan, 'siap' => $siap];
    }
}
