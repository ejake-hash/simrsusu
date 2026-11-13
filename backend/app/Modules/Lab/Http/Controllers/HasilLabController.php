<?php

namespace App\Modules\Lab\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Application\AksesDaruratAktif;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Lab\Application\AksesHasilLab;
use App\Modules\Lab\Application\BacaDaftarKerjaLab;
use App\Modules\Lab\Application\BacaHasilLab;
use App\Modules\Lab\Application\KelolaHasilLab;
use App\Modules\Lab\Application\RuteHasilLab;
use App\Modules\RekamMedis\Application\KelolaPermintaanPemeriksaan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Hasil lab (tiket 2.31, kontrak v0.41.0): input (penunjang.laboratorium.input), validasi analis
 * (penunjang.laboratorium.sahkan) atau otorisasi Sp.PK (penunjang.laboratorium.setujui), adendum (sahkan; setujui bila butuh Sp.PK), semuanya
 * cakupan RS; linimasa hasil tervalidasi per episode (pola daftar order episode 2.16). Respons `no-store`.
 */
final class HasilLabController
{
    public function __construct(
        private readonly KelolaHasilLab $kelola,
        private readonly BacaHasilLab $baca,
        private readonly AksesHasilLab $akses,
        private readonly AksesDaruratAktif $darurat,
        private readonly RuteHasilLab $rute,
    ) {}

    public function input(Request $request, string $item): JsonResponse
    {
        Gate::authorize(BacaDaftarKerjaLab::IZIN_AMBIL, Cakupan::rs());
        $isian = $request->validate([
            'nilai' => ['nullable', 'numeric', 'between:-999999999999,999999999999'],
            'nilai_teks' => ['nullable', 'string', 'max:500'],
            'catatan' => ['nullable', 'string', 'max:1000'],
        ]);
        [$id, $baru] = $this->kelola->input($request->user(), $item, $isian);

        return $this->satu($id, $request->user(), $baru ? 201 : 200);
    }

    public function validasi(Request $request, string $hasil): JsonResponse
    {
        // Tanpa salah satu izin tahap → 403 sebelum mencari hasil; izin tahap yang tepat diperiksa KelolaHasilLab dari status.
        if (! Gate::allows(BacaHasilLab::IZIN_SAHKAN, Cakupan::rs())) {
            Gate::authorize(BacaHasilLab::IZIN_SETUJUI, Cakupan::rs());
        }

        return $this->satu($this->kelola->validasi($request->user(), $hasil), $request->user());
    }

    public function adendum(Request $request, string $hasil): JsonResponse
    {
        // Izin tepat (sahkan, atau setujui untuk pemeriksaan butuh otorisasi Sp.PK) diperiksa KelolaHasilLab dari data induk.
        if (! Gate::allows(BacaHasilLab::IZIN_SAHKAN, Cakupan::rs())) {
            Gate::authorize(BacaHasilLab::IZIN_SETUJUI, Cakupan::rs());
        }
        $isian = $request->validate([
            'nilai' => ['nullable', 'numeric', 'between:-999999999999,999999999999'],
            'nilai_teks' => ['nullable', 'string', 'max:500'],
            'alasan' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        return $this->satu($this->kelola->adendum($request->user(), $hasil, $isian), $request->user(), 201);
    }

    /** Linimasa hasil tervalidasi satu episode; membukanya menyelesaikan tugas tindak lanjut milik akun ini. */
    public function episode(Request $request, string $episode): JsonResponse
    {
        $izin = KelolaPermintaanPemeriksaan::IZIN_LIHAT;
        Gate::authorize($izin);
        abort_unless(DB::table('kunjungan.episode')->where('id', $episode)->exists(), 404);
        /** @var Pengguna $pengguna */
        $pengguna = $request->user();
        if (! $this->akses->bolehBukaEpisode($pengguna, $episode)) {
            throw new ApiError(403, 'akses_ditolak', 'Episode ini di luar cakupan akses Anda.');
        }
        $this->darurat->catatBaca($request, AksesDaruratAktif::pasienEpisode($episode), null, 'hasil_lab');
        $sensitif = KelolaPermintaanPemeriksaan::unitSensitifTerbuka($pengguna);
        $terlihat = DB::table('kunjungan.kunjungan AS k')->join('inti.poliklinik AS pl', 'pl.id', '=', 'k.poli_id')->where('k.episode_id', $episode)
            ->where(fn ($q) => $q->whereNull('pl.nama_netral')->orWhereIn('pl.unit_organisasi_id', $sensitif))->select('k.id');
        $query = DB::table('lab.hasil AS h')->join('permintaan.permintaan_item AS i', 'i.id', '=', 'h.permintaan_item_id')
            ->join('permintaan.permintaan_pemeriksaan AS p', 'p.id', '=', 'i.permintaan_pemeriksaan_id')
            ->where('p.episode_id', $episode)->whereIn('p.kunjungan_id', $terlihat)->where('h.status', 'divalidasi')
            ->select(['h.id', 'h.dikeluarkan_pada']);

        return Paginasi::respons($query, $request, 'h.dikeluarkan_pada', 'h.id', 'desc', function (Collection $baris) use ($pengguna) {
            $id = $baris->pluck('id')->map(fn ($v) => (string) $v)->values()->all();
            $hasil = $this->baca->untuk($id, $pengguna);
            $objek = [...$id, ...array_merge(...array_map(fn (array $h) => array_column($h['adendum'], 'id'), array_values($hasil)))];
            $this->rute->selesaikanDibuka($pengguna, $objek);

            return array_values($hasil);
        })->setPrivate()->header('Cache-Control', 'no-store, private');
    }

    private function satu(string $id, Pengguna $pengguna, int $status = 200): JsonResponse
    {
        return (new JsonResponse(['data' => $this->baca->satu($id, $pengguna)], $status))
            ->setPrivate()->header('Cache-Control', 'no-store, private');
    }
}
