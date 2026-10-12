<?php

namespace App\Modules\Integrasi\Application;

use App\Modules\Integrasi\Domain\HasilKirim;
use App\Modules\Integrasi\Domain\Kiriman;
use App\Modules\Integrasi\Domain\PenanganKiriman;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\MasterData\Application\DataIntegrasi;

/**
 * Organization SATUSEHAT dari unit organisasi 1.8a (tiket 1.9 butir 4, FR-49-06; belum dicocokkan dengan dokumen resmi).
 * Unit akar menjadi bagian (`partOf`) Organization RS (organization_id kredensial); unit lain bagian dari induknya, yang
 * harus terdaftar lebih dulu (tertahan sampai itu). identifier.value = id lokal; type HL7 organization-type `dept`.
 */
final class PenanganOrganization implements PenanganKiriman
{
    public const JENIS = 'Organization';

    public const JENIS_LOKAL = 'unit_organisasi';

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
        $unit = $this->data->unit($kiriman->agregatId);
        if ($unit === null || $unit['induk_id'] === null || $kiriman->lingkungan === null || $this->tanpaPendaftaran($unit, $kiriman->lingkungan)
            || $this->peta->idLuar(SistemIntegrasi::Satusehat, $kiriman->lingkungan, self::JENIS_LOKAL, $unit['induk_id']) !== null) {
            return null;
        }
        $induk = $this->data->unit($unit['induk_id']);
        // Unit induk yang nonaktif sebelum pernah terdaftar tidak didaftarkan; anaknya tidak bisa menunggu induk itu.
        if (($induk['status'] ?? null) === 'nonaktif') {
            return ['kode' => 'induk_ditutup', 'pemilik_unit_id' => $unit['induk_id'],
                'pesan' => 'Unit induk '.$induk['kode'].' nonaktif dan tidak terdaftar di SATUSEHAT; pindahkan unit ini.'];
        }

        return ['kode' => 'induk_belum_terdaftar', 'pemilik_unit_id' => $unit['induk_id'],
            'pesan' => 'Unit induk '.($induk['kode'] ?? '').' belum terdaftar di SATUSEHAT.'];
    }

    public function kirim(Kiriman $kiriman, string $lingkungan, array $kredensial): HasilKirim
    {
        return $this->registrasi->denganSewa($kiriman, fn () => $this->daftarkan($kiriman, $lingkungan, $kredensial));
    }

    /** @param  array{id: string, status: string}  $unit */
    private function tanpaPendaftaran(array $unit, string $lingkungan): bool
    {
        return $unit['status'] === 'nonaktif' && $this->peta->idLuar(SistemIntegrasi::Satusehat, $lingkungan, self::JENIS_LOKAL, $unit['id']) === null;
    }

    /** @param  array<string, string>  $kredensial */
    private function daftarkan(Kiriman $kiriman, string $lingkungan, array $kredensial): HasilKirim
    {
        $unit = $this->data->unit($kiriman->agregatId);
        if ($unit === null) {
            return HasilKirim::ditolak('Unit organisasi tidak ditemukan.');
        }
        if ($this->tanpaPendaftaran($unit, $lingkungan)) {
            // Nonaktif sebelum pernah terdaftar (sinkron juga tidak mendaftarkannya): selesai tanpa memanggil SATUSEHAT.
            return HasilKirim::berhasil();
        }
        $org = $kredensial['organization_id'];
        $induk = $unit['induk_id'] === null ? $org : $this->peta->idLuar(SistemIntegrasi::Satusehat, $lingkungan, self::JENIS_LOKAL, $unit['induk_id']);
        if ($induk === null) {
            return HasilKirim::tunda('Menunggu unit induk terdaftar di SATUSEHAT.');
        }
        $resource = [
            'resourceType' => 'Organization', 'active' => $unit['status'] === 'aktif',
            'identifier' => [['use' => 'official', 'system' => str_replace('{organization_id}', $org, (string) config('integrasi.satusehat.identifier_organization')),
                'value' => $unit['id']]],
            'type' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/organization-type', 'code' => 'dept', 'display' => 'Hospital Department']]]],
            'name' => $unit['nama'], 'partOf' => ['reference' => "Organization/$induk"],
        ];
        $hasil = $this->registrasi->simpan($kiriman, $lingkungan, $kredensial, self::JENIS_LOKAL, $resource, $unit['sidik']);
        if ($hasil['id_luar'] !== null) {
            PenanganLocation::lepasTertahanInduk(self::JENIS, $lingkungan, $this->data->anakUnit($unit['id']));
        }

        return $hasil['hasil'];
    }
}
