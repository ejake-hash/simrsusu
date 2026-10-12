<?php

namespace App\Modules\Integrasi\Http\Resources;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Bentuk Kiriman dan LogIntegrasi (kontrak v0.13.0). Muatan kiriman tidak pernah dikirim ke layar. */
final class IntegrasiPresenter
{
    /** Kolom kotak_keluar yang dibaca untuk layar (tanpa muatan). */
    public const KOLOM_KIRIMAN = ['id', 'tujuan', 'lingkungan', 'jenis_resource', 'agregat_jenis', 'agregat_id', 'status', 'percobaan',
        'coba_lagi_pada', 'terkirim_pada', 'error_terakhir', 'penyebab_tertahan_kode', 'penyebab_tertahan_pesan', 'pemilik_unit_id', 'id_luar', 'dibuat_pada'];

    /**
     * @param  Collection<int, \stdClass|null>  $baris
     * @return list<array<string, mixed>>
     */
    public static function kiriman(Collection $baris): array
    {
        $unit = DB::table('inti.unit_organisasi')->whereIn('id', $baris->pluck('pemilik_unit_id')->filter()->unique()->values())
            ->get(['id', 'kode', 'nama'])->keyBy('id');
        $aktif = DB::table('integrasi.kredensial_api')->where('aktif', true)->pluck('lingkungan', 'tujuan');
        $bolehPerbaiki = [];
        $hasil = [];
        foreach ($baris->filter() as $k) {
            $sistem = SistemIntegrasi::from($k->tujuan);
            $bolehPerbaiki[$k->tujuan] ??= Gate::allows($sistem->subMenuPemantauan().'.perbaiki', Cakupan::rs());
            $lingkunganAktif = $aktif[$k->tujuan] ?? null;
            $u = $k->pemilik_unit_id === null ? null : ($unit[$k->pemilik_unit_id] ?? null);

            $hasil[] = [
                'id' => $k->id, 'tujuan' => $k->tujuan, 'lingkungan' => $k->lingkungan, 'jenis_resource' => $k->jenis_resource,
                'objek_jenis' => $k->agregat_jenis, 'objek_id' => $k->agregat_id, 'status' => $k->status, 'percobaan' => (int) $k->percobaan,
                'coba_lagi_pada' => $k->status === 'terkirim' ? null : self::waktu($k->coba_lagi_pada),
                'terkirim_pada' => self::waktu($k->terkirim_pada), 'error_terakhir' => $k->error_terakhir,
                'penyebab_tertahan' => $k->status === 'tertahan' ? ['kode' => $k->penyebab_tertahan_kode, 'pesan' => (string) $k->penyebab_tertahan_pesan] : null,
                'pemilik_unit' => $u === null ? null : ['id' => $u->id, 'kode' => $u->kode, 'nama' => $u->nama],
                'id_luar' => $k->id_luar,
                'dapat_kirim_ulang' => $bolehPerbaiki[$k->tujuan] && in_array($k->status, ['gagal', 'tertahan'], true)
                    && $lingkunganAktif !== null && ($k->lingkungan === null || $k->lingkungan === $lingkunganAktif),
                'dibuat_pada' => self::waktu($k->dibuat_pada),
            ];
        }

        return $hasil;
    }

    /**
     * @param  Collection<int, \stdClass>  $baris
     * @return list<array<string, mixed>>
     */
    public static function log(Collection $baris): array
    {
        return $baris->map(fn (\stdClass $l) => [
            'id' => $l->id, 'tujuan' => $l->tujuan, 'lingkungan' => $l->lingkungan, 'metode' => $l->metode, 'endpoint' => $l->endpoint,
            'kode_http' => $l->kode_http === null ? null : (int) $l->kode_http, 'berhasil' => (bool) $l->berhasil,
            'latensi_ms' => $l->latensi_ms === null ? null : (int) $l->latensi_ms, 'pesan' => $l->pesan, 'kotak_keluar_id' => $l->kotak_keluar_id,
            'permintaan' => $l->permintaan, 'respons' => $l->respons, 'terpotong' => (bool) $l->terpotong, 'terjadi_pada' => self::waktu($l->terjadi_pada),
        ])->values()->all();
    }

    private static function waktu(mixed $nilai): ?string
    {
        return $nilai === null ? null : Carbon::parse($nilai)->toIso8601String();
    }
}
