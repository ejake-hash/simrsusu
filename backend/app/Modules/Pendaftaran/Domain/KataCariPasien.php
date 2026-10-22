<?php

namespace App\Modules\Pendaftaran\Domain;

use App\Core\Http\ApiError;

/**
 * Tafsir kata cari satu kolom (FR-01-01, kontrak v0.19.0 GET /pasien): NIK, No. kartu BPJS, No. RM, atau nama + tanggal
 * lahir. Kata cari datang dari header X-Cari (persen-encode), tidak pernah diulang di pesan error.
 */
final class KataCariPasien
{
    private const PANJANG_MAKS = 200;

    private function __construct(
        public readonly string $jenis,
        public readonly string $nilai,
        public readonly ?string $tanggalLahir = null,
    ) {}

    public static function tafsir(?string $header): self
    {
        $mentah = rawurldecode((string) $header);
        if (! mb_check_encoding($mentah, 'UTF-8')) {
            throw self::tidakDikenali();
        }
        $teks = trim((string) preg_replace('/\s+/u', ' ', $mentah));
        if ($teks === '') {
            throw new ApiError(422, 'kata_cari_wajib', 'Ketik NIK, No. RM, No. kartu BPJS, atau nama dan tanggal lahir pasien.');
        }
        if (mb_strlen($teks) > self::PANJANG_MAKS) {
            throw self::tidakDikenali();
        }

        // Tanggal dulu: "12-03-1980" bukan No. RM walau hanya angka dan pemisah.
        $pola = '/(?<!\d)(?:(\d{2})[-\/](\d{2})[-\/](\d{4})|(\d{4})-(\d{2})-(\d{2}))(?!\d)/';
        if (preg_match($pola, $teks, $m) !== 1 || ! checkdate(...self::bulanHariTahun($m))) {
            $angka = (string) preg_replace('/[\s.\-]/', '', $teks);

            return match (true) {
                preg_match('/^[0-9]+$/', $angka) !== 1 => throw self::tidakDikenali(),
                strlen($angka) === 16 => new self('nik', $angka),
                strlen($angka) === 13 => new self('no_kartu', $angka),
                strlen($angka) <= 12 => new self('no_rm', self::noRm($angka)),
                default => throw self::tidakDikenali(),
            };
        }
        [$bulan, $hari, $tahun] = self::bulanHariTahun($m);
        $nama = trim((string) preg_replace('/\s+/u', ' ', str_replace($m[0], ' ', $teks)));
        if (preg_match_all('/\p{L}/u', $nama) < 3) {
            throw self::tidakDikenali();
        }

        return new self('nama_tanggal_lahir', $nama, sprintf('%04d-%02d-%02d', $tahun, $bulan, $hari));
    }

    /**
     * @param  array<int, string>  $m  hasil pola tanggal (DD-MM-YYYY, DD/MM/YYYY, atau YYYY-MM-DD)
     * @return array{0: int, 1: int, 2: int} untuk checkdate()
     */
    private static function bulanHariTahun(array $m): array
    {
        return ($m[4] ?? '') !== '' ? [(int) $m[5], (int) $m[6], (int) $m[4]] : [(int) $m[2], (int) $m[1], (int) $m[3]];
    }

    /** No. RM berpanjang tetap: nol di depan boleh dihilangkan atau ditambah saat mengetik. */
    public static function noRm(string $angka): string
    {
        $tanpaNol = ltrim($angka, '0');

        return str_pad($tanpaNol === '' ? '0' : $tanpaNol, (int) config('instalasi.panjang_no_rm'), '0', STR_PAD_LEFT);
    }

    private static function tidakDikenali(): ApiError
    {
        return new ApiError(422, 'kata_cari_tidak_dikenali',
            'Kata cari tidak dikenali. Gunakan NIK (16 digit), No. kartu BPJS (13 digit), No. RM, atau nama dan tanggal lahir (mis. 12-03-1980).');
    }
}
