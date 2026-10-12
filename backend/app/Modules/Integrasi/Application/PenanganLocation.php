<?php

namespace App\Modules\Integrasi\Application;

use App\Modules\Integrasi\Domain\HasilKirim;
use App\Modules\Integrasi\Domain\Kiriman;
use App\Modules\Integrasi\Domain\PenanganKiriman;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\MasterData\Application\DataIntegrasi;
use Illuminate\Support\Facades\DB;

/**
 * Location SATUSEHAT dari lokasi fisik 1.8b (tiket 1.9 butir 4, FR-49-06; catatan lapangan §19, belum dicocokkan dengan
 * dokumen resmi). Berjenjang lewat `partOf`: lokasi yang induknya belum terdaftar tertahan sampai induk terdaftar.
 * identifier.value = id lokal (tetap walau kode/induk berubah); physicalType dari kode HL7 location-physical-type; status
 * TT diturunkan dari masa berlakunya (DataIntegrasi).
 */
final class PenanganLocation implements PenanganKiriman
{
    public const JENIS = 'Location';

    public const JENIS_LOKAL = 'lokasi';

    /** jenis lokasi 1.8b → kode HL7 http://terminology.hl7.org/CodeSystem/location-physical-type. */
    private const TIPE_FISIK = [
        'situs' => ['si', 'Site'], 'gedung' => ['bu', 'Building'], 'sayap' => ['wi', 'Wing'], 'lantai' => ['lvl', 'Level'],
        'ruang' => ['ro', 'Room'], 'tempat_tidur' => ['bd', 'Bed'],
    ];

    /** status lokasi → Location.status FHIR. */
    private const STATUS = ['aktif' => 'active', 'renovasi' => 'suspended', 'ditutup' => 'inactive'];

    public function __construct(
        private readonly DataIntegrasi $data,
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
        $lokasi = $this->data->lokasi($kiriman->agregatId);
        if ($lokasi === null || $lokasi['induk_id'] === null || $kiriman->lingkungan === null || $this->tanpaPendaftaran($lokasi, $kiriman->lingkungan)
            || $this->peta->idLuar(SistemIntegrasi::Satusehat, $kiriman->lingkungan, self::JENIS_LOKAL, $lokasi['induk_id']) !== null) {
            return null;
        }
        $induk = $this->data->lokasi($lokasi['induk_id']);
        // Induk yang ditutup sebelum pernah terdaftar tidak didaftarkan; anaknya tidak bisa menunggu induk itu.
        if (($induk['status'] ?? null) === 'ditutup') {
            return ['kode' => 'induk_ditutup', 'pemilik_unit_id' => $lokasi['unit_organisasi_id'],
                'pesan' => 'Lokasi induk '.$induk['kode_lengkap'].' ditutup dan tidak terdaftar di SATUSEHAT; tutup atau pindahkan lokasi ini.'];
        }

        return ['kode' => 'induk_belum_terdaftar', 'pemilik_unit_id' => $lokasi['unit_organisasi_id'],
            'pesan' => 'Lokasi induk '.($induk['kode_lengkap'] ?? '').' belum terdaftar di SATUSEHAT.'];
    }

    public function kirim(Kiriman $kiriman, string $lingkungan, array $kredensial): HasilKirim
    {
        return $this->registrasi->denganSewa($kiriman, fn () => $this->daftarkan($kiriman, $lingkungan, $kredensial));
    }

    /**
     * Kiriman anak langsung yang tertahan menunggu induk ini diperiksa ulang pada putaran berikutnya, tidak menunggu 15
     * menit.
     *
     * @param  list<string>  $anak
     */
    public static function lepasTertahanInduk(string $jenisResource, string $lingkungan, array $anak): void
    {
        if ($anak !== []) {
            DB::table('integrasi.kotak_keluar')->where('tujuan', SistemIntegrasi::Satusehat->value)->where('jenis_resource', $jenisResource)
                ->where('lingkungan', $lingkungan)->whereIn('agregat_id', $anak)->where('status', 'tertahan')
                ->where('penyebab_tertahan_kode', 'induk_belum_terdaftar')->update(['coba_lagi_pada' => now()]);
        }
    }

    /** @param  array{id: string, status: string}  $lokasi */
    private function tanpaPendaftaran(array $lokasi, string $lingkungan): bool
    {
        return $lokasi['status'] === 'ditutup' && $this->peta->idLuar(SistemIntegrasi::Satusehat, $lingkungan, self::JENIS_LOKAL, $lokasi['id']) === null;
    }

    /** @param  array<string, string>  $kredensial */
    private function daftarkan(Kiriman $kiriman, string $lingkungan, array $kredensial): HasilKirim
    {
        $lokasi = $this->data->lokasi($kiriman->agregatId);
        if ($lokasi === null) {
            return HasilKirim::ditolak('Lokasi tidak ditemukan.');
        }
        if ($this->tanpaPendaftaran($lokasi, $lingkungan)) {
            // Ditutup sebelum pernah terdaftar (sinkron juga tidak mendaftarkannya): selesai tanpa memanggil SATUSEHAT.
            return HasilKirim::berhasil();
        }
        $org = $kredensial['organization_id'];
        [$kode, $nama] = self::TIPE_FISIK[$lokasi['jenis']];
        $resource = [
            'resourceType' => 'Location',
            'identifier' => [['system' => str_replace('{organization_id}', $org, (string) config('integrasi.satusehat.identifier_location')), 'value' => $lokasi['id']]],
            'status' => self::STATUS[$lokasi['status']], 'name' => $lokasi['nama'], 'description' => $lokasi['kode_lengkap'], 'mode' => 'instance',
            'physicalType' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/location-physical-type', 'code' => $kode, 'display' => $nama]]],
            'managingOrganization' => ['reference' => "Organization/$org"],
        ];
        if ($lokasi['induk_id'] !== null) {
            $induk = $this->peta->idLuar(SistemIntegrasi::Satusehat, $lingkungan, self::JENIS_LOKAL, $lokasi['induk_id']);
            if ($induk === null) {
                // Induk berganti sesudah periksa (pindah induk): periksa ulang pada percobaan berikutnya.
                return HasilKirim::tunda('Menunggu lokasi induk terdaftar di SATUSEHAT.');
            }
            $resource['partOf'] = ['reference' => "Location/$induk"];
        }
        ['hasil' => $hasil, 'id_luar' => $id] = $this->registrasi->simpan($kiriman, $lingkungan, $kredensial, self::JENIS_LOKAL, $resource, $lokasi['sidik']);
        if ($id !== null) {
            if ($lingkungan === 'produksi') {
                $this->data->simpanSatusehatLokasiId($lokasi['id'], $id);
            }
            self::lepasTertahanInduk(self::JENIS, $lingkungan, $this->data->anakLokasi($lokasi['id']));
        }

        return $hasil;
    }
}
