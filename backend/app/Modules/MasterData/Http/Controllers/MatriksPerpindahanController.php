<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Http\Paginasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\MasterData\Models\AturanPerpindahan;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/data-induk/matriks-perpindahan (kontrak v0.33.0, tiket 2.26; FR-00-02). Hanya baca: perubahan lewat
 * usulan data induk (`jenis_data` `matriks_perpindahan`). Boleh untuk pengelola data induk
 * (master_data.matriks_perpindahan.lihat) atau dokter IGD yang memilih tujuan disposisi (igd.disposisi.lihat), keduanya
 * cakupan rs, diperiksa lewat Gate → AccessEvaluator sebelum isian dibaca.
 */
final class MatriksPerpindahanController
{
    public function index(Request $request): JsonResponse
    {
        if (! Gate::allows('master_data.matriks_perpindahan.lihat', Cakupan::rs()) && ! Gate::allows('igd.disposisi.lihat', Cakupan::rs())) {
            throw new AuthorizationException;
        }
        $filter = $request->validate([
            'pos_asal' => ['nullable', Rule::in(AturanPerpindahan::POS_ASAL)],
            'status' => ['nullable', Rule::in(['aktif', 'nonaktif'])],
        ]);
        $query = AturanPerpindahan::query()
            ->when($filter['pos_asal'] ?? null, fn ($q, $pos) => $q->where('pos_asal', $pos))
            ->when($filter['status'] ?? null, fn ($q, $status) => $q->where('status', $status));

        return Paginasi::respons($query, $request, 'pos_asal', arah: 'asc',
            bentuk: fn ($baris) => $baris->map(fn (AturanPerpindahan $a) => self::bentuk($a))->values()->all())
            ->header('Cache-Control', 'no-store');
    }

    /** @return array<string, mixed> */
    public static function bentuk(AturanPerpindahan $a): array
    {
        return [
            'id' => $a->id, 'pos_asal' => $a->pos_asal, 'pos_tujuan' => $a->pos_tujuan, 'izin_perintah' => $a->izin_perintah,
            'keterangan' => $a->keterangan, 'status' => $a->status, 'versi' => (string) $a->revisi, 'diubah_pada' => $a->diubah_pada->toIso8601String(),
        ];
    }
}
