<?php

namespace App\Modules\Lab\Application;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\RawatInap\Application\NamaPelaku;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Bentuk kontrak daftar kerja lab dan `Spesimen` (tiket 2.30). Data pasien = dua penanda identitas (nama, No. RM, tanggal
 * lahir); tanpa NIK, alamat, atau diagnosis medis; `diagnosis_kerja` = indikasi klinis order (2.16). Lokasi pasien saat ini: TT aktif (2.29) → area IGD kunjungan IGD aktif → poli
 * kunjungan order.
 */
final class BacaDaftarKerjaLab
{
    public const IZIN_LIHAT = 'penunjang.laboratorium.lihat';

    public const IZIN_AMBIL = 'penunjang.laboratorium.input';

    public const IZIN_TERIMA = 'penunjang.laboratorium.verifikasi';

    public function __construct(private readonly NamaPelaku $pelaku) {}

    /**
     * Order lab aktif (tidak batal, masih ada butir dipesan atau sampel_diambil) dengan prioritas itu. Mulai dari himpunan
     * butir aktif (indeks parsial permintaan_item_aktif), bukan dari seluruh riwayat order lab.
     */
    public static function query(string $prioritas): Builder
    {
        return DB::table('permintaan.permintaan_pemeriksaan AS p')
            ->whereIn('p.id', fn ($q) => $q->from('permintaan.permintaan_item')->select('permintaan_pemeriksaan_id')
                ->whereIn('status', ['dipesan', 'sampel_diambil']))
            ->where('p.jenis', 'lab')->where('p.status', 'dipesan')->where('p.prioritas', $prioritas)
            ->select(['p.id', 'p.dibuat_pada', 'p.kunjungan_id', 'p.pasien_id', 'p.prioritas', 'p.status', 'p.diagnosis_kerja', 'p.pemesan_id']);
    }

