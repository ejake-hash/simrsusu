<?php

namespace App\Modules\Integrasi\Application;

use App\Core\ProfilRs\ProfilRs;
use App\Modules\Integrasi\Domain\HasilKirim;
use App\Modules\Integrasi\Domain\Kiriman;
use App\Modules\Integrasi\Domain\PenanganKiriman;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\Integrasi\Infrastructure\KlienAplicares;
use App\Modules\MasterData\Application\DataIntegrasi;
use App\Modules\MasterData\Application\SuntinganBerversi;

/**
 * Ketersediaan TT ke Aplicares (tiket 1.9 butir 5; catatan lapangan §24, belum dicocokkan dengan dokumen resmi BPJS).
 * Satu kiriman per bangsal; satu ruang Aplicares = bangsal × kelas rawat (koderuang = kode bangsal, kodekelas =
 * `kelas_rawat.kode_aplicares`). Isi dibaca dari keadaan terkini saat dikirim, bukan dari muatan kiriman, sehingga
 * kiriman lama yang di-retry tidak menimpa keadaan yang lebih baru (catatan tinjauan #76). Kelas tanpa kode Aplicares
 * menahan kiriman dengan penyebab (FR-49-04).
 */
final class PenanganAplicares implements PenanganKiriman
{
    public const JENIS = 'bed_update';

    public function __construct(
        private readonly DataIntegrasi $data,
        private readonly KlienAplicares $klien,
    ) {}

    public function tujuan(): SistemIntegrasi
    {
        return SistemIntegrasi::Aplicares;
    }

    public function jenisResource(): string
    {
        return self::JENIS;
    }

    public function periksa(Kiriman $kiriman): ?array
    {
        if (self::kodePpk() === null) {
            return ['kode' => 'kode_ppk_kosong', 'pesan' => 'Kode PPK BPJS di Profil RS belum diisi atau tidak valid.', 'pemilik_unit_id' => null];
        }
        $ruang = $this->data->ruangAplicares($kiriman->agregatId, SuntinganBerversi::hariIni());
        $tanpaKode = array_values(array_filter($ruang['kelas'] ?? [], fn (array $k) => trim((string) $k['kode_aplicares']) === ''));
        if ($ruang !== null && $tanpaKode !== []) {
            return ['kode' => 'kode_kelas_aplicares_kosong', 'pemilik_unit_id' => $ruang['unit_organisasi_id'],
                'pesan' => 'Kelas rawat '.implode(', ', array_column($tanpaKode, 'kode')).' di bangsal '.$ruang['kode'].' belum punya kode kelas Aplicares.'];
        }

        return null;
    }

    public function kirim(Kiriman $kiriman, string $lingkungan, array $kredensial): HasilKirim
    {
        $ruang = $this->data->ruangAplicares($kiriman->agregatId, SuntinganBerversi::hariIni());
        if ($ruang === null) {
            return HasilKirim::ditolak('Bangsal tidak ditemukan.');
        }
        $ppk = (string) self::kodePpk();
        foreach ($ruang['kelas'] as $kelas) {
            $isi = self::muatan($ruang, $kelas);
            $hasil = $this->klien->kirim($lingkungan, $kredensial, 'POST', "bed/update/$ppk", $isi, $kiriman->id);
            if (! $hasil['berhasil'] && ! $hasil['jawaban']->gangguan()) {
                // Ruang yang belum terdaftar didaftarkan dengan bed/create. Kode jawaban "belum terdaftar" belum tercatat
                // di catatan lapangan, jadi setiap penolakan update dicoba create sekali; ditolak lagi = gagal.
                $hasil = $this->klien->kirim($lingkungan, $kredensial, 'POST', "bed/create/$ppk", $isi, $kiriman->id);
            }
            if ($hasil['jawaban']->gangguan()) {
                return HasilKirim::gangguan((string) $hasil['pesan']);
            }
            if (! $hasil['berhasil']) {
                return HasilKirim::ditolak((string) $hasil['pesan']);
            }
        }

        return HasilKirim::berhasil();
    }

    /**
     * Isi bed/update dan bed/create. Hanya `kodekelas` dan `koderuang` yang tercatat di catatan lapangan (§24); nama isian
     * lain **perlu verifikasi** ke katalog Aplicares (DoD D.7). Keterisian TT baru ada di fase 2 (penempatan pasien),
     * jadi sampai itu `tersedia*` = kapasitas.
     *
     * @param  array{kode: string, nama: string}  $ruang
     * @param  array{kode_aplicares: string|null, kapasitas: int, laki_laki: int, perempuan: int, campur: int}  $kelas
     * @return array<string, int|string>
     */
    public static function muatan(array $ruang, array $kelas): array
    {
        return [
            'kodekelas' => trim((string) $kelas['kode_aplicares']), 'koderuang' => $ruang['kode'], 'namaruang' => $ruang['nama'],
            'kapasitas' => $kelas['kapasitas'], 'tersedia' => $kelas['kapasitas'],
            'tersediapria' => $kelas['laki_laki'], 'tersediawanita' => $kelas['perempuan'], 'tersediapriawanita' => $kelas['campur'],
        ];
    }

    /** Kode PPK masuk ke path URL, jadi hanya huruf dan angka. */
    private static function kodePpk(): ?string
    {
        $kode = trim((string) ProfilRs::satu()?->kode_ppk_bpjs);

        return preg_match('/^[A-Za-z0-9]{1,20}$/', $kode) === 1 ? $kode : null;
    }
}
