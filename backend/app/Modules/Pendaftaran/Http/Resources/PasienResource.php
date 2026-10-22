<?php

namespace App\Modules\Pendaftaran\Http\Resources;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Pendaftaran\Models\KontakDaruratPasien;
use App\Modules\Pendaftaran\Models\Pasien;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Bentuk kontrak v0.19.0 `Pasien` (tiket 2.5). NIK dan paspor utuh hanya untuk pemegang
 * pendaftaran.pasien.identitas.lihat; selain itu field tidak dikirim, hanya samarannya (PRD 9.2.1).
 */
final class PasienResource
{
    public const IZIN_IDENTITAS = 'pendaftaran.pasien.identitas.lihat';

    public static function bolehIdentitas(Pengguna $penonton): bool
    {
        return Gate::forUser($penonton)->allows(self::IZIN_IDENTITAS, Cakupan::rs());
    }

    /**
     * @param  Collection<int, Pasien>  $baris
     * @return list<array<string, mixed>>
     */
    public function banyak(Collection $baris, Pengguna $penonton): array
    {
        if ($baris->isEmpty()) {
            return [];
        }
        $baris = (new EloquentCollection($baris->all()))->load(['identitas', 'kontakDarurat']);
        $boleh = self::bolehIdentitas($penonton);
        $referensi = $this->referensi($baris);
        $wilayah = DB::table('inti.wilayah AS k')
            ->leftJoin('inti.wilayah AS kc', 'kc.id', '=', 'k.induk_id')
            ->leftJoin('inti.wilayah AS kb', 'kb.id', '=', 'kc.induk_id')
            ->leftJoin('inti.wilayah AS pv', 'pv.id', '=', 'kb.induk_id')
            ->whereIn('k.id', $baris->pluck('wilayah_id')->filter()->unique()->values())
            ->get(['k.id', 'k.kode_kemendagri', 'k.nama AS kelurahan', 'kc.nama AS kecamatan', 'kb.nama AS kabupaten_kota', 'pv.nama AS provinsi'])
            ->keyBy('id');
        $zona = (string) config('instalasi.zona_waktu');
        $kunjungan = DB::table('kunjungan.kunjungan')->whereIn('pasien_id', $baris->pluck('id'))->where('status', '<>', 'batal')
            ->groupBy('pasien_id')->selectRaw('pasien_id, count(*) AS jumlah')->pluck('jumlah', 'pasien_id');

        return $baris->map(function (Pasien $p) use ($boleh, $referensi, $wilayah, $zona, $kunjungan) {
            $nik = $p->nilaiIdentitas('nik');
            $paspor = $p->nilaiIdentitas('no_paspor');
            $alamat = $p->alamat_enkripsi;
            $w = $p->wilayah_id === null ? null : $wilayah->get($p->wilayah_id);
            $kodeLabel = fn (string $field) => $p->{$field} === null ? null
                : ['kode' => $p->{$field}, 'label' => $referensi[Pasien::REFERENSI[$field].'|'.$p->{$field}] ?? $p->{$field}];

            return [
                'id' => $p->id,
                'no_rm' => $p->no_rm,
                'nama' => $p->nama,
                ...($boleh ? ['nik' => $nik] : []),
                'nik_samaran' => $nik === null ? null : substr($nik, 0, 4).' •••• •••• '.substr($nik, -4),
                ...($boleh ? ['no_paspor' => $paspor] : []),
                'no_paspor_samaran' => $paspor === null ? null : self::samaranPaspor($paspor),
                'no_kartu_bpjs' => $p->nilaiIdentitas('no_kartu_bpjs'),
                'tanggal_lahir' => $p->tanggal_lahir?->toDateString(),
                'tempat_lahir' => $p->tempat_lahir,
                'jenis_kelamin' => $p->jenis_kelamin,
                'agama' => $kodeLabel('agama'),
                'pendidikan' => $kodeLabel('pendidikan'),
                'pekerjaan' => $kodeLabel('pekerjaan'),
                'status_kawin' => $kodeLabel('status_kawin'),
                'kewarganegaraan' => $p->kewarganegaraan,
                'bahasa' => $p->bahasa,
                'kebutuhan_khusus' => $p->kebutuhan_khusus,
                'alamat' => $alamat === null ? null : [
                    'jalan' => $alamat['jalan'], 'rt' => $alamat['rt'] ?? null, 'rw' => $alamat['rw'] ?? null, 'kode_pos' => $alamat['kode_pos'] ?? null,
                    'wilayah' => [
                        'kode' => (string) ($w->kode_kemendagri ?? ''), 'kelurahan' => (string) ($w->kelurahan ?? ''),
                        'kecamatan' => (string) ($w->kecamatan ?? ''), 'kabupaten_kota' => (string) ($w->kabupaten_kota ?? ''),
                        'provinsi' => (string) ($w->provinsi ?? ''),
                    ],
                ],
                'telepon' => $p->telepon_enkripsi,
                'kontak_darurat' => $p->kontakDarurat->map(fn (KontakDaruratPasien $k) => ['nama' => $k->nama, 'hubungan' => $k->hubungan, 'telepon' => $k->telepon_enkripsi])->values()->all(),
                'tanpa_identitas' => $p->tanpa_identitas,
                'ciri_pengenal' => $p->ciri_pengenal,
                'meninggal_pada' => $p->meninggal_pada?->toIso8601String(),
                'jumlah_kunjungan' => (int) ($kunjungan[$p->id] ?? 0),
                'sejak_tahun' => (int) $p->dibuat_pada->clone()->setTimezone($zona)->format('Y'),
                'versi' => (string) $p->revisi,
                'dibuat_pada' => $p->dibuat_pada->toIso8601String(),
                'diubah_pada' => $p->diubah_pada->toIso8601String(),
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    public function satu(Pasien $pasien, Pengguna $penonton): array
    {
        return $this->banyak(collect([$pasien]), $penonton)[0];
    }

    /** Paspor ≥ 8 karakter: 3 awal dan 2 akhir ("A12••••89"); lebih pendek: 1 awal dan 1 akhir. */
    private static function samaranPaspor(string $paspor): string
    {
        [$awal, $akhir] = strlen($paspor) >= 8 ? [3, 2] : [1, 1];

        return substr($paspor, 0, $awal).'••••'.substr($paspor, -$akhir);
    }

    /**
     * @param  Collection<int, Pasien>  $baris
     * @return array<string, string> "kelompok|kode" => label
     */
    private function referensi(Collection $baris): array
    {
        $pasangan = $baris->flatMap(fn (Pasien $p) => collect(Pasien::REFERENSI)
            ->filter(fn ($_, $field) => $p->{$field} !== null)
            ->map(fn ($kelompok, $field) => [$kelompok, $p->{$field}])->values())->unique(fn ($k) => implode('|', $k))->values();
        if ($pasangan->isEmpty()) {
            return [];
        }

        return DB::table('inti.referensi')
            ->where(fn ($q) => $pasangan->each(fn ($k) => $q->orWhere(fn ($q) => $q->where('kelompok', $k[0])->where('kode', $k[1]))))
            ->get(['kelompok', 'kode', 'label'])->mapWithKeys(fn ($r) => ["$r->kelompok|$r->kode" => $r->label])->all();
    }
}