    /**
     * @param  Collection<int, mixed>  $baris
     * @return list<array<string, mixed>>
     */
    public function bentuk(Collection $baris, Pengguna $pengguna): array
    {
        if ($baris->isEmpty()) {
            return [];
        }
        $id = $baris->pluck('id')->all();
        $item = DB::table('permintaan.permintaan_item')->whereIn('permintaan_pemeriksaan_id', $id)->where('status', '<>', 'batal')
            ->orderBy('kode_item')->get(['id', 'permintaan_pemeriksaan_id', 'kode_item', 'nama', 'status'])->groupBy('permintaan_pemeriksaan_id');
        $spesimen = $this->spesimenItem($item->flatten(1)->pluck('id')->map(fn ($v) => (string) $v)->values()->all());
        $kunjungan = DB::table('kunjungan.kunjungan AS k')->leftJoin('inti.poliklinik AS pl', 'pl.id', '=', 'k.poli_id')
            ->whereIn('k.id', $baris->pluck('kunjungan_id')->unique()->values())
            ->get(['k.id', 'k.jenis_kunjungan', 'pl.nama', 'pl.nama_netral'])->keyBy('id');
        $pasienId = $baris->pluck('pasien_id')->unique()->values()->all();
        $pasien = DB::table('pasien.pasien')->whereIn('id', $pasienId)->get(['id', 'no_rm', 'nama', 'tanggal_lahir'])->keyBy('id');
        $rawatInap = DB::table('kunjungan.penempatan_tempat_tidur AS pn')
            ->join('inti.tempat_tidur AS tt', 'tt.id', '=', 'pn.tempat_tidur_id')
            ->join('inti.kamar AS km', 'km.id', '=', 'tt.kamar_id')
            ->join('inti.bangsal AS bg', 'bg.id', '=', 'km.bangsal_id')
            ->whereIn('pn.pasien_id', $pasienId)->whereNull('pn.selesai_pada')
            ->get(['pn.pasien_id', 'tt.kode', 'bg.nama'])->keyBy('pasien_id');
        $igd = DB::table('kunjungan.kunjungan AS k')->leftJoin('kunjungan.papan_igd AS pi', 'pi.kunjungan_id', '=', 'k.id')
            ->leftJoin('inti.area_igd AS a', 'a.id', '=', 'pi.area_igd_id')
            ->whereIn('k.pasien_id', $pasienId)->where('k.jenis_kunjungan', 'igd')->whereIn('k.status', ['terdaftar', 'dilayani'])
            ->get(['k.pasien_id', 'a.nama'])->keyBy('pasien_id');
        $pelaku = $this->pelaku->untuk($baris->pluck('pemesan_id')->map(fn ($v) => (string) $v));
        $gate = Gate::forUser($pengguna);
        $bolehAmbil = $gate->allows(self::IZIN_AMBIL, Cakupan::rs());
        $bolehTerima = $gate->allows(self::IZIN_TERIMA, Cakupan::rs());

        return $baris->map(function ($p) use ($item, $spesimen, $kunjungan, $pasien, $rawatInap, $igd, $pelaku, $bolehAmbil, $bolehTerima) {
            $k = $kunjungan[$p->kunjungan_id] ?? null;
            $ps = $pasien[$p->pasien_id];
            $namaPoli = $k === null ? null : ($k->nama_netral ?? $k->nama);
            $ri = $rawatInap[$p->pasien_id] ?? null;
            $ig = $igd[$p->pasien_id] ?? null;
            $lokasi = match (true) {
                $ri !== null => ['jenis' => 'rawat_inap', 'nama' => (string) $ri->nama, 'tempat_tidur' => (string) $ri->kode],
                $ig !== null => ['jenis' => 'igd', 'nama' => $ig->nama === null ? null : (string) $ig->nama, 'tempat_tidur' => null],
                $k !== null && $k->jenis_kunjungan === 'poliklinik' => ['jenis' => 'poli', 'nama' => $namaPoli, 'tempat_tidur' => null],
                default => null,
            };

            return [
                'id' => (string) $p->id, 'prioritas' => (string) $p->prioritas, 'status' => (string) $p->status,
                'dipesan_pada' => Carbon::parse((string) $p->dibuat_pada)->toIso8601String(),
                'diagnosis_kerja' => (string) $p->diagnosis_kerja,
                'pemesan' => $pelaku[(string) $p->pemesan_id] ?? null,
                'pasien' => ['id' => (string) $ps->id, 'no_rm' => (string) $ps->no_rm, 'nama' => (string) $ps->nama,
                    'tanggal_lahir' => $ps->tanggal_lahir === null ? null : substr((string) $ps->tanggal_lahir, 0, 10)],
                'asal' => ['kunjungan_id' => (string) $p->kunjungan_id, 'jenis_kunjungan' => $k === null ? null : (string) $k->jenis_kunjungan,
                    'nama' => $namaPoli],
                'lokasi_saat_ini' => $lokasi,
                'item' => ($item[$p->id] ?? collect())->map(fn ($i) => [
                    'id' => (string) $i->id, 'kode_item' => (string) $i->kode_item, 'nama' => (string) $i->nama, 'status' => (string) $i->status,
                    'spesimen' => $spesimen[(string) $i->id] ?? null,
                    'dapat' => ['ambil' => $i->status === 'dipesan' && $bolehAmbil, 'terima' => $i->status === 'sampel_diambil' && $bolehTerima],
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    public function spesimen(string $id): array
    {
        $itemId = (string) DB::table('lab.spesimen')->where('id', $id)->value('permintaan_item_id');

        return $this->spesimenItem([$itemId])[$itemId];
    }

    /**
     * @param  array<int, string>  $itemId
     * @return array<string, array<string, mixed>> id butir => Spesimen
     */
    private function spesimenItem(array $itemId): array
    {
        if ($itemId === []) {
            return [];
        }
        $baris = DB::table('lab.spesimen AS s')->join('permintaan.permintaan_item AS i', 'i.id', '=', 's.permintaan_item_id')
            ->whereIn('s.permintaan_item_id', $itemId)
            ->get(['s.*', 'i.permintaan_pemeriksaan_id', 'i.status AS item_status']);
        $pelaku = $this->pelaku->untuk($baris->pluck('diambil_oleh')->merge($baris->pluck('diterima_oleh'))->map(fn ($v) => $v === null ? null : (string) $v));
        $hasil = [];
        foreach ($baris as $s) {
            $hasil[(string) $s->permintaan_item_id] = [
                'id' => (string) $s->id, 'permintaan_item_id' => (string) $s->permintaan_item_id,
                'permintaan_pemeriksaan_id' => (string) $s->permintaan_pemeriksaan_id, 'item_status' => (string) $s->item_status,
                'barcode' => (string) $s->barcode, 'jenis' => (string) $s->jenis, 'lokasi_pengambilan' => (string) $s->lokasi_pengambilan,
                'diambil_pada' => Carbon::parse((string) $s->diambil_pada)->toIso8601String(),
                'diambil_oleh' => $pelaku[(string) $s->diambil_oleh] ?? null,
                'diterima_pada' => $s->diterima_pada === null ? null : Carbon::parse((string) $s->diterima_pada)->toIso8601String(),
                'diterima_oleh' => $s->diterima_oleh === null ? null : ($pelaku[(string) $s->diterima_oleh] ?? null),
                'kondisi' => $s->kondisi === null ? null : (string) $s->kondisi,
            ];
        }

        return $hasil;
    }
}
