<?php

namespace App\Modules\RekamMedis\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Http\IfMatch;
use App\Core\Http\SuntinganBerversi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Antrean\Application\KelolaAntrean;
use App\Modules\RekamMedis\Events\ResepDibatalkan;
use App\Modules\RekamMedis\Events\ResepDisahkan;
use App\Modules\RekamMedis\Models\Resep;
use App\Modules\Ttd\Application\Penandatangan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * E-resep rawat jalan (tiket 2.17, kontrak v0.27.0; FR-07-06, FR-07-07, FR-02-10). Draf → sah (PIN T1 dokter jadwal kunjungan,
 * tanggapan per peringatan) → antrean farmasi lewat kejadian ResepDisahkan; sah tidak diubah, hanya dibatalkan. Isi resep
 * tidak pernah ke log/audit.
 */
final class KelolaResep
{
    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly Penandatangan $ttd,
        private readonly PemeriksaResep $pemeriksa,
    ) {}

    /**
     * @param  array{depo_id: string, racikan?: bool, catatan?: string|null, item: list<array<string, mixed>>}  $isian
     */
    public function buat(Pengguna $oleh, string $kunjunganId, array $isian): Resep
    {
        $this->pastikanDepo($isian['depo_id']);
        $this->pastikanBarang($isian['item']);

        return DB::transaction(function () use ($oleh, $kunjunganId, $isian) {
            $k = $this->kunjunganDilayani($kunjunganId);
            $tanggal = SuntinganBerversi::hariIni();
            $urut = DB::selectOne('INSERT INTO farmasi.nomor_resep (tanggal, terakhir) VALUES (?, 1)
                ON CONFLICT (tanggal) DO UPDATE SET terakhir = farmasi.nomor_resep.terakhir + 1 RETURNING terakhir', [$tanggal])->terakhir;
            $r = new Resep([
                'no_resep' => sprintf('RSP-%s-%04d', str_replace('-', '', $tanggal), $urut), 'kunjungan_id' => $k->id, 'pasien_id' => $k->pasien_id,
                'depo_id' => $isian['depo_id'], 'racikan' => $isian['racikan'] ?? false, 'catatan' => $isian['catatan'] ?? null,
                'penulis_id' => $oleh->id, 'dibuat_oleh' => $oleh->id, 'diubah_oleh' => $oleh->id,
            ]);
            $r->save();
            $this->simpanButir($oleh, $r->id, $isian['item']);
            $this->audit->catat('farmasi.resep_dibuat', $oleh->id, $r->getTable(), $r->id, ['kunjungan_id' => $k->id]);

            return $r->refresh();
        });
    }

    /**
     * @param  array{depo_id?: string, racikan?: bool, catatan?: string|null, item?: list<array<string, mixed>>}  $isian
     */
    public function ubah(Pengguna $oleh, string $resepId, string $versi, array $isian): Resep
    {
        if (isset($isian['depo_id'])) {
            $this->pastikanDepo($isian['depo_id']);
        }
        if (isset($isian['item'])) {
            $this->pastikanBarang($isian['item']);
        }

        return DB::transaction(function () use ($oleh, $resepId, $versi, $isian) {
            $r = $this->kunci($resepId, $versi);
            $this->pastikanDraf($r);
            $this->pastikanPenulisAtauDokter($oleh, $r);
            $this->kunjunganDilayani($r->kunjungan_id);
            $r->fill(array_intersect_key($isian, array_flip(['depo_id', 'racikan', 'catatan'])));
            $r->forceFill(['diubah_oleh' => $oleh->id])->save();
            if (isset($isian['item'])) {
                DB::table('farmasi.resep_item')->where('resep_id', $r->id)->whereNull('diganti_pada')
                    ->update(['diganti_pada' => now(), 'diubah_oleh' => $oleh->id, 'diubah_pada' => now()]);
                $this->simpanButir($oleh, $r->id, $isian['item']);
            }
            $this->audit->catat('farmasi.resep_diubah', $oleh->id, $r->getTable(), $r->id, ['kunjungan_id' => $r->kunjungan_id]);

            return $r->refresh();
        });
    }

    /**
     * @param  list<array{kunci: string, alasan: string}>  $tanggapan
     */
    public function sahkan(Request $request, Pengguna $oleh, string $resepId, string $versi, ?string $pin, array $tanggapan): Resep
    {
        $r = Resep::query()->findOrFail($resepId);
        $this->pastikanDokterJadwal($oleh, $r);
        $this->pastikanDraf($r);
        $this->kunjunganDilayani($r->kunjungan_id, false);
        $alasan = array_column($tanggapan, 'alasan', 'kunci');
        // Sebelum PIN dibuka: peringatan yang belum ditanggapi tidak menghabiskan percobaan PIN.
        $this->pastikanDitanggapi($this->pemeriksa->periksa($oleh, $r), $alasan);
        $kunci = [...$this->ttd->buka($request, $oleh, $pin), $pin === null];

        try {
            return DB::transaction(function () use ($oleh, $resepId, $versi, $alasan, $kunci) {
                $r = $this->kunci($resepId, $versi);
                $this->pastikanDraf($r);
                $this->kunjunganDilayani($r->kunjungan_id);
                if (PemeriksaResep::butir($r->id) === []) {
                    throw ValidationException::withMessages(['item' => 'Resep belum punya butir.']);
                }
                // Satu antrean farmasi per pasien per hari: baris pasien dikunci agar dua resep yang disahkan bersamaan tidak membuat dua antrean.
                DB::table('pasien.pasien')->where('id', $r->pasien_id)->lockForUpdate()->value('id');
                $peringatan = $this->pemeriksa->periksa($oleh, $r);
                $this->pastikanDitanggapi($peringatan, $alasan);
                $sekarang = now();
                $tanggal = SuntinganBerversi::hariIni();
                $r->forceFill(['status' => 'sah', 'masuk_pada' => $sekarang, 'dokter_id' => $oleh->pegawai_id, 'diubah_oleh' => $oleh->id])->save();
                DB::table('farmasi.peringatan_resep')->insert(array_map(fn (array $p) => [
                    'id' => (string) Str::uuid7(), 'resep_item_id' => $p['resep_item_id'], 'kunci' => $p['kunci'], 'jenis' => $p['jenis'],
                    'tingkat' => $p['tingkat'], 'pesan' => $p['pesan'], 'tanggapan' => $alasan[$p['kunci']], 'ditanggapi_oleh' => $oleh->id,
                    'ditanggapi_pada' => $sekarang,
                ], $peringatan));
                ResepDisahkan::dispatch($r->id, $r->kunjungan_id, $r->pasien_id, $tanggal, $oleh->id, $sekarang->toIso8601String());
                $antrean = KelolaAntrean::antreanFarmasi($r->pasien_id, $tanggal)
                    ?? throw new \LogicException('Antrean farmasi tidak terbentuk.');
                $tandaTangan = $this->ttd->tandatanganiTerbuka($oleh, [['jenis' => 'resep', 'id' => $r->id]], $kunci[0], $kunci[1], $kunci[2])[0]['tanda_tangan']->id;
                $r->forceFill(['antrean_id' => $antrean->id, 'tanda_tangan_id' => $tandaTangan])->save();
                $this->audit->catat('farmasi.resep_disahkan', $oleh->id, $r->getTable(), $r->id, ['kunjungan_id' => $r->kunjungan_id, 'antrean_id' => $antrean->id]);

                return $r->refresh();
            });
        } finally {
            sodium_memzero($kunci[1]);
        }
    }

    public function batal(Pengguna $oleh, string $resepId, string $alasan): Resep
    {
        return DB::transaction(function () use ($oleh, $resepId, $alasan) {
            $r = Resep::query()->whereKey($resepId)->lockForUpdate()->firstOrFail();
            if ($r->status === 'batal') {
                throw new ApiError(409, 'status_tidak_sesuai', 'Resep sudah dibatalkan.', [['status' => $r->status]]);
            }
            $r->status === 'sah' ? $this->pastikanDokterJadwal($oleh, $r) : $this->pastikanPenulisAtauDokter($oleh, $r);
            $r->forceFill(['status' => 'batal', 'alasan_batal' => $alasan, 'dibatalkan_oleh' => $oleh->id, 'dibatalkan_pada' => now(),
                'diubah_oleh' => $oleh->id, 'revisi' => $r->revisi + 1])->save();
            if ($r->antrean_id !== null) {
                ResepDibatalkan::dispatch($r->antrean_id, $oleh->id, $alasan);
            }
            $this->audit->catat('farmasi.resep_dibatalkan', $oleh->id, $r->getTable(), $r->id, ['kunjungan_id' => $r->kunjungan_id]);

            return $r->refresh();
        });
    }

    public const IZIN_LIHAT = 'rawat_jalan.pemeriksaan.resep.lihat';

    public const IZIN_FARMASI = 'farmasi.resep.lihat';

    /** Aturan baca resep (kontrak): resep.lihat di unit kunjungan, atau farmasi.resep.lihat di unit depo untuk resep yang pernah sah. */
    public static function terlihat(Pengguna $pengguna, Resep $r): bool
    {
        $gate = Gate::forUser($pengguna);
        $unit = DB::table('kunjungan.kunjungan AS k')->join('inti.poliklinik AS p', 'p.id', '=', 'k.poli_id')->where('k.id', $r->kunjungan_id)
            ->value('p.unit_organisasi_id');

        return ($unit !== null && $gate->allows(self::IZIN_LIHAT, new Cakupan('unit', (string) $unit)))
            || ($r->masuk_pada !== null && $gate->allows(self::IZIN_FARMASI, new Cakupan('unit', $r->depo_id)));
    }

    /** Akun ini dokter jadwal kunjungan resep (Kunjungan.dokter). */
    public static function dokterJadwal(Pengguna $pengguna, string $kunjunganId): bool
    {
        return ! empty($pengguna->pegawai_id)
            && DB::table('kunjungan.kunjungan')->where('id', $kunjunganId)->value('pegawai_id') === $pengguna->pegawai_id;
    }

    /**
     * @param  list<array{kunci: string, resep_item_id: string, jenis: string, tingkat: string|null, pesan: string}>  $peringatan
     * @param  array<string, string>  $alasan
     */
    private function pastikanDitanggapi(array $peringatan, array $alasan): void
    {
        $kurang = array_values(array_filter($peringatan, fn (array $p) => ! isset($alasan[$p['kunci']])));
        if ($kurang !== []) {
            throw new ApiError(409, 'peringatan_belum_ditanggapi', 'Tanggapi setiap peringatan sebelum mengesahkan resep.',
                array_map(fn (array $p) => ['kolom' => 'tanggapan', ...$p], $kurang));
        }
    }

    private function pastikanDepo(string $depoId): void
    {
        if (! DB::table('inti.unit_organisasi')->where('id', $depoId)->where('jenis', 'depo')->where('status', 'aktif')->exists()) {
            throw ValidationException::withMessages(['depo_id' => 'Depo tidak ditemukan atau tidak aktif.']);
        }
    }

    /**
     * Barang aktif berjenis obat/bmhp per butir; selain itu 422 per baris.
     *
     * @param  list<array<string, mixed>>  $item
     */
    private function pastikanBarang(array $item): void
    {
        $ada = DB::table('logistik.barang')->whereIn('id', array_column($item, 'barang_id'))->where('status', 'aktif')
            ->whereIn('jenis', ['obat', 'bmhp'])->pluck('id')->flip()->all();
        $salah = [];
        foreach ($item as $n => $i) {
            if (! isset($ada[$i['barang_id']])) {
                $salah["item.$n.barang_id"] = 'Barang tidak aktif atau bukan obat/BMHP.';
            }
        }
        if ($salah !== []) {
            throw ValidationException::withMessages($salah);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $item
     */
    private function simpanButir(Pengguna $oleh, string $resepId, array $item): void
    {
        DB::table('farmasi.resep_item')->insert(array_map(fn (array $i, int $n) => [
            'id' => (string) Str::uuid7(), 'resep_id' => $resepId, 'barang_id' => $i['barang_id'], 'urutan' => $n + 1,
            'dosis' => $i['dosis'] ?? null, 'frekuensi_per_hari' => $i['frekuensi_per_hari'], 'jumlah_per_pakai' => $i['jumlah_per_pakai'],
            'hari' => $i['hari'], 'jumlah' => $i['jumlah'] ?? self::jumlah($i['frekuensi_per_hari'], $i['jumlah_per_pakai'], $i['hari']),
            'rute' => $i['rute'] ?? null, 'aturan_pakai' => $i['aturan_pakai'] ?? null, 'catatan' => $i['catatan'] ?? null,
            'dibuat_oleh' => $oleh->id, 'diubah_oleh' => $oleh->id,
        ], $item, array_keys($item)));
    }

    /** FR-07-06: jumlah n hari = ceil(frekuensi × jumlah per pakai × hari), dibulatkan pada 3 desimal lebih dulu agar 0,1 × 3 tidak jadi 0,30000001. */
    public static function jumlah(int $frekuensi, float|int|string $perPakai, int $hari): float
    {
        return ceil(round($frekuensi * (float) $perPakai * $hari, 3));
    }

    /** @return object{id: string, pasien_id: string, status: string} */
    private function kunjunganDilayani(string $kunjunganId, bool $kunci = true): object
    {
        /** @var object{id: string, pasien_id: string, status: string} $k */
        $k = DB::table('kunjungan.kunjungan')->where('id', $kunjunganId)->when($kunci, fn ($q) => $q->lockForUpdate())->first(['id', 'pasien_id', 'status'])
            ?? throw new ApiError(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.');
        if ($k->status !== 'dilayani') {
            throw new ApiError(409, 'status_tidak_sesuai', 'Resep hanya bisa ditulis dan disahkan saat kunjungan sedang dilayani.', [['status' => $k->status]]);
        }

        return $k;
    }

    private function kunci(string $id, string $versi): Resep
    {
        $r = Resep::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        if (IfMatch::revisi($versi) !== $r->revisi) {
            throw new ApiError(412, 'versi_berubah', 'Resep ini diubah sejak Anda membukanya. Muat ulang untuk melihat perubahan terbaru.');
        }
        $r->revisi++;

        return $r;
    }

    private function pastikanDraf(Resep $r): void
    {
        if ($r->status === 'sah') {
            throw new ApiError(409, 'sudah_disahkan', 'Resep ini sudah disahkan. Batalkan lalu buat resep baru untuk mengubahnya.');
        }
        if ($r->status !== 'draf') {
            throw new ApiError(409, 'status_tidak_sesuai', 'Resep ini sudah dibatalkan.', [['status' => $r->status]]);
        }
    }

    private function pastikanPenulisAtauDokter(Pengguna $oleh, Resep $r): void
    {
        if ($r->penulis_id !== $oleh->id && ! self::dokterJadwal($oleh, $r->kunjungan_id)) {
            throw new ApiError(403, 'akses_ditolak', 'Hanya penulis resep atau dokter jadwal kunjungan yang dapat mengubah resep ini.');
        }
    }

    private function pastikanDokterJadwal(Pengguna $oleh, Resep $r): void
    {
        if (! self::dokterJadwal($oleh, $r->kunjungan_id)) {
            throw new ApiError(403, 'bukan_dokter_kunjungan', 'Resep hanya bisa disahkan atau dibatalkan dokter jadwal kunjungan ini.');
        }
    }
}
