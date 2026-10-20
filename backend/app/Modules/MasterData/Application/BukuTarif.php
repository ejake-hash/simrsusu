<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Models\TarifVersi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pembaca buku tarif berversi (tiket 2.2): versi berlaku pada tanggal = `berlaku_dari` terbaru yang ≤ tanggal (versi
 * tertinggi bila sama). Rupiah selalu string desimal dua angka; penjumlahan dalam sen (bilangan bulat), tanpa float.
 */
final class BukuTarif
{
    /** "1500" → "1500.00", "1500.5" → "1500.50". */
    public static function rupiah(string $nilai): string
    {
        [$bulat, $sen] = array_pad(explode('.', $nilai, 2), 2, '0');

        return ltrim($bulat, '0') === '' ? '0.'.str_pad($sen, 2, '0') : ltrim($bulat, '0').'.'.str_pad($sen, 2, '0');
    }

    public static function sen(string $nilai): int
    {
        [$bulat, $sen] = array_pad(explode('.', self::rupiah($nilai), 2), 2, '0');

        return (int) $bulat * 100 + (int) $sen;
    }

    public static function dariSen(int $sen): string
    {
        return intdiv($sen, 100).'.'.str_pad((string) ($sen % 100), 2, '0', STR_PAD_LEFT);
    }

    /** SQL korelasi: id versi tarif `keuangan.tarif.id` yang berlaku pada tanggal (binding pertama). */
    public const SQL_VERSI_BERLAKU = '(select v.id from keuangan.tarif_versi v where v.tarif_id = keuangan.tarif.id and v.berlaku_dari <= ? order by v.berlaku_dari desc, v.versi desc limit 1)';

    public function versiTerbaru(string $tarifId): ?TarifVersi
    {
        return TarifVersi::query()->where('tarif_id', $tarifId)->orderByDesc('versi')->first();
    }

    /**
     * Harga per kelas dari banyak versi sekaligus.
     *
     * @param  list<string>  $versiId
     * @return array<string, list<array<string, string>>> id versi → HargaTarif[] (urut kode kelas)
     */
    public function harga(array $versiId): array
    {
        return DB::table('keuangan.tarif_versi_harga as h')->join('inti.kelas_rawat as k', 'k.id', '=', 'h.kelas_rawat_id')
            ->whereIn('h.tarif_versi_id', $versiId)->orderBy('k.kode')->orderBy('h.kelas_rawat_id')
            ->get(['h.tarif_versi_id', 'h.kelas_rawat_id', 'k.nama as kelas_rawat_label', 'h.jasa_sarana', 'h.jasa_pelayanan', 'h.bhp'])
            ->groupBy('tarif_versi_id')->map(fn (Collection $baris) => $baris->map(fn (object $b) => self::barisHarga(
                (string) $b->kelas_rawat_id, (string) $b->kelas_rawat_label, (string) $b->jasa_sarana, (string) $b->jasa_pelayanan, (string) $b->bhp,
            ))->values()->all())->all();
    }

    /** @return array{kelas_rawat_id: string, kelas_rawat_label: string, jasa_sarana: string, jasa_pelayanan: string, bhp: string, total: string} */
    public static function barisHarga(string $kelasId, string $label, string $sarana, string $pelayanan, string $bhp): array
    {
        return ['kelas_rawat_id' => $kelasId, 'kelas_rawat_label' => $label, 'jasa_sarana' => self::rupiah($sarana),
            'jasa_pelayanan' => self::rupiah($pelayanan), 'bhp' => self::rupiah($bhp),
            'total' => self::dariSen(self::sen($sarana) + self::sen($pelayanan) + self::sen($bhp))];
    }

    /**
     * HargaTarif dari isian usulan (IsianHargaTarif[]).
     *
     * @param  list<array<string, mixed>>  $isian
     * @return list<array<string, string>>
     */
    public function hargaDariIsian(array $isian): array
    {
        $kelas = DB::table('inti.kelas_rawat')->whereIn('id', array_column($isian, 'kelas_rawat_id'))->pluck('nama', 'id');
        $hasil = array_map(fn (array $h) => self::barisHarga((string) $h['kelas_rawat_id'], (string) ($kelas[$h['kelas_rawat_id']] ?? ''),
            (string) $h['jasa_sarana'], (string) $h['jasa_pelayanan'], (string) $h['bhp']), $isian);
        usort($hasil, fn (array $a, array $b) => [$a['kelas_rawat_label'], $a['kelas_rawat_id']] <=> [$b['kelas_rawat_label'], $b['kelas_rawat_id']]);

        return $hasil;
    }

    /**
     * Isi versi untuk perbandingan dan riwayat: nama, kelompok, status, harga.
     *
     * @return array{nama: string, kelompok: string, status: string, harga: list<array<string, string>>}
     */
    public function isi(TarifVersi $v): array
    {
        return ['nama' => $v->nama, 'kelompok' => $v->kelompok, 'status' => $v->status, 'harga' => ($this->harga([$v->id])[$v->id] ?? [])];
    }
}
