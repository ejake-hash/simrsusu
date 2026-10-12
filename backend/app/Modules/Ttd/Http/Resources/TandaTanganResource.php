<?php

namespace App\Modules\Ttd\Http\Resources;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\Ttd\Domain\KunciTtd;
use App\Modules\Ttd\Models\Kredensial;
use App\Modules\Ttd\Models\TandaTangan;
use Illuminate\Support\Facades\DB;

/** Bentuk TandaTangan (kontrak v0.8.0). Tanpa nilai tanda tangan, kunci, atau isi; nama dan NIP dari pegawai. */
final class TandaTanganResource
{
    /**
     * @param  iterable<int, array<string, mixed>>  $baris  {tanda_tangan: TandaTangan, status: string, baru?: bool}
     * @return list<array<string, mixed>>
     */
    public static function daftar(iterable $baris): array
    {
        $baris = collect($baris);
        $tt = $baris->pluck('tanda_tangan');
        $akun = Pengguna::query()->whereIn('id', $tt->pluck('pengguna_id')->filter()->unique()->values())->get(['id', 'pegawai_id', 'nama_pengguna'])->keyBy('id');
        $pegawai = DB::table('sdm.pegawai')->whereIn('id', $akun->pluck('pegawai_id')->filter()->unique()->values())->get(['id', 'nama', 'nip'])->keyBy('id');
        $kredensial = Kredensial::query()->whereIn('id', $tt->pluck('kredensial_id')->filter()->unique()->values())->get(['id', 'kunci_publik', 'status'])->keyBy('id');
        $sekarang = now()->toIso8601String();

        return $baris->map(function (array $b) use ($akun, $pegawai, $kredensial, $sekarang) {
            /** @var TandaTangan $t */
            $t = $b['tanda_tangan'];
            $a = $akun->get((string) $t->pengguna_id);
            $p = $a?->pegawai_id === null ? null : $pegawai->get($a->pegawai_id);
            $k = $kredensial->get((string) $t->kredensial_id);

            return [
                'id' => $t->id, 'objek_jenis' => $t->objek_jenis, 'objek_id' => $t->objek_id,
                'penanda_tangan' => ['id' => (string) $t->pengguna_id, 'nama' => $p->nama ?? $a->nama_pengguna ?? '-', 'nip' => $p->nip ?? null],
                'tingkat' => $t->tingkat, 'metode' => $t->metode, 'hash_isi' => $t->hash_isi,
                'sidik_kunci' => KunciTtd::sidik((string) $k?->kunci_publik), 'kredensial_dicabut' => $k?->status === 'dicabut',
                'ditandatangani_pada' => $t->ditandatangani_pada->toIso8601String(),
                'verifikasi' => ['status' => $b['status'], 'diperiksa_pada' => $sekarang],
                ...(array_key_exists('baru', $b) ? ['baru' => $b['baru']] : []),
            ];
        })->values()->all();
    }
}
