<?php

namespace App\Modules\RekamMedis\Infrastructure;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\RekamMedis\Application\AksesCatatan;
use App\Modules\RekamMedis\Models\CatatanKlinis;
use App\Modules\Ttd\Application\PenyediaIsiTtd;
use App\Modules\Ttd\Domain\IsiKanonik;
use App\Modules\Ttd\Models\TandaTangan;

/**
 * Isi kanonik catatan klinis (tiket 2.14, kontrak v0.24.0): isi + label + penulis + kunjungan, hanya di-hash. Ditandatangani
 * penulisnya saja: asesmen awal berstatus selesai (lewat /ttd/tandatangani), CPPT berstatus sah yang belum ditandatangani
 * atas isinya (di dalam transaksi pengesahan/perbaikan KelolaCppt, sesudah status menjadi sah).
 */
final class IsiCatatanKlinis implements PenyediaIsiTtd
{
    public function __construct(private readonly AksesCatatan $akses) {}

    public function jenis(): string
    {
        return 'catatan_klinis';
    }

    public function isi(string $id): ?array
    {
        $c = CatatanKlinis::query()->find($id);

        return $c === null ? null : self::kanonik($c);
    }

    /** @return array<string, mixed> */
    public static function kanonik(CatatanKlinis $c): array
    {
        return ['id' => $c->id, 'jenis' => $c->jenis, 'kunjungan_id' => $c->kunjungan_id, 'penulis_id' => $c->penulis_id, 'label' => $c->label, 'isi' => $c->isi];
    }

    /** Waktu tanda tangan penulis atas isi saat ini; null bila belum atau isi berubah sesudahnya. */
    public static function ditandatanganiPada(CatatanKlinis $c): ?string
    {
        return TandaTangan::query()->where('objek_jenis', 'catatan_klinis')->where('objek_id', $c->id)->where('pengguna_id', $c->penulis_id)
            ->where('hash_isi', IsiKanonik::hash(self::kanonik($c)))->orderByDesc('ditandatangani_pada')->first()?->ditandatangani_pada->toIso8601String();
    }

    public function alasanTidakBoleh(Pengguna $penandaTangan, string $id): ?array
    {
        $c = CatatanKlinis::query()->find($id);
        if ($c === null || ! $this->akses->terlihat($penandaTangan, $c) || ! $this->akses->terbuka($penandaTangan, $c)) {
            return ['tidak_ditemukan', 'Catatan tidak ditemukan.'];
        }
        if ($c->penulis_id !== $penandaTangan->id) {
            return ['bukan_pemutus', 'Catatan klinis hanya bisa ditandatangani penulisnya.'];
        }
        $boleh = match ($c->jenis) {
            'asesmen' => $c->status === 'selesai',
            'soap' => $c->status === 'sah' && self::ditandatanganiPada($c) === null,
            default => false,
        };

        return $boleh ? null : ['belum_diputuskan', $c->jenis === 'soap' ? 'CPPT disahkan dari layar CPPT.' : 'Selesaikan asesmen sebelum ditandatangani.'];
    }

    public function terlihat(Pengguna $pengguna, string $id): bool
    {
        $c = CatatanKlinis::query()->find($id);

        return $c !== null && $this->akses->terlihat($pengguna, $c) && $this->akses->terbuka($pengguna, $c);
    }
}
