<?php

namespace App\Modules\RekamMedis\Application;

use App\Core\Http\SuntinganBerversi;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\RekamMedis\Models\Resep;
use Illuminate\Support\Facades\DB;

/**
 * Pemeriksaan resep otomatis (tiket 2.17, FR-07-07, kontrak PeringatanResep): alergi, duplikasi zat aktif, dan di luar
 * formularium berlaku untuk penjamin kunjungan. Interaksi dan dosis ginjal menunggu data induk RS. `kunci` tetap selama
 * sumbernya sama, jadi tanggapan dokter bisa dicocokkan saat sahkan.
 */
final class PemeriksaResep
{
    /**
     * Butir aktif resep beserta barangnya, urut `urutan`.
     *
     * @return list<object{id: string, barang_id: string, urutan: int, nama: string, zat_aktif: string|null}>
     */
    public static function butir(string $resepId): array
    {
        /** @var list<object{id: string, barang_id: string, urutan: int, nama: string, zat_aktif: string|null}> */
        return DB::table('farmasi.resep_item AS i')->join('logistik.barang AS b', 'b.id', '=', 'i.barang_id')
            ->where('i.resep_id', $resepId)->whereNull('i.diganti_pada')->orderBy('i.urutan')
            ->get(['i.id', 'i.barang_id', 'i.urutan', 'b.nama', 'b.zat_aktif'])->all();
    }

    /**
     * @return list<array{kunci: string, resep_item_id: string, jenis: string, tingkat: string|null, pesan: string}>
     */
    public function periksa(Pengguna $oleh, Resep $resep): array
    {
        $butir = self::butir($resep->id);
        if ($butir === []) {
            return [];
        }
        $hasil = [...$this->alergi($resep->pasien_id, $butir), ...$this->duplikasi($oleh, $resep, $butir), ...$this->formularium($resep, $butir)];
        $urut = array_flip(array_column($butir, 'id'));
        usort($hasil, fn ($a, $b) => [$urut[$a['resep_item_id']], $a['kunci']] <=> [$urut[$b['resep_item_id']], $b['kunci']]);

        return $hasil;
    }

    /** @return list<string> */
    public static function zat(?string $zatAktif): array
    {
        return array_values(array_filter(array_map(self::normal(...), explode('+', (string) $zatAktif))));
    }

    private static function normal(string $teks): string
    {
        return (string) preg_replace('/\s+/u', ' ', mb_strtolower(trim($teks)));
    }

    /**
     * @param  list<object{id: string, barang_id: string, urutan: int, nama: string, zat_aktif: string|null}>  $butir
     * @return list<array{kunci: string, resep_item_id: string, jenis: string, tingkat: string|null, pesan: string}>
     */
    private function alergi(string $pasienId, array $butir): array
    {
        $alergi = DB::table('klinis.alergi')->where('pasien_id', $pasienId)->where('status', 'aktif')->get(['id', 'zat', 'barang_id', 'tingkat']);
        $hasil = [];
        foreach ($butir as $b) {
            $zat = self::zat($b->zat_aktif);
            foreach ($alergi as $a) {
                if ($a->barang_id === $b->barang_id || in_array(self::normal($a->zat), $zat, true)) {
                    $hasil[] = ['kunci' => "$b->id:alergi:$a->id", 'resep_item_id' => $b->id, 'jenis' => 'alergi', 'tingkat' => $a->tingkat,
                        'pesan' => "Pasien tercatat alergi $a->zat."];
                }
            }
        }

        return $hasil;
    }

