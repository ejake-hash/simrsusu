<?php

namespace App\Modules\Notifikasi\Application;

use App\Modules\Akses\Application\JumlahTugasSaya;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Notifikasi\Models\Tugas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Tab kotak kerja (kontrak v0.7.0), saling lepas: saya = milik tingkat 0–1; dieskalasi = milik tingkat 2–3;
 * unit = bukan milik, di unit yang dikepalai (termasuk turunan) atau dieskalasikan ke akun ini; selesai = bulan ini.
 * "Milik" = pelaksananya akun ini, peran yang dipegangnya, atau unit penugasannya (penugasan berlaku).
 */
final class KueriKotakKerja implements JumlahTugasSaya
{
    public const TAB = ['saya', 'unit', 'dieskalasi', 'selesai'];

    /**
     * Memo per instans (satu permintaan HTTP): identitas pelaksana dan unit dikepalai dipakai di setiap tab.
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private array $identitas = [];

    /** @var array<string, list<string>> */
    private array $unit = [];

    public function __construct(private readonly PenerimaTugas $penerima) {}

    /** @return Builder<Tugas> */
    public function tab(Pengguna $pengguna, string $tab): Builder
    {
        $milik = fn (Builder $q) => $this->milik($q, $pengguna);

        return match ($tab) {
            'saya' => Tugas::query()->where('status', 'terbuka')->where('tingkat_saat_ini', '<=', 1)->where($milik),
            'dieskalasi' => Tugas::query()->where('status', 'terbuka')->where('tingkat_saat_ini', '>=', 2)->where($milik),
            'unit' => Tugas::query()->where('status', 'terbuka')->whereNot($milik)->where(fn ($q) => $this->unitAtauEskalasi($q, $pengguna)),
            default => Tugas::query()->where('status', 'selesai')
                ->where('selesai_pada', '>=', now(config('instalasi.zona_waktu'))->startOfMonth()->format('Y-m-d H:i:sP'))
                ->where(fn ($q) => $q->where($milik)->orWhere(fn ($q) => $this->unitAtauEskalasi($q, $pengguna))),
        };
    }

    /** @return array{saya: int, unit: int, dieskalasi: int, selesai: int} */
    public function jumlah(Pengguna $pengguna): array
    {
        $hasil = [];
        foreach (self::TAB as $tab) {
            $hasil[$tab] = $this->tab($pengguna, $tab)->count();
        }

        return $hasil;
    }

    /** Tugas yang tampil di salah satu tab boleh dibuka di /tugas/{id}. */
    public function terlihat(Pengguna $pengguna, Tugas $tugas): bool
    {
        return Tugas::query()->whereKey($tugas->id)
            ->where(fn ($q) => $q->where(fn ($q) => $this->milik($q, $pengguna))->orWhere(fn ($q) => $this->unitAtauEskalasi($q, $pengguna)))
            ->exists();
    }

    /** MenuSaya.jumlah_tugas Beranda = tab saya + dieskalasi. */
    public function untuk(Pengguna $pengguna): int
    {
        return Tugas::query()->where('status', 'terbuka')->where(fn ($q) => $this->milik($q, $pengguna))->count();
    }

    /** @param  Builder<Tugas>  $q */
    private function milik(Builder $q, Pengguna $pengguna): void
    {
        $q->where(function ($q) use ($pengguna) {
            foreach ($this->identitas[$pengguna->id] ??= $this->penerima->identitasPelaksana($pengguna) as [$jenis, $id]) {
                $q->orWhere(fn ($q) => $q->where('pelaksana_jenis', $jenis)->where('pelaksana_id', $id));
            }
        });
    }

    /** @param  Builder<Tugas>  $q */
    private function unitAtauEskalasi(Builder $q, Pengguna $pengguna): void
    {
        $unit = $this->unit[$pengguna->id] ??= $this->penerima->unitDikepalai($pengguna);
        $q->whereIn('unit_id', $unit)->orWhereIn('id', DB::table('inti.tugas_kejadian')->where('tingkat', '>=', 2)
            ->whereRaw('penerima @> ?::jsonb', [json_encode([$pengguna->id])])->select('tugas_id'));
    }
}
