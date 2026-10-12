<?php

namespace App\Modules\Integrasi\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Integrasi\Application\KelolaKredensial;
use App\Modules\Integrasi\Application\KotakKeluar;
use App\Modules\Integrasi\Application\StatusSistem;
use App\Modules\Integrasi\Application\UjiKoneksi;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\Integrasi\Http\Resources\IntegrasiPresenter;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Gateway integrasi (tiket 1.9, kontrak v0.13.0). Izin: konfigurasi `pengaturan.integrasi.*`; kotak keluar per tujuan
 * `integrasi.satusehat.*`/`integrasi.bpjs.*`; log `integrasi.log.lihat`. Semua cakupan rs, lewat Gate → AccessEvaluator.
 */
final class IntegrasiController
{
    /** Isi per baris log sampai 2 × 16 KB: batas halaman log lebih kecil dari batas umum. */
    private const BATAS_LOG = 50;

    public function sistem(StatusSistem $status): JsonResponse
    {
        $sistem = StatusSistem::bolehDilihat();
        abort_if($sistem === [], 403);

        return new JsonResponse(['data' => $status->bentuk($sistem)]);
    }

    public function simpanKredensial(Request $request, KelolaKredensial $kredensial, StatusSistem $status, string $kode): JsonResponse
    {
        abort_unless(Gate::allows('pengaturan.integrasi.perbaiki', Cakupan::rs()), 403);
        $sistem = SistemIntegrasi::from($kode);
        $isi = $request->validate([
            'lingkungan' => ['required', Rule::in(SistemIntegrasi::LINGKUNGAN)],
            'aktifkan' => ['sometimes', 'boolean'],
            'nonaktifkan' => ['sometimes', 'boolean'],
            'kolom' => ['sometimes', 'array'],
            'kolom.*' => ['required', 'string'],
        ]);
        $kredensial->simpan($sistem, $isi['lingkungan'], $isi['kolom'] ?? [], (bool) ($isi['aktifkan'] ?? false), (bool) ($isi['nonaktifkan'] ?? false), $request->user()->id);

        return new JsonResponse(['data' => $status->bentuk([$sistem])[0]]);
    }

    public function ujiKoneksi(Request $request, UjiKoneksi $uji, string $kode): JsonResponse
    {
        abort_unless(Gate::allows('pengaturan.integrasi.perbaiki', Cakupan::rs()), 403);
        $isi = $request->validate(['lingkungan' => ['sometimes', 'nullable', Rule::in(SistemIntegrasi::LINGKUNGAN)]]);

        return new JsonResponse(['data' => $uji->uji(SistemIntegrasi::from($kode), $isi['lingkungan'] ?? null, $request->user()->id)]);
    }

    public function kotakKeluar(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'tujuan' => ['sometimes', Rule::enum(SistemIntegrasi::class)],
            'status' => ['sometimes', Rule::in(['menunggu', 'terkirim', 'gagal', 'tertahan'])],
            'lingkungan' => ['sometimes', Rule::in(SistemIntegrasi::LINGKUNGAN)],
            'dari' => ['sometimes', 'date_format:Y-m-d'],
            'sampai' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ]);
        $boleh = array_values(array_filter(SistemIntegrasi::cases(), fn (SistemIntegrasi $s) => Gate::allows($s->subMenuPemantauan().'.lihat', Cakupan::rs())));
        $tujuan = isset($filter['tujuan']) ? [SistemIntegrasi::from($filter['tujuan'])] : $boleh;
        abort_if($boleh === [] || array_diff(array_column($tujuan, 'value'), array_column($boleh, 'value')) !== [], 403);

        $query = DB::table('integrasi.kotak_keluar')->select(IntegrasiPresenter::KOLOM_KIRIMAN)->whereIn('tujuan', array_column($tujuan, 'value'))
            ->when(isset($filter['status']), fn ($q) => $q->where('status', $filter['status']))
            ->when(isset($filter['lingkungan']), fn ($q) => $q->where('lingkungan', $filter['lingkungan']));
        self::rentang($query, 'dibuat_pada', $filter);

        return Paginasi::respons($query, $request, 'dibuat_pada', bentuk: fn ($baris) => IntegrasiPresenter::kiriman($baris));
    }

    public function kirimUlang(Request $request, KotakKeluar $kotakKeluar, string $id): JsonResponse
    {
        $baris = KotakKeluar::cari($id);
        abort_if($baris === null, 404);
        abort_unless(Gate::allows(SistemIntegrasi::from($baris->tujuan)->subMenuPemantauan().'.perbaiki', Cakupan::rs()), 403);
        $kotakKeluar->kirimUlang($baris, $request->user()->id);

        $sesudah = DB::table('integrasi.kotak_keluar')->select(IntegrasiPresenter::KOLOM_KIRIMAN)->where('id', $id)->first();

        return new JsonResponse(['data' => IntegrasiPresenter::kiriman(collect([$sesudah]))[0]]);
    }

    public function log(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('integrasi.log.lihat', Cakupan::rs()), 403);
        if ((int) $request->query('batas', '0') > self::BATAS_LOG) {
            throw new ApiError(422, 'ukuran_halaman_melebihi_batas', 'Paling banyak '.self::BATAS_LOG.' baris log per halaman.', [['kolom' => 'batas', 'pesan' => 'Maksimum '.self::BATAS_LOG.'.']]);
        }
        $filter = $request->validate([
            'tujuan' => ['sometimes', Rule::enum(SistemIntegrasi::class)],
            'berhasil' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'kotak_keluar_id' => ['sometimes', 'uuid'],
            'dari' => ['sometimes', 'date_format:Y-m-d'],
            'sampai' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ]);
        $query = DB::table('integrasi.log_api')
            ->when(isset($filter['tujuan']), fn ($q) => $q->where('tujuan', $filter['tujuan']))
            ->when(isset($filter['berhasil']), fn ($q) => $q->where('berhasil', in_array($filter['berhasil'], ['true', '1'], true)))
            ->when(isset($filter['kotak_keluar_id']), fn ($q) => $q->where('kotak_keluar_id', $filter['kotak_keluar_id']));
        self::rentang($query, 'terjadi_pada', $filter);

        return Paginasi::respons($query, $request, 'terjadi_pada', bentuk: fn ($baris) => IntegrasiPresenter::log($baris));
    }

    /**
     * Tanggal (zona RS) inklusif → rentang waktu.
     *
     * @param  array<string, mixed>  $filter
     */
    private static function rentang(Builder $query, string $kolom, array $filter): void
    {
        $zona = (string) config('instalasi.zona_waktu');
        if (isset($filter['dari'])) {
            $query->where($kolom, '>=', Carbon::parse($filter['dari'], $zona)->startOfDay());
        }
        if (isset($filter['sampai'])) {
            $query->where($kolom, '<', Carbon::parse($filter['sampai'], $zona)->addDay()->startOfDay());
        }
    }
}
