<?php

namespace App\Modules\MasterData\Http\Resources;

use App\Modules\MasterData\Application\PohonLokasi;
use App\Modules\MasterData\Application\SuntinganBerversi;
use App\Modules\MasterData\Models\Bangsal;
use App\Modules\MasterData\Models\Kamar;
use App\Modules\MasterData\Models\KelasRawat;
use App\Modules\MasterData\Models\Lokasi;
use App\Modules\MasterData\Models\Poliklinik;
use App\Modules\MasterData\Models\TempatTidur;
use App\Modules\MasterData\Models\UnitOrganisasi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bentuk kontrak v0.5.0 (tiket 1.8b): Lokasi, Bangsal, Kamar, TempatTidur, KelasRawat, Poliklinik. Data terkait
 * dimuat sekaligus per halaman. Status operasional TT dan status node lokasi TT diturunkan dari tanggal (zona RS).
 */
final class LokasiResource
{
    public function __construct(private readonly PohonLokasi $pohon) {}

    /**
     * @param  Collection<int, Lokasi>  $baris
     * @return list<array<string, mixed>>
     */
    public function lokasi(Collection $baris): array
    {
        $id = $baris->pluck('id')->all();
        $anak = $id === [] ? [] : DB::table('inti.lokasi')->whereIn('induk_id', $id)->groupBy('induk_id')
            ->selectRaw('induk_id, count(*) AS jumlah')->pluck('jumlah', 'induk_id')->all();
        $jalur = $this->pohon->jalur($id);
        $unit = $this->unit($baris->pluck('unit_organisasi_id')->filter()->unique()->values()->all());
        $kamar = Kamar::query()->whereIn('lokasi_id', $id)->pluck('id', 'lokasi_id');
        $tt = TempatTidur::query()->whereIn('lokasi_id', $id)->get(['id', 'lokasi_id', 'berlaku_dari', 'berlaku_sampai'])->keyBy('lokasi_id');
        $hariIni = SuntinganBerversi::hariIni();

        return $baris->map(function (Lokasi $l) use ($anak, $jalur, $unit, $kamar, $tt, $hariIni) {
            [$status, $sejak] = [$l->status, $l->status_berlaku_dari->toDateString()];
            $t = $tt->get($l->id);
            if ($t !== null) {
                $tutup = $t->berlaku_sampai !== null && $t->berlaku_sampai->toDateString() < $hariIni;
                [$status, $sejak] = $tutup ? ['ditutup', $t->berlaku_sampai->copy()->addDay()->toDateString()] : ['aktif', $t->berlaku_dari->toDateString()];
            }

            return [
                'id' => $l->id,
                'induk_id' => $l->induk_id,
                'jenis' => $l->jenis,
                'kode' => $l->kode,
                'kode_lengkap' => $l->kode_lengkap,
                'nama' => $l->nama,
                'fungsi' => $l->fungsi,
                'unit' => $l->unit_organisasi_id === null ? null : ($unit[$l->unit_organisasi_id] ?? null),
                'status' => $status,
                'status_berlaku_dari' => $sejak,
                'satusehat_lokasi_id' => $l->satusehat_lokasi_id,
                'kamar_id' => $kamar[$l->id] ?? null,
                'tempat_tidur_id' => $t?->id,
                'jumlah_anak' => (int) ($anak[$l->id] ?? 0),
                'jalur' => $jalur[$l->id] ?? [],
                'versi' => (string) $l->revisi,
                'diubah_pada' => $l->diubah_pada->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, Bangsal>  $baris
     * @return list<array<string, mixed>>
     */
    public function bangsal(Collection $baris): array
    {
        $id = $baris->pluck('id')->all();
        $unit = $this->unit($baris->pluck('unit_organisasi_id')->unique()->values()->all());
        $jumlahKamar = $id === [] ? [] : Kamar::query()->whereIn('bangsal_id', $id)->groupBy('bangsal_id')
            ->selectRaw('bangsal_id, count(*) AS jumlah')->pluck('jumlah', 'bangsal_id')->all();
        $hariIni = SuntinganBerversi::hariIni();
        $ringkas = $id === [] ? collect() : DB::table('inti.tempat_tidur AS tt')->join('inti.kamar AS k', 'k.id', '=', 'tt.kamar_id')
            ->whereIn('k.bangsal_id', $id)->groupBy('k.bangsal_id', 'tt.kelas_rawat_id')
            ->selectRaw('k.bangsal_id, tt.kelas_rawat_id,
                count(*) FILTER (WHERE tt.berlaku_dari <= ?::date AND tt.berlaku_sampai IS NULL AND NOT tt.ekstra) AS tersedia_tetap,
                count(*) FILTER (WHERE tt.berlaku_dari <= ?::date AND tt.berlaku_sampai >= ?::date AND NOT tt.ekstra) AS tersedia_akan_tutup,
                count(*) FILTER (WHERE tt.berlaku_dari <= ?::date AND (tt.berlaku_sampai IS NULL OR tt.berlaku_sampai >= ?::date) AND tt.ekstra) AS ekstra,
                count(*) FILTER (WHERE tt.berlaku_sampai >= ?::date) AS akan_ditutup,
                count(*) FILTER (WHERE tt.berlaku_sampai < ?::date) AS ditutup', array_fill(0, 7, $hariIni))
            ->get()->groupBy('bangsal_id');
        $kelas = $this->kelasRingkas($ringkas->flatten(1)->pluck('kelas_rawat_id')->unique()->values()->all());

        return $baris->map(fn (Bangsal $b) => [
            'id' => $b->id,
            'kode' => $b->kode,
            'nama' => $b->nama,
            'unit' => $unit[$b->unit_organisasi_id] ?? null,
            'status' => $b->status,
            'jumlah_kamar' => (int) ($jumlahKamar[$b->id] ?? 0),
            'ringkasan_tt' => ($ringkas[$b->id] ?? collect())->sortBy(fn ($r) => $kelas[$r->kelas_rawat_id]['kode'] ?? '')
                ->map(fn ($r) => [
                    'kelas_rawat' => $kelas[$r->kelas_rawat_id],
                    'tersedia' => (int) $r->tersedia_tetap + (int) $r->tersedia_akan_tutup,
                    'ekstra' => (int) $r->ekstra,
                    'akan_ditutup' => (int) $r->akan_ditutup,
                    'ditutup' => (int) $r->ditutup,
                ])->values()->all(),
            'versi' => (string) $b->revisi,
            'diubah_pada' => $b->diubah_pada->toIso8601String(),
        ])->values()->all();
    }

    /**
     * @param  Collection<int, Kamar>  $baris
     * @return list<array<string, mixed>>
     */
    public function kamar(Collection $baris): array
    {
        $ruang = Lokasi::query()->whereIn('id', $baris->pluck('lokasi_id'))->get(['id', 'kode', 'kode_lengkap', 'nama'])->keyBy('id');
        $bangsal = $this->bangsalRingkas($baris->pluck('bangsal_id')->unique()->values()->all());
        $hariIni = SuntinganBerversi::hariIni();
        $aktif = $baris->isEmpty() ? [] : TempatTidur::query()->whereIn('kamar_id', $baris->pluck('id'))->where('berlaku_dari', '<=', $hariIni)
            ->where(fn ($q) => $q->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', $hariIni))
            ->groupBy('kamar_id')->selectRaw('kamar_id, count(*) AS jumlah')->pluck('jumlah', 'kamar_id')->all();

        return $baris->map(function (Kamar $k) use ($ruang, $bangsal, $aktif) {
            $r = $ruang->get($k->lokasi_id);
            $jumlah = (int) ($aktif[$k->id] ?? 0);

            return [
                'id' => $k->id,
                'nomor' => (string) $r?->kode,
                'bangsal' => $bangsal[$k->bangsal_id] ?? null,
                'lokasi' => $r === null ? null : ['id' => $r->id, 'kode_lengkap' => $r->kode_lengkap, 'nama' => $r->nama],
                'kapasitas_maksimum' => $k->kapasitas_maksimum,
                // Objek JSON walau kosong (array PHP kosong akan menjadi [] di JSON).
                'atribut_standar' => (object) $k->atribut_standar,
                'jumlah_tempat_tidur_aktif' => $jumlah,
                'melebihi_kapasitas' => $k->kapasitas_maksimum !== null && $jumlah > $k->kapasitas_maksimum,
                'versi' => (string) $k->revisi,
                'diubah_pada' => $k->diubah_pada->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, TempatTidur>  $baris
     * @return list<array<string, mixed>>
     */
    public function tempatTidur(Collection $baris): array
    {
        $kamar = Kamar::query()->whereIn('id', $baris->pluck('kamar_id')->unique())->get(['id', 'bangsal_id', 'lokasi_id'])->keyBy('id');
        $ruang = Lokasi::query()->whereIn('id', $kamar->pluck('lokasi_id'))->get(['id', 'kode', 'kode_lengkap', 'nama'])->keyBy('id');
        $bangsal = $this->bangsalRingkas($kamar->pluck('bangsal_id')->unique()->values()->all());
        $kelas = $this->kelasRingkas($baris->pluck('kelas_rawat_id')->unique()->values()->all());
        $hariIni = SuntinganBerversi::hariIni();

        return $baris->map(function (TempatTidur $t) use ($kamar, $ruang, $bangsal, $kelas, $hariIni) {
            $k = $kamar->get($t->kamar_id);
            $r = $k === null ? null : $ruang->get($k->lokasi_id);

            return [
                'id' => $t->id,
                'kode' => $t->kode,
                'kamar' => ['id' => $t->kamar_id, 'nomor' => (string) $r?->kode, 'kode_lengkap' => (string) $r?->kode_lengkap, 'nama' => (string) $r?->nama],
                'bangsal' => $k === null ? null : ($bangsal[$k->bangsal_id] ?? null),
                'lokasi_id' => $t->lokasi_id,
                'kelas_rawat' => $kelas[$t->kelas_rawat_id] ?? null,
                'aturan_jenis_kelamin' => $t->aturan_jenis_kelamin,
                'isolasi' => $t->isolasi,
                'ekstra' => $t->ekstra,
                'status_operasional' => self::statusOperasional($t->berlaku_dari, $t->berlaku_sampai, $hariIni),
                'berlaku_dari' => $t->berlaku_dari->toDateString(),
                'berlaku_sampai' => $t->berlaku_sampai?->toDateString(),
                'alasan_tutup' => $t->alasan_tutup,
                'satusehat_lokasi_id' => $t->satusehat_lokasi_id,
                'versi' => (string) $t->revisi,
                'diubah_pada' => $t->diubah_pada->toIso8601String(),
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    public static function kelas(KelasRawat $k): array
    {
        return [
            'id' => $k->id, 'kode' => $k->kode, 'nama' => $k->nama, 'kode_aplicares' => $k->kode_aplicares,
            'berlaku_dari' => $k->berlaku_dari->toDateString(), 'status' => $k->status,
            'versi' => (string) $k->revisi, 'diubah_pada' => $k->diubah_pada->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, Poliklinik>  $baris
     * @return list<array<string, mixed>>
     */
    public function poliklinik(Collection $baris): array
    {
        $unit = $this->unit($baris->pluck('unit_organisasi_id')->unique()->values()->all());
        $ruang = Lokasi::query()->whereIn('id', $baris->pluck('lokasi_id')->filter())->get(['id', 'kode_lengkap', 'nama'])->keyBy('id');

        return $baris->map(fn (Poliklinik $p) => [
            'id' => $p->id,
            'kode' => $p->kode,
            'nama' => $p->nama,
            'unit' => $unit[$p->unit_organisasi_id] ?? null,
            'lokasi' => ($r = $p->lokasi_id === null ? null : $ruang->get($p->lokasi_id)) === null ? null
                : ['id' => $r->id, 'kode_lengkap' => $r->kode_lengkap, 'nama' => $r->nama],
            'kode_poli_bpjs' => $p->kode_poli_bpjs,
            'status' => $p->status,
            'versi' => (string) $p->revisi,
            'diubah_pada' => $p->diubah_pada->toIso8601String(),
        ])->values()->all();
    }

    /**
     * @param  list<string>  $id
     * @return array<string, array{id: string, kode: string, nama: string}>
     */
    public function bangsalRingkas(array $id): array
    {
        return Bangsal::query()->whereIn('id', $id)->get(['id', 'kode', 'nama'])
            ->mapWithKeys(fn (Bangsal $b) => [$b->id => ['id' => $b->id, 'kode' => $b->kode, 'nama' => $b->nama]])->all();
    }

    /**
     * @param  list<string>  $id
     * @return array<string, array{id: string, kode: string, nama: string}>
     */
    public function kelasRingkas(array $id): array
    {
        return KelasRawat::query()->whereIn('id', $id)->get(['id', 'kode', 'nama'])
            ->mapWithKeys(fn (KelasRawat $k) => [$k->id => ['id' => $k->id, 'kode' => $k->kode, 'nama' => $k->nama]])->all();
    }

    public static function statusOperasional(Carbon $dari, ?Carbon $sampai, string $hariIni): string
    {
        return match (true) {
            $sampai !== null && $sampai->toDateString() < $hariIni => 'ditutup',
            $sampai !== null => 'akan_ditutup',
            $dari->toDateString() > $hariIni => 'belum_berlaku',
            default => 'aktif',
        };
    }

    /**
     * @param  list<string>  $id
     * @return array<string, array{id: string, kode: string, nama: string}>
     */
    private function unit(array $id): array
    {
        return UnitOrganisasi::query()->whereIn('id', $id)->get(['id', 'kode', 'nama'])
            ->mapWithKeys(fn (UnitOrganisasi $u) => [$u->id => ['id' => $u->id, 'kode' => $u->kode, 'nama' => $u->nama]])->all();
    }
}
