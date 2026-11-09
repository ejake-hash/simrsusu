<?php

namespace App\Modules\Antrean\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\EtagIsi;
use App\Modules\Antrean\Application\KelolaAntrean;
use App\Modules\MasterData\Models\Poliklinik;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/display/{area} (tiket 2.8; FR-02-04, FR-02-15). Perangkat display dengan token perangkat (middleware
 * TokenPerangkat:display). Hanya nomor tampil dan inisial; poli sensitif memakai nama netral; teks suara tanpa nama; tidak
 * ada id pasien, kunjungan, antrean, No. RM, atau tanggal lahir. ETag dari isi tanpa `waktu_server` → 304 bila sama.
 */
final class DisplayController
{
    public function __invoke(Request $request, string $area): Response
    {
        $poli = Poliklinik::query()->whereRaw(KelolaAntrean::SQL_AREA_POLI.' = ?', [$area])->orderBy('kode')
            ->get(['id', 'kode', 'nama', 'nama_netral', 'antrean_area']);
        // Area loket admisi (tiket 2.20): nama dari lokasi loket anjungan yang mengisi area itu; nomor loket tanpa pasien.
        $loket = DB::table('kunjungan.anjungan AS j')->join('inti.lokasi AS l', 'l.id', '=', DB::raw('coalesce(j.lokasi_loket_id, j.lokasi_id)'))
            ->where('j.area_loket', $area)->distinct()->orderBy('l.nama')->pluck('l.nama');
        if ($poli->isEmpty() && $loket->isEmpty()) {
            throw new ApiError(404, 'tidak_ditemukan', 'Area display tidak dikenal.');
        }
        $namaPoli = $poli->mapWithKeys(fn (Poliklinik $p) => [$p->id => $p->nama_netral ?? $p->nama]);
        // Nama ruang poli sensitif bisa membuka poli aslinya (mis. "Ruang Poli Jiwa"): yang tampil hanya nama netral.
        $sensitif = $poli->whereNotNull('nama_netral')->pluck('id')->flip();
        $hariIni = now((string) config('instalasi.zona_waktu'))->toDateString();
        $dasar = fn () => DB::table('kunjungan.antrean AS a')->leftJoin('kunjungan.kunjungan AS k', 'k.id', '=', 'a.kunjungan_id')
            ->leftJoin('pasien.pasien AS p', 'p.id', '=', 'k.pasien_id')->where('a.tanggal', $hariIni)->where('a.area', $area);

        $dipanggil = $dasar()->leftJoin('inti.lokasi AS l', 'l.id', '=', 'a.ruang_id')->where('a.status', 'dipanggil')
            ->orderByDesc('a.dipanggil_pada')->orderByDesc('a.id')->limit((int) config('antrean.display.jumlah_dipanggil'))
            ->get(['a.nomor_tampil', 'a.poli_id', 'a.panggilan_ke', 'a.dipanggil_pada', 'l.nama AS ruang', 'p.nama', 'p.tanpa_identitas'])
            ->map(function ($b) use ($namaPoli, $sensitif) {
                $poli = $b->poli_id === null ? null : ($namaPoli[$b->poli_id] ?? null);
                $ruang = isset($sensitif[$b->poli_id]) ? null : $b->ruang;
                $ke = $ruang ?? $poli ?? '';

                return [
                    'nomor_tampil' => $b->nomor_tampil,
                    'inisial' => self::inisial($b),
                    'tujuan' => implode(' · ', array_filter([$ruang, $poli])),
                    'panggilan_ke' => (int) $b->panggilan_ke,
                    'dipanggil_pada' => Carbon::parse($b->dipanggil_pada)->toIso8601String(),
                    'teks_suara' => 'Nomor '.implode(' ', str_split(str_replace('-', '', $b->nomor_tampil))).($ke === '' ? '' : ', silakan ke '.$ke),
                ];
            })->all();
        $berikutnya = $dasar()->where('a.status', 'menunggu')->orderBy('a.urutan')->orderBy('a.nomor')
            ->limit((int) config('antrean.display.jumlah_berikutnya'))->get(['a.nomor_tampil', 'p.nama', 'p.tanpa_identitas'])
            ->map(fn ($b) => ['nomor_tampil' => $b->nomor_tampil, 'inisial' => self::inisial($b)])->all();

        $isi = [
            'area' => ['kode' => $area, 'nama' => $namaPoli->toBase()->unique()->merge($loket)->implode(', ')],
            'dipanggil' => $dipanggil,
            'berikutnya' => $berikutnya,
            'interval_detik' => max(5, min(10, (int) config('antrean.display.interval_detik'))),
        ];
        $respons = new JsonResponse(['data' => $isi]);
        $hasil = EtagIsi::terapkan($request, $respons);
        if ($hasil === $respons) {
            $respons->setData(['data' => [...$isi, 'waktu_server' => now()->toIso8601String()]]);
        }
        $hasil->headers->set('Cache-Control', 'no-store');

        return $hasil;
    }

    /**
     * Huruf awal paling banyak dua kata nama (`S.W.`). Pasien tanpa identitas → null.
     * shortcut: label privasi tinggi (B-18) per pasien belum ada di basis data; tambahkan syaratnya saat label pasien dibangun.
     */
    private static function inisial(object $baris): ?string
    {
        if ($baris->nama === null || $baris->tanpa_identitas) {
            return null;
        }
        $huruf = [];
        foreach (preg_split('/\s+/u', trim((string) $baris->nama), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $kata) {
            if (preg_match('/\p{L}/u', $kata, $m) === 1) {
                $huruf[] = mb_strtoupper($m[0]).'.';
            }
            if (count($huruf) === 2) {
                break;
            }
        }

        return $huruf === [] ? null : implode('', $huruf);
    }
}
