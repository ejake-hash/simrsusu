<?php

namespace App\Modules\Integrasi\Application;

use App\Modules\Integrasi\Domain\HasilKirim;
use App\Modules\Integrasi\Domain\Kiriman;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\Integrasi\Infrastructure\JawabanHttp;
use App\Modules\Integrasi\Infrastructure\KlienSatusehat;

/**
 * Daftarkan atau perbarui satu resource data induk RS di SATUSEHAT (Organization, Location; tiket 1.9 butir 4). Id luar
 * sudah diketahui → PUT. Belum → POST; jawaban duplikat (20002, wajar menurut catatan lapangan §19) → cari lewat
 * identifier, simpan id-nya, lalu PUT agar isinya terkini. Aman dikirim ulang. Penangan SATUSEHAT membaca dan mengirim di
 * bawah sewa per agregat (denganSewa), sehingga dua kiriman data yang sama tidak berjalan bersamaan.
 */
final class RegistrasiFhir
{
    public function __construct(
        private readonly KlienSatusehat $klien,
        private readonly PetaIdEksternal $peta,
        private readonly SewaAgregat $sewa,
    ) {}

    /** @param  callable(): HasilKirim  $kerja */
    public function denganSewa(Kiriman $kiriman, callable $kerja): HasilKirim
    {
        $kunci = 'satusehat:'.$kiriman->jenisResource.':'.$kiriman->agregatId;
        $token = $this->sewa->ambil($kunci);
        if ($token === null) {
            return HasilKirim::tunda('Menunggu kiriman data yang sama selesai.');
        }
        try {
            return $kerja();
        } finally {
            $this->sewa->lepas($kunci, $token);
        }
    }

    /**
     * @param  array<string, string>  $kredensial
     * @param  array<string, mixed>  $resource  tanpa `id`; `identifier[0]` dipakai untuk pencarian duplikat
     * @param  string  $sidik  sidik data lokal yang menjadi isi $resource
     * @return array{hasil: HasilKirim, id_luar: string|null}
     */
    public function simpan(Kiriman $kiriman, string $lingkungan, array $kredensial, string $jenisLokal, array $resource, string $sidik): array
    {
        $jenis = (string) $resource['resourceType'];
        $id = $this->peta->idLuar(SistemIntegrasi::Satusehat, $lingkungan, $jenisLokal, $kiriman->agregatId);
        if ($id === null) {
            $buat = $this->klien->panggil($lingkungan, $kredensial, 'POST', KlienSatusehat::PATH_FHIR.$jenis, $resource, $kiriman->id);
            if (self::berhasil($buat)) {
                return $this->catat($kiriman, $lingkungan, $jenisLokal, $buat['json']['id'] ?? null, $sidik);
            }
            if (! KlienSatusehat::duplikat($buat['jawaban'], $buat['json'])) {
                return ['hasil' => self::gagal($buat), 'id_luar' => null];
            }
            $identifier = $resource['identifier'][0];
            $cari = $this->klien->panggil($lingkungan, $kredensial, 'GET',
                KlienSatusehat::PATH_FHIR.$jenis.'?identifier='.rawurlencode($identifier['system'].'|'.$identifier['value']), null, $kiriman->id);
            if (! self::berhasil($cari)) {
                return ['hasil' => self::gagal($cari), 'id_luar' => null];
            }
            $id = $cari['json']['entry'][0]['resource']['id'] ?? null;
            if (! is_string($id) || preg_match(PetaIdEksternal::POLA_ID_FHIR, $id) !== 1) {
                return ['hasil' => HasilKirim::ditolak("SATUSEHAT menyatakan $jenis duplikat, tetapi tidak ditemukan lewat identifier."), 'id_luar' => null];
            }
        }
        $ubah = $this->klien->panggil($lingkungan, $kredensial, 'PUT', KlienSatusehat::PATH_FHIR."$jenis/$id", ['id' => $id, ...$resource], $kiriman->id);
        if (! self::berhasil($ubah)) {
            return ['hasil' => self::gagal($ubah), 'id_luar' => null];
        }

        return $this->catat($kiriman, $lingkungan, $jenisLokal, $id, $sidik);
    }

    /**
     * Penolakan atau gangguan dari satu panggilan.
     *
     * @param  array{jawaban: JawabanHttp, json: array<string, mixed>|null, galat_token: string|null}  $panggilan
     */
    public static function gagal(array $panggilan): HasilKirim
    {
        $pesan = $panggilan['galat_token'] ?? KlienSatusehat::pesan($panggilan['jawaban'], $panggilan['json']);

        return $panggilan['jawaban']->gangguan() ? HasilKirim::gangguan($pesan) : HasilKirim::ditolak($pesan);
    }

    /** @param  array{jawaban: JawabanHttp, json: array<string, mixed>|null, galat_token: string|null}  $panggilan */
    public static function berhasil(array $panggilan): bool
    {
        $kode = $panggilan['jawaban']->kodeHttp;

        return $panggilan['galat_token'] === null && $kode !== null && $kode >= 200 && $kode < 300;
    }

    /** @return array{hasil: HasilKirim, id_luar: string|null} */
    private function catat(Kiriman $kiriman, string $lingkungan, string $jenisLokal, mixed $id, string $sidik): array
    {
        if (! is_string($id) || preg_match(PetaIdEksternal::POLA_ID_FHIR, $id) !== 1) {
            return ['hasil' => HasilKirim::ditolak('Jawaban SATUSEHAT tanpa id resource yang valid.'), 'id_luar' => null];
        }
        $this->peta->simpan(SistemIntegrasi::Satusehat, $lingkungan, $jenisLokal, $kiriman->agregatId, $id, $sidik);

        return ['hasil' => HasilKirim::berhasil($id), 'id_luar' => $id];
    }
}
