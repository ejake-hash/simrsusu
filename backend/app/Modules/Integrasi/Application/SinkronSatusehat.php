<?php

namespace App\Modules\Integrasi\Application;

use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\MasterData\Application\DataIntegrasi;
use App\Modules\Sdm\Application\PegawaiIntegrasi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sinkron data induk ke SATUSEHAT (tiket 1.9 butir 4, FR-49-05/06): unit organisasi → Organization, lokasi fisik →
 * Location, pegawai nakes → IHS Practitioner. Terjadwal tiap jam dan lewat `php artisan integrasi:sinkron-satusehat`:
 * hanya data yang belum terdaftar di lingkungan aktif atau yang sidiknya berbeda dari yang terakhir dikirim, induk lebih
 * dulu. Data yang sudah punya kiriman menunggu/tertahan tidak diberi kiriman baru (isi dibaca saat dikirim). Kiriman
 * gagal diulang sesudah 24 jam atau sesudah kredensial lingkungan itu diubah (mis. secret salah lalu dibetulkan).
 */
final class SinkronSatusehat
{
    /** Kiriman gagal tidak diulang sebelum selang ini, kecuali kredensial diubah sesudahnya. */
    private const ULANG_GAGAL_JAM = 24;

    public function __construct(
        private readonly KotakKeluar $kotakKeluar,
        private readonly KelolaKredensial $kredensial,
        private readonly PetaIdEksternal $peta,
        private readonly DataIntegrasi $data,
        private readonly PegawaiIntegrasi $pegawai,
    ) {}

    /** @return array<string, int> jenis_resource => jumlah diantrekan */
    public function semua(): array
    {
        $lingkungan = $this->kredensial->lingkunganAktif(SistemIntegrasi::Satusehat);
        if ($lingkungan === null) {
            return [];
        }
        $kredensialDiubah = Carbon::parse((string) DB::table('integrasi.kredensial_api')->where('tujuan', SistemIntegrasi::Satusehat->value)
            ->where('lingkungan', $lingkungan)->value('diubah_pada'));
        $batasGagal = $kredensialDiubah->max(now()->subHours(self::ULANG_GAGAL_JAM));

        return [
            PenanganOrganization::JENIS => $this->antrekan($lingkungan, $batasGagal, PenanganOrganization::JENIS, PenanganOrganization::JENIS_LOKAL,
                $this->data->daftarUnit(), fn (array $u) => $u['status'] === 'aktif'),
            // Lokasi (termasuk TT di luar masa berlakunya) yang ditutup sebelum pernah terdaftar tidak didaftarkan.
            PenanganLocation::JENIS => $this->antrekan($lingkungan, $batasGagal, PenanganLocation::JENIS, PenanganLocation::JENIS_LOKAL,
                $this->data->daftarLokasi(), fn (array $l) => $l['status'] !== 'ditutup'),
            PenanganPractitioner::JENIS => $this->antrekan($lingkungan, $batasGagal, PenanganPractitioner::JENIS, PenanganPractitioner::JENIS_LOKAL,
                $this->pegawai->nakes()),
        ];
    }

    /**
     * @param  list<array{id: string, sidik: string}>  $daftar  urut induk lebih dulu
     * @param  (callable(array<string, mixed>): bool)|null  $layakDidaftarkan  syarat data yang belum pernah terdaftar
     */
    private function antrekan(string $lingkungan, Carbon $batasGagal, string $jenis, string $jenisLokal, array $daftar, ?callable $layakDidaftarkan = null): int
    {
        $sidik = $this->peta->sidik(SistemIntegrasi::Satusehat, $lingkungan, $jenisLokal);
        $kiriman = fn () => DB::table('integrasi.kotak_keluar')->where('tujuan', SistemIntegrasi::Satusehat->value)->where('jenis_resource', $jenis)
            ->where('agregat_jenis', $jenisLokal)->where('lingkungan', $lingkungan);
        $tunggu = array_flip([
            ...$kiriman()->where(fn ($q) => $q->where('status', 'menunggu')
                ->orWhere(fn ($q) => $q->where('status', 'tertahan')->whereNotIn('penyebab_tertahan_kode', KotakKeluar::TAHAN_MANUAL)))
                ->distinct()->pluck('agregat_id')->all(),
            ...$kiriman()->where('status', 'gagal')->where('dibuat_pada', '>', $batasGagal)->distinct()->pluck('agregat_id')->all(),
        ]);
        $jumlah = 0;
        foreach ($daftar as $baris) {
            $id = $baris['id'];
            $perlu = isset($sidik[$id]) ? $sidik[$id] !== $baris['sidik'] : ($layakDidaftarkan === null || $layakDidaftarkan($baris));
            if ($perlu && ! isset($tunggu[$id])) {
                $this->kotakKeluar->antrekan(SistemIntegrasi::Satusehat, $jenis, $jenisLokal, $id);
                $jumlah++;
            }
        }

        return $jumlah;
    }
}
