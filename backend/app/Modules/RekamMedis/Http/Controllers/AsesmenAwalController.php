<?php

namespace App\Modules\RekamMedis\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\IfMatch;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\RekamMedis\Application\KelolaAsesmenAwal;
use App\Modules\RekamMedis\Models\CatatanKlinis;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Asesmen awal keperawatan RJ (tiket 2.13, kontrak v0.23.0). Izin diperiksa dulu di cakupan mana pun (tanpa izin, ada
 * atau tidaknya id tidak terbaca), lalu terhadap unit poliklinik kunjungannya. Respons `no-store`.
 */
final class AsesmenAwalController
{
    private const LIHAT = ['rawat_jalan.asesmen_perawat.lihat', 'rawat_jalan.pemeriksaan.lihat'];

    public function __construct(private readonly KelolaAsesmenAwal $kelola, private readonly RingkasanPegawai $pegawai) {}

    public function show(string $kunjungan): JsonResponse
    {
        $k = self::kunjungan(self::LIHAT, $kunjungan);
        $catatan = CatatanKlinis::query()->where('kunjungan_id', $k->id)->where('jenis', 'asesmen')->where('profesi', 'perawat')->first()
            ?? throw new ApiError(404, 'tidak_ditemukan', 'Kunjungan ini belum punya asesmen awal.');

        return $this->satu($catatan);
    }

    public function store(Request $request, string $kunjungan): JsonResponse
    {
        $k = self::kunjungan(['rawat_jalan.asesmen_perawat.input'], $kunjungan);
        $isian = $request->validate(self::aturan());

        return $this->satu($this->kelola->buat($request->user(), $k, $k->unit_organisasi_id, $isian), 201);
    }

    public function update(Request $request, string $asesmen): JsonResponse
    {
        $catatan = self::asesmen('rawat_jalan.asesmen_perawat.perbaiki', $asesmen);
        $isian = $request->validate([...self::aturan(), 'alasan_perubahan' => ['sometimes', 'string', 'min:5', 'max:500']]);

        return $this->satu($this->kelola->ubah($request->user(), $catatan, IfMatch::versi($request), $isian));
    }

    public function selesai(Request $request, string $asesmen): JsonResponse
    {
        $catatan = self::asesmen('rawat_jalan.asesmen_perawat.input', $asesmen);

        return $this->satu($this->kelola->selesai($request->user(), $catatan, IfMatch::versi($request)));
    }

    /** @return array<string, list<mixed>> */
    private static function aturan(): array
    {
        $teks = fn (int $maks) => ['sometimes', 'nullable', 'string', "max:$maks"];
        $angka = fn (string $jenis, float $min, float $maks) => ['sometimes', 'nullable', $jenis, "min:$min", "max:$maks"];

        return [
            'keluhan_utama' => $teks(2000), 'riwayat_penyakit_sekarang' => $teks(4000), 'riwayat_penyakit_dahulu' => $teks(4000), 'catatan' => $teks(4000),
            'tanda_vital' => ['sometimes', 'array:sistolik,diastolik,nadi,napas,suhu,spo2,berat_badan,tinggi_badan'],
            'tanda_vital.sistolik' => $angka('integer', 30, 300), 'tanda_vital.diastolik' => $angka('integer', 10, 200),
            'tanda_vital.nadi' => $angka('integer', 10, 300), 'tanda_vital.napas' => $angka('integer', 2, 100),
            'tanda_vital.suhu' => $angka('numeric', 25, 45), 'tanda_vital.spo2' => $angka('integer', 30, 100),
            'tanda_vital.berat_badan' => $angka('numeric', 0.3, 400), 'tanda_vital.tinggi_badan' => $angka('numeric', 20, 250),
            'skrining' => ['sometimes', 'array:skala_nyeri,lokasi_nyeri,risiko_jatuh,gizi_berisiko,catatan'],
            'skrining.skala_nyeri' => $angka('integer', 0, 10), 'skrining.lokasi_nyeri' => $teks(200),
            'skrining.risiko_jatuh' => ['sometimes', 'nullable', Rule::in(['rendah', 'sedang', 'tinggi'])],
            'skrining.gizi_berisiko' => ['sometimes', 'nullable', 'boolean'], 'skrining.catatan' => $teks(1000),
        ];
    }

    /**
     * @param  list<string>  $izin  salah satu cukup
     * @return object{id: string, episode_id: string, pasien_id: string, status: string, unit_organisasi_id: string}
     */
    public static function kunjungan(array $izin, string $id): object
    {
        $pengguna = request()->user();
        if (! array_any($izin, fn ($i) => Gate::allows($i))) {
            throw new ApiError(403, 'akses_ditolak', 'Anda tidak memiliki akses ke halaman ini.');
        }
        $k = DB::table('kunjungan.kunjungan AS k')->join('inti.poliklinik AS p', 'p.id', '=', 'k.poli_id')->where('k.id', $id)
            ->first(['k.id', 'k.episode_id', 'k.pasien_id', 'k.status', 'p.unit_organisasi_id'])
            ?? throw new ApiError(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.');
        $cakupan = new Cakupan('unit', $k->unit_organisasi_id);
        if (! $pengguna instanceof Pengguna || ! array_any($izin, fn ($i) => Gate::allows($i, $cakupan))) {
            throw new ApiError(403, 'akses_ditolak', 'Kunjungan ini di luar cakupan akses Anda.');
        }

        return $k;
    }

    private static function asesmen(string $izin, string $id): CatatanKlinis
    {
        Gate::authorize($izin);
        $catatan = CatatanKlinis::query()->whereKey($id)->where('jenis', 'asesmen')->first()
            ?? throw new ApiError(404, 'tidak_ditemukan', 'Asesmen tidak ditemukan.');
        Gate::authorize($izin, new Cakupan('unit', $catatan->unit_id));

        return $catatan;
    }

    private function satu(CatatanKlinis $c, int $status = 200): JsonResponse
    {
        $penulis = Pengguna::query()->find($c->penulis_id, ['id', 'pegawai_id', 'nama_pengguna']);
        $nama = $penulis?->pegawai_id ? ($this->pegawai->nama([$penulis->pegawai_id])[$penulis->pegawai_id] ?? null) : null;
        $isi = $c->isi;

        return (new JsonResponse(['data' => [
            'id' => $c->id, 'kunjungan_id' => $c->kunjungan_id, 'episode_id' => $c->episode_id, 'status' => $c->status,
            'keluhan_utama' => $isi['keluhan_utama'] ?? null, 'riwayat_penyakit_sekarang' => $isi['riwayat_penyakit_sekarang'] ?? null,
            'riwayat_penyakit_dahulu' => $isi['riwayat_penyakit_dahulu'] ?? null, 'tanda_vital' => $isi['tanda_vital'], 'imt' => $isi['imt'],
            'skrining' => $isi['skrining'], 'catatan' => $isi['catatan'] ?? null,
            'penulis' => $penulis === null ? null : ['id' => $penulis->id, 'nama' => $nama ?? $penulis->nama_pengguna],
            'dibuat_pada' => $c->dibuat_pada->toIso8601String(), 'selesai_pada' => $c->selesai_pada?->toIso8601String(),
            'batas_perbaikan_pada' => $c->batas_perbaikan_pada?->toIso8601String(), 'jumlah_versi' => $c->jumlah_versi,
            'versi' => (string) $c->revisi, 'diubah_pada' => $c->diubah_pada->toIso8601String(),
        ]], $status))->setEtag((string) $c->revisi)->setPrivate()->header('Cache-Control', 'no-store, private');
    }
}