    /**
     * Zat sama di butir sebelumnya resep ini, atau di resep sah lain pasien ini hari ini (poli sensitif di luar cakupan tidak dihitung).
     *
     * @param  list<object{id: string, barang_id: string, urutan: int, nama: string, zat_aktif: string|null}>  $butir
     * @return list<array{kunci: string, resep_item_id: string, jenis: string, tingkat: string|null, pesan: string}>
     */
    private function duplikasi(Pengguna $oleh, Resep $resep, array $butir): array
    {
        $sensitif = KelolaPermintaanPemeriksaan::unitSensitifTerbuka($oleh);
        $lain = DB::table('farmasi.resep_item AS i')->join('farmasi.resep AS r', 'r.id', '=', 'i.resep_id')
            ->join('logistik.barang AS b', 'b.id', '=', 'i.barang_id')
            ->join('kunjungan.kunjungan AS k', 'k.id', '=', 'r.kunjungan_id')->join('inti.poliklinik AS pl', 'pl.id', '=', 'k.poli_id')
            ->where('r.pasien_id', $resep->pasien_id)->where('r.status', 'sah')->where('r.id', '<>', $resep->id)->whereNull('i.diganti_pada')
            ->whereRaw('(r.masuk_pada AT TIME ZONE ?)::date = ?', [config('instalasi.zona_waktu'), SuntinganBerversi::hariIni()])
            ->where(fn ($q) => $q->whereNull('pl.nama_netral')->orWhereIn('pl.unit_organisasi_id', $sensitif))
            ->orderBy('r.masuk_pada')->get(['r.id', 'r.no_resep', 'b.zat_aktif']);
        $hasil = [];
        $sebelumnya = [];
        foreach ($butir as $b) {
            foreach (self::zat($b->zat_aktif) as $z) {
                if (isset($sebelumnya[$z])) {
                    $hasil[] = ['kunci' => "$b->id:duplikasi:butir:{$sebelumnya[$z]}:$z", 'resep_item_id' => $b->id, 'jenis' => 'duplikasi', 'tingkat' => null,
                        'pesan' => "Zat aktif $z juga ada di butir lain resep ini."];
                }
                foreach ($lain as $l) {
                    if (in_array($z, self::zat($l->zat_aktif), true)) {
                        $hasil[] = ['kunci' => "$b->id:duplikasi:resep:$l->id:$z", 'resep_item_id' => $b->id, 'jenis' => 'duplikasi', 'tingkat' => null,
                            'pesan' => "Zat aktif $z sudah ada di resep sah lain hari ini ($l->no_resep)."];
                    }
                }
                $sebelumnya[$z] ??= $b->id;
            }
        }

        return array_values(array_unique($hasil, SORT_REGULAR));
    }

    /**
     * Formularium berlaku untuk penjamin kunjungan: milik penjamin → fornas bila JKN → rs. Tidak ada → tanpa peringatan.
     *
     * @param  list<object{id: string, barang_id: string, urutan: int, nama: string, zat_aktif: string|null}>  $butir
     * @return list<array{kunci: string, resep_item_id: string, jenis: string, tingkat: string|null, pesan: string}>
     */
    private function formularium(Resep $resep, array $butir): array
    {
        $k = DB::table('kunjungan.kunjungan')->where('id', $resep->kunjungan_id)->first(['cara_bayar', 'penjamin_id']);
        $hariIni = SuntinganBerversi::hariIni();
        $berlaku = fn (string $jenis, ?string $penjaminId = null) => DB::table('farmasi.formularium')->where('jenis', $jenis)
            ->when($penjaminId !== null, fn ($q) => $q->where('penjamin_id', $penjaminId))
            ->where('berlaku_dari', '<=', $hariIni)->where(fn ($q) => $q->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', $hariIni))
            ->orderByDesc('berlaku_dari')->first(['id', 'nama']);
        $f = ($k?->penjamin_id !== null ? $berlaku('penjamin', $k->penjamin_id) : null)
            ?? ($k?->cara_bayar === 'jkn' ? $berlaku('fornas') : null)
            ?? $berlaku('rs');
        if ($f === null) {
            return [];
        }
        $ada = DB::table('farmasi.formularium_item')->where('formularium_id', $f->id)->where('status', 'aktif')
            ->whereIn('barang_id', array_column($butir, 'barang_id'))->pluck('barang_id')->all();
        $hasil = [];
        foreach ($butir as $b) {
            if (! in_array($b->barang_id, $ada, true)) {
                $hasil[] = ['kunci' => "$b->id:non_fornas:$f->id", 'resep_item_id' => $b->id, 'jenis' => 'non_fornas', 'tingkat' => null,
                    'pesan' => "$b->nama tidak ada di formularium $f->nama yang berlaku."];
            }
        }

        return $hasil;
    }
}
