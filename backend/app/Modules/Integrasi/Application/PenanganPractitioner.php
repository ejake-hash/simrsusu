<?php

namespace App\Modules\Integrasi\Application;

use App\Modules\Integrasi\Domain\HasilKirim;
use App\Modules\Integrasi\Domain\Kiriman;
use App\Modules\Integrasi\Domain\PenanganKiriman;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\Integrasi\Infrastructure\KlienSatusehat;
use App\Modules\Sdm\Application\PegawaiIntegrasi;

/**
 * IHS Practitioner tenaga kesehatan dari NIK (tiket 1.9 butir 4, FR-39-07, FR-49-06; catatan lapangan §16, belum
 * dicocokkan dengan dokumen resmi). Bukan pendaftaran: Practitioner dicari lewat identifier NIK, id-nya (IHS) disimpan.
 * Pegawai tanpa NIK tertahan (catatan §16: tanpa NIK data tidak bisa dikirim), pemiliknya unit pegawai (FR-49-04).
 */
final class PenanganPractitioner implements PenanganKiriman
{
    public const JENIS = 'Practitioner';

    public const JENIS_LOKAL = 'pegawai';

    public function __construct(
        private readonly PegawaiIntegrasi $pegawai,
        private readonly KlienSatusehat $klien,
        private readonly PetaIdEksternal $peta,
        private readonly RegistrasiFhir $registrasi,
    ) {}

    public function tujuan(): SistemIntegrasi
    {
        return SistemIntegrasi::Satusehat;
    }

    public function jenisResource(): string
    {
        return self::JENIS;
    }

    public function periksa(Kiriman $kiriman): ?array
    {
        $data = $this->pegawai->untukIhs($kiriman->agregatId);
        if ($data !== null && preg_match('/^\d{16}$/', (string) $data['nik']) !== 1) {
            return ['kode' => 'nik_kosong', 'pesan' => 'NIK pegawai belum diisi atau tidak 16 digit; IHS Practitioner tidak bisa dicari.',
                'pemilik_unit_id' => $data['unit_organisasi_id']];
        }

        return null;
    }

    public function kirim(Kiriman $kiriman, string $lingkungan, array $kredensial): HasilKirim
    {
        return $this->registrasi->denganSewa($kiriman, fn () => $this->cari($kiriman, $lingkungan, $kredensial));
    }

    /** @param  array<string, string>  $kredensial */
    private function cari(Kiriman $kiriman, string $lingkungan, array $kredensial): HasilKirim
    {
        $data = $this->pegawai->untukIhs($kiriman->agregatId);
        if ($data === null) {
            return HasilKirim::ditolak('Pegawai tidak ditemukan.');
        }
        if (preg_match('/^\d{16}$/', (string) $data['nik']) !== 1) {
            // NIK dikosongkan sesudah periksa: periksa ulang (tertahan) pada percobaan berikutnya.
            return HasilKirim::tunda('NIK pegawai berubah; diperiksa ulang.');
        }
        $cari = $this->klien->panggil($lingkungan, $kredensial, 'GET',
            KlienSatusehat::PATH_FHIR.'Practitioner?identifier='.rawurlencode(config('integrasi.satusehat.identifier_nik').'|'.$data['nik']), null, $kiriman->id);
        if (! RegistrasiFhir::berhasil($cari)) {
            return RegistrasiFhir::gagal($cari);
        }
        $ihs = $cari['json']['entry'][0]['resource']['id'] ?? null;
        if (! is_string($ihs) || preg_match(PetaIdEksternal::POLA_ID_FHIR, $ihs) !== 1) {
            return HasilKirim::ditolak('NIK pegawai tidak ditemukan sebagai Practitioner di SATUSEHAT.');
        }
        $this->peta->simpan(SistemIntegrasi::Satusehat, $lingkungan, self::JENIS_LOKAL, $kiriman->agregatId, $ihs, $data['sidik']);
        if ($lingkungan === 'produksi') {
            $this->pegawai->simpanIhs($kiriman->agregatId, $ihs);
        }

        return HasilKirim::berhasil($ihs);
    }
}
