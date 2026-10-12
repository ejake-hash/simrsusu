<?php

namespace App\Modules\Integrasi\Application;

use App\Core\ProfilRs\ProfilRs;
use App\Modules\Integrasi\Domain\HasilKirim;
use App\Modules\Integrasi\Domain\Kiriman;
use App\Modules\Integrasi\Domain\PenanganKiriman;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\Integrasi\Infrastructure\KlienAplicares;
use App\Modules\Integrasi\Infrastructure\PanggilanHttp;
use App\Modules\MasterData\Application\DataIntegrasi;
use App\Modules\MasterData\Application\SuntinganBerversi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ketersediaan TT ke Aplicares (tiket 1.9 butir 5; catatan lapangan §24, belum dicocokkan dengan dokumen resmi BPJS).
 * Satu kiriman per bangsal; satu ruang Aplicares = bangsal × kode kelas Aplicares (koderuang = kode bangsal; kelas rawat
 * yang dipetakan ke kode yang sama dijumlahkan). Isi dibaca dari keadaan terkini saat dikirim, di bawah kunci per bangsal,
 * lalu dibaca ulang sesudah terkirim dan dikirim lagi bila berubah: kiriman bangsal yang sama tidak saling menimpa
 * (catatan tinjauan #76). Ruang yang pernah dikirim tetapi tidak ada lagi dikirim dengan kapasitas 0.
 */
final class PenanganAplicares implements PenanganKiriman
{
    public const JENIS = 'bed_update';

    /** Putaran baca-kirim paling banyak per kiriman bila keadaan terus berubah selama dikirim. */
    private const PUTARAN_MAKS = 3;

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
        $lingkungan = $kiriman->lingkungan;
        if ($lingkungan !== null && PanggilanHttp::alamat(SistemIntegrasi::Aplicares, $lingkungan) === null) {
            return ['kode' => 'alamat_belum_dikonfigurasi', 'pemilik_unit_id' => null,
                'pesan' => "Alamat Aplicares lingkungan $lingkungan belum dikonfigurasi di server."];
        }
        // Isian bed/update belum dicocokkan dengan dokumen resmi dan keterisian TT belum ada (fase 2): angka tidak dikirim
        // ke Aplicares produksi (tampil ke publik) sebelum diverifikasi.
        if ($lingkungan === 'produksi' && ! config('integrasi.aplicares_isian_terverifikasi')) {
            return ['kode' => 'isian_belum_diverifikasi', 'pemilik_unit_id' => null,
                'pesan' => 'Isian Aplicares belum dicocokkan dengan dokumen resmi BPJS; kiriman produksi ditahan sampai diverifikasi.'];
        }
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
        $kunci = ['integrasi.aplicares', $kiriman->agregatId];
        if (! DB::selectOne('SELECT pg_try_advisory_lock(hashtext(?), hashtext(?)) AS dapat', $kunci)->dapat) {
            return HasilKirim::tunda();
        }
        try {
            for ($putaran = 1; ; $putaran++) {
                $ruang = $this->data->ruangAplicares($kiriman->agregatId, SuntinganBerversi::hariIni());
                if ($ruang === null) {
                    return HasilKirim::ditolak('Bangsal tidak ditemukan.');
                }
                $hasil = $this->kirimRuang($kiriman, $lingkungan, $kredensial, $ruang);
                if (! $hasil->berhasil || $putaran >= self::PUTARAN_MAKS
                    || $this->data->ruangAplicares($kiriman->agregatId, SuntinganBerversi::hariIni()) === $ruang) {
                    return $hasil;
                }
            }
        } finally {
            DB::select('SELECT pg_advisory_unlock(hashtext(?), hashtext(?))', $kunci);
        }
    }

    /**
     * Isi bed/update dan bed/create per ruang. Hanya `kodekelas` dan `koderuang` yang tercatat di catatan lapangan (§24);
     * nama isian lain **perlu verifikasi** ke katalog Aplicares (DoD D.7). Keterisian TT baru ada di fase 2 (penempatan
     * pasien), jadi sampai itu `tersedia*` = kapasitas; karena itu kiriman produksi ditahan (periksa()).
     *
     * @param  array{kode: string, nama: string, kelas: list<array{kode_aplicares: string|null, kapasitas: int, laki_laki: int, perempuan: int, campur: int}>}  $ruang
     * @return array<string, array<string, int|string>> kodekelas|koderuang => isi
     */
    public static function muatan(array $ruang): array
    {
        $hasil = [];
        foreach ($ruang['kelas'] as $kelas) {
            $kode = trim((string) $kelas['kode_aplicares']);
            $isi = $hasil[$kode.'|'.$ruang['kode']] ?? ['kodekelas' => $kode, 'koderuang' => $ruang['kode'], 'namaruang' => $ruang['nama'],
                'kapasitas' => 0, 'tersedia' => 0, 'tersediapria' => 0, 'tersediawanita' => 0, 'tersediapriawanita' => 0];
            $isi['kapasitas'] += $kelas['kapasitas'];
            $isi['tersedia'] += $kelas['kapasitas'];
            $isi['tersediapria'] += $kelas['laki_laki'];
            $isi['tersediawanita'] += $kelas['perempuan'];
            $isi['tersediapriawanita'] += $kelas['campur'];
            $hasil[$kode.'|'.$ruang['kode']] = $isi;
        }

        return $hasil;
    }

    /**
     * Kirim semua ruang bangsal (ditambah ruang lama yang hilang, dengan kapasitas 0). Ruang yang ditolak tidak menghentikan
     * ruang lain; gangguan menghentikan putaran (dicoba lagi seluruhnya, aman karena nilainya mutlak).
     *
     * @param  array<string, string>  $kredensial
     * @param  array{kode: string, nama: string, kelas: list<array{kode_aplicares: string|null, kapasitas: int, laki_laki: int, perempuan: int, campur: int}>}  $ruang
     */
    private function kirimRuang(Kiriman $kiriman, string $lingkungan, array $kredensial, array $ruang): HasilKirim
    {
        $ppk = (string) self::kodePpk();
        $muatan = self::muatan($ruang);
        $lama = DB::table('integrasi.ruang_aplicares')->where('lingkungan', $lingkungan)->where('bangsal_id', $kiriman->agregatId)
            ->where('kapasitas', '>', 0)->orderBy('kodekelas')->orderBy('koderuang')->get(['kodekelas', 'koderuang']);
        foreach ($lama as $l) {
            $muatan[$l->kodekelas.'|'.$l->koderuang] ??= ['kodekelas' => $l->kodekelas, 'koderuang' => $l->koderuang, 'namaruang' => $ruang['nama'],
                'kapasitas' => 0, 'tersedia' => 0, 'tersediapria' => 0, 'tersediawanita' => 0, 'tersediapriawanita' => 0];
        }
        $ditolak = [];
        foreach ($muatan as $kunci => $isi) {
            $hasil = $this->klien->kirim($lingkungan, $kredensial, 'POST', "bed/update/$ppk", $isi, $kiriman->id);
            if (! $hasil['berhasil'] && ! $hasil['jawaban']->gangguan()) {
                // Ruang yang belum terdaftar didaftarkan dengan bed/create. Kode jawaban "belum terdaftar" belum tercatat
                // di catatan lapangan, jadi setiap penolakan update dicoba create sekali; bila create juga ditolak, alasan
                // penolakan update tetap disimpan.
                $buat = $this->klien->kirim($lingkungan, $kredensial, 'POST', "bed/create/$ppk", $isi, $kiriman->id);
                $hasil = $buat['berhasil'] || $buat['jawaban']->gangguan() ? $buat : [...$buat, 'pesan' => $hasil['pesan'].'; bed/create: '.$buat['pesan']];
            }
            if ($hasil['jawaban']->gangguan()) {
                return HasilKirim::gangguan((string) $hasil['pesan']);
            }
            if (! $hasil['berhasil']) {
                $ditolak[] = "$kunci: ".$hasil['pesan'];

                continue;
            }
            DB::table('integrasi.ruang_aplicares')->upsert([
                'id' => (string) Str::uuid7(), 'lingkungan' => $lingkungan, 'kodekelas' => $isi['kodekelas'], 'koderuang' => $isi['koderuang'],
                'bangsal_id' => $kiriman->agregatId, 'kapasitas' => $isi['kapasitas'], 'dikirim_pada' => now(), 'diubah_pada' => now(),
            ], ['lingkungan', 'kodekelas', 'koderuang'], ['bangsal_id', 'kapasitas', 'dikirim_pada', 'diubah_pada']);
        }

        return $ditolak === [] ? HasilKirim::berhasil() : HasilKirim::ditolak(implode(' | ', $ditolak));
    }

    /** Kode PPK masuk ke path URL, jadi hanya huruf dan angka. */
    private static function kodePpk(): ?string
    {
        $kode = trim((string) ProfilRs::satu()?->kode_ppk_bpjs);

        return preg_match('/^[A-Za-z0-9]{1,20}$/', $kode) === 1 ? $kode : null;
    }
}
