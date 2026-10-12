<?php

namespace App\Modules\Integrasi\Application;

use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\Integrasi\Infrastructure\KlienSatusehat;
use App\Modules\Integrasi\Infrastructure\Penyamar;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/**
 * Baca SATUSEHAT untuk modul lain (tiket 1.9 butir 4; catatan lapangan §16, §18; belum dicocokkan dengan dokumen resmi):
 * IHS pasien dari NIK (dipakai pendaftaran fase 2; hasilnya disimpan pemanggil di `pasien.ihs_id`, PRD 11.2) dan
 * pencarian KFA (FR-49-07). Baca sinkron tetap lewat circuit breaker: sirkuit terbuka → gagal cepat. NIK tidak pernah
 * masuk log (path dicatat tanpa query, respons disamarkan).
 */
final class LayananSatusehat
{
    /** Lama cache hasil KFA (detik); kamus KFA jarang berubah. */
    private const TTL_KFA = 86400;

    public function __construct(
        private readonly KelolaKredensial $kredensial,
        private readonly KlienSatusehat $klien,
        private readonly SirkuitIntegrasi $sirkuit,
    ) {}

    /**
     * `lingkungan` ikut dikembalikan: IHS sandbox tidak boleh disimpan sebagai `pasien.ihs_id` produksi.
     *
     * @return array{berhasil: bool, ihs: string|null, pesan: string|null, lingkungan: string|null}
     */
    public function ihsPasien(string $nik): array
    {
        if (preg_match('/^\d{16}$/', $nik) !== 1) {
            throw new InvalidArgumentException('NIK harus 16 digit.');
        }
        $hasil = $this->baca(KlienSatusehat::PATH_FHIR.'Patient?identifier='.rawurlencode(config('integrasi.satusehat.identifier_nik').'|'.$nik));
        $lingkungan = $this->kredensial->lingkunganAktif(SistemIntegrasi::Satusehat);
        if (! $hasil['berhasil']) {
            return ['berhasil' => false, 'ihs' => null, 'pesan' => $hasil['pesan'], 'lingkungan' => $lingkungan];
        }
        $ihs = $hasil['json']['entry'][0]['resource']['id'] ?? null;

        return is_string($ihs) && preg_match(PetaIdEksternal::POLA_ID_FHIR, $ihs) === 1
            ? ['berhasil' => true, 'ihs' => $ihs, 'pesan' => null, 'lingkungan' => $lingkungan]
            : ['berhasil' => false, 'ihs' => null, 'pesan' => 'NIK tidak terdaftar sebagai pasien di SATUSEHAT.', 'lingkungan' => $lingkungan];
    }

    /**
     * KFA `kfa-v2/products/all?keyword=&size=` (catatan §18). Bentuk respons belum tercatat di catatan lapangan (**perlu
     * verifikasi**): daftar diambil dari `items.data`, `items`, atau `data`; isi tiap produk dikembalikan apa adanya.
     *
     * @return array{berhasil: bool, data: list<array<string, mixed>>, pesan: string|null}
     */
    public function cariKfa(string $kataKunci, int $ukuran = 15): array
    {
        $kataKunci = trim($kataKunci);
        if (preg_match('/^[\pL\pN .,\-\/()%]{3,50}$/u', $kataKunci) !== 1 || $ukuran < 1 || $ukuran > 50) {
            throw new InvalidArgumentException('Kata kunci KFA 3–50 karakter; ukuran 1–50.');
        }
        $lingkungan = $this->kredensial->lingkunganAktif(SistemIntegrasi::Satusehat);
        $kunci = 'integrasi:satusehat:kfa:'.$lingkungan.':'.sha1(mb_strtolower($kataKunci).'|'.$ukuran);
        $simpan = Cache::get($kunci);
        if (is_array($simpan)) {
            return ['berhasil' => true, 'data' => $simpan, 'pesan' => null];
        }
        $hasil = $this->baca(KlienSatusehat::PATH_KFA.'products/all?keyword='.rawurlencode($kataKunci).'&size='.$ukuran);
        if (! $hasil['berhasil']) {
            return ['berhasil' => false, 'data' => [], 'pesan' => $hasil['pesan']];
        }
        $json = $hasil['json'] ?? [];
        $daftar = $json['items']['data'] ?? $json['items'] ?? $json['data'] ?? null;
        if (! is_array($daftar) || ! array_is_list($daftar)) {
            return ['berhasil' => false, 'data' => [], 'pesan' => 'Bentuk jawaban KFA tidak dikenali.'];
        }
        $daftar = array_values(array_filter($daftar, 'is_array'));
        Cache::put($kunci, $daftar, self::TTL_KFA);

        return ['berhasil' => true, 'data' => $daftar, 'pesan' => null];
    }

    /** @return array{berhasil: bool, json: array<string, mixed>|null, pesan: string|null} */
    private function baca(string $path): array
    {
        $sistem = SistemIntegrasi::Satusehat;
        $lingkungan = $this->kredensial->lingkunganAktif($sistem);
        if ($lingkungan === null) {
            return ['berhasil' => false, 'json' => null, 'pesan' => 'Integrasi SATUSEHAT belum diaktifkan.'];
        }
        // Kredensial dibuka sebelum mengambil jatah circuit breaker: kegagalan di sini tidak menahan jatah setengah terbuka.
        try {
            $kredensial = (array) $this->kredensial->nilai($sistem, $lingkungan);
        } catch (Throwable) {
            return ['berhasil' => false, 'json' => null, 'pesan' => 'Kredensial SATUSEHAT tidak bisa dibuka; periksa KREDENSIAL_KUNCI.'];
        }
        if (! $this->sirkuit->boleh($sistem)) {
            return ['berhasil' => false, 'json' => null, 'pesan' => 'SATUSEHAT sedang tidak tersambung; dicoba lagi otomatis.'];
        }
        $panggilan = $this->klien->panggil($lingkungan, $kredensial, 'GET', $path);
        if ($panggilan['jawaban']->gangguan()) {
            $pesan = (string) Penyamar::teks($panggilan['galat_token'] ?? KlienSatusehat::pesan($panggilan['jawaban'], $panggilan['json']));
            $this->sirkuit->gangguan($sistem, $pesan);

            return ['berhasil' => false, 'json' => null, 'pesan' => $pesan];
        }
        $this->sirkuit->berhasil($sistem);
        if (! RegistrasiFhir::berhasil($panggilan)) {
            return ['berhasil' => false, 'json' => null,
                'pesan' => Penyamar::teks($panggilan['galat_token'] ?? KlienSatusehat::pesan($panggilan['jawaban'], $panggilan['json']))];
        }

        return ['berhasil' => true, 'json' => $panggilan['json'], 'pesan' => null];
    }
}
