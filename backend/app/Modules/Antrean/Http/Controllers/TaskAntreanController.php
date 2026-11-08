<?php

namespace App\Modules\Antrean\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Domain\Cakupan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Task ID antrean satu kunjungan (tiket 2.18, kontrak v0.28.0). Tanpa data pasien; respons `no-store`. */
final class TaskAntreanController
{
    public function index(Request $request, string $kunjungan): JsonResponse
    {
        Gate::authorize('integrasi.bpjs.lihat', Cakupan::rs());
        if (! DB::table('kunjungan.kunjungan')->where('id', $kunjungan)->exists()) {
            throw new ApiError(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.');
        }
        $query = DB::table('kunjungan.antrean_task AS t')->join('kunjungan.antrean AS a', 'a.id', '=', 't.antrean_id')
            ->where('t.kunjungan_id', $kunjungan)
            ->select(['t.id', 't.antrean_id', 'a.titik', 't.task_no', 't.kejadian', 't.terjadi_pada', 't.status_kirim']);

        return Paginasi::respons($query, $request, 't.terjadi_pada', 't.id', arah: 'asc', bentuk: fn (Collection $baris) => $baris->map(fn ($b) => [...(array) $b, 'terjadi_pada' => Carbon::parse($b->terjadi_pada)->toIso8601String()])->all())
            ->setPrivate()->header('Cache-Control', 'no-store, private');
    }
}
