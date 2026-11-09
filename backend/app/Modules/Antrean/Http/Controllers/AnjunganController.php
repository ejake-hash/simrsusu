<?php

namespace App\Modules\Antrean\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\Paginasi;
use App\Core\Http\SuntinganBerversi;
use App\Modules\Antrean\Application\KelolaAntrean;
use App\Modules\Antrean\Models\Anjungan;
use App\Modules\Antrean\Models\Antrean;
use App\Modules\MasterData\Http\Resources\JadwalPraktikResource;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\MasterData\Models\Lokasi;
use App\Modules\MasterData\Models\Poliklinik;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * /api/v1/anjungan/* (tiket 2.20; PRD 7.01.1, FR-01-28). Perangkat bertoken kemampuan `anjungan` (middleware TokenPerangkat),
 * bukan akun orang. Tanpa data pasien di permintaan maupun respons; respons `no-store`.
 */
final class AnjunganController
{
    public const ALASAN_PRIORITAS = ['lansia', 'disabilitas', 'ibu_hamil', 'anak_khusus'];

    /** Konfigurasi perangkat; sekaligus menandai perangkat online (FR-01-30). */
    public function show(Request $request): JsonResponse
    {
        $anjungan = self::pendaftaran($request);
        $anjungan->forceFill(['terakhir_aktif_pada' => now()])->saveQuietly();

        return self::json(['kode' => $anjungan->kode, 'lokasi' => (string) Lokasi::query()->whereKey($anjungan->lokasi_id)->value('nama'),
            'mode' => $anjungan->mode, 'batas_diam_detik' => $anjungan->batas_diam_detik, 'layanan' => ['nomor_loket', 'jadwal_praktik']]);
    }

    public function nomorLoket(Request $request, KelolaAntrean $kelola): JsonResponse
    {
        $anjungan = self::pendaftaran($request);
        $prioritas = $request->boolean('prioritas');
        $isi = $request->validate([
            'prioritas' => ['required', 'boolean'],
            'alasan_prioritas' => ['nullable', Rule::requiredIf($prioritas), Rule::prohibitedIf(! $prioritas), Rule::in(self::ALASAN_PRIORITAS)],
        ]);
        $antrean = $kelola->buatLoket($anjungan, $prioritas ? $isi['alasan_prioritas'] : null);
        $didepan = Antrean::query()->where('titik', 'loket')->where('tanggal', $antrean->tanggal->toDateString())->where('status', 'menunggu')
            ->whereKeyNot($antrean->id)->whereRaw('(urutan, nomor) < (?::numeric, ?)', [$antrean->urutan, $antrean->nomor])->count();

        return self::json([
            'nomor_tampil' => $antrean->nomor_tampil,
            'prioritas' => $antrean->prioritas,
            'tanggal' => $antrean->tanggal->toDateString(),
            'diambil_pada' => $antrean->dibuat_pada->toIso8601String(),
            'menunggu_di_depan' => $didepan,
            'lokasi' => (string) Lokasi::query()->whereKey($anjungan->lokasi_loket_id ?? $anjungan->lokasi_id)->value('nama'),
        ], 201);
    }

    /** Jadwal dokter satu tanggal (hari ini s.d. +6, zona RS): hanya status, tanpa id pegawai, kuota angka, atau alasan cuti. */
    public function jadwalPraktik(Request $request, JadwalPraktikResource $resource): JsonResponse
    {
        $hariIni = SuntinganBerversi::hariIni();
        $filter = $request->validate([
            'tanggal' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$hariIni, 'before_or_equal:'.Carbon::parse($hariIni)->addDays(6)->toDateString()],
            'poli_id' => ['nullable', 'uuid'],
        ]);
        $tanggal = $filter['tanggal'] ?? $hariIni;
        $query = JadwalPraktik::query()->where('status', 'aktif')->where('hari', Carbon::parse($tanggal)->isoWeekday())
            ->where('berlaku_dari', '<=', $tanggal)->where(fn ($w) => $w->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', $tanggal))
            ->when($filter['poli_id'] ?? null, fn ($q, $id) => $q->where('poli_id', $id));

        return Paginasi::respons($query, $request, 'jam_mulai', arah: 'asc', bentuk: function ($baris) use ($resource, $tanggal) {
            $poli = Poliklinik::query()->whereIn('id', $baris->pluck('poli_id')->unique())->get(['id', 'nama', 'nama_netral'])->keyBy('id');

            return array_map(fn (array $j) => [
                'poliklinik' => ['id' => $j['poliklinik']['id'], 'nama' => $poli[$j['poliklinik']['id']]->nama_netral ?? $j['poliklinik']['nama']],
                'dokter' => $j['pegawai']['nama'],
                'jam_mulai' => $j['jam_mulai'],
                'jam_selesai' => $j['jam_selesai'],
                'status' => match (true) {
                    ! $j['pada_tanggal']['tersedia'] => 'tidak_praktik',
                    // Anjungan tidak tahu cara bayar pasien: penuh bila kuota JKN dan non-JKN sama-sama habis.
                    $j['pada_tanggal']['sisa_jkn'] + $j['pada_tanggal']['sisa_non_jkn'] === 0 => 'penuh',
                    default => 'tersedia',
                },
            ], $resource->jadwal($baris, $tanggal));
        })->header('Cache-Control', 'no-store');
    }

    private static function pendaftaran(Request $request): Anjungan
    {
        /** @var Anjungan $anjungan */
        $anjungan = $request->attributes->get('perangkat');
        if ($anjungan->mode !== 'pendaftaran') {
            throw new ApiError(409, 'mode_belum_didukung', 'Mode perangkat ini belum didukung.');
        }

        return $anjungan;
    }

    /** @param  array<string, mixed>  $data */
    private static function json(array $data, int $status = 200): JsonResponse
    {
        return (new JsonResponse(['data' => $data], $status))->header('Cache-Control', 'no-store');
    }
}
