<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Application\AlurPersetujuan;
use App\Modules\Akses\Http\Resources\PersetujuanPresenter;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PersetujuanAkses;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Antrean persetujuan akses (kontrak /persetujuan-akses*, tiket 1.2d). Tanpa izin khusus: setiap akun hanya melihat
 * yang relevan baginya (pengaju, penerima, atau pemegang peran penyetuju); selain itu 404. Aturan keputusan di
 * AlurPersetujuan.
 */
final class PersetujuanAksesController
{
    private const ALASAN = ['required', 'string', 'min:5', 'max:500'];

    public function __construct(
        private readonly AlurPersetujuan $alur,
        private readonly PersetujuanPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'tab' => ['required', Rule::in(['perlu_saya_putuskan', 'saya_ajukan'])],
            'status' => ['nullable', Rule::in(['menunggu', 'disetujui', 'ditolak', 'kedaluwarsa', 'dibatalkan'])],
        ]);
        /** @var Pengguna $saya */
        $saya = $request->user();

        $query = $filter['tab'] === 'perlu_saya_putuskan'
            ? $this->alur->perluDiputuskan($saya, $this->alur->peranDipegang($saya))
            : PersetujuanAkses::query()->where('diajukan_oleh', $saya->id);
        $this->saringStatus($query, $filter['status'] ?? null);

        return Paginasi::respons($query, $request, 'akses.persetujuan_akses.dibuat_pada', 'akses.persetujuan_akses.id',
            bentuk: fn ($baris) => $this->presenter->daftar($baris, $saya));
    }

    public function show(Request $request, string $persetujuan): JsonResponse
    {
        return $this->respons($this->temukan($request, $persetujuan), $request);
    }

    public function setujui(Request $request, string $persetujuan): JsonResponse
    {
        $isi = $request->validate(['catatan' => ['nullable', 'string', 'max:500']]);

        return $this->respons($this->alur->setujui($request->user(), $this->temukan($request, $persetujuan), $isi['catatan'] ?? null), $request);
    }

    public function tolak(Request $request, string $persetujuan): JsonResponse
    {
        $isi = $request->validate(['alasan' => self::ALASAN]);

        return $this->respons($this->alur->tolak($request->user(), $this->temukan($request, $persetujuan), $isi['alasan']), $request);
    }

    public function batalkan(Request $request, string $persetujuan): JsonResponse
    {
        $isi = $request->validate(['alasan' => self::ALASAN]);

        return $this->respons($this->alur->batalkan($request->user(), $this->temukan($request, $persetujuan), $isi['alasan']), $request);
    }

    /** Kedaluwarsa = juga yang masih tercatat menunggu tetapi sudah lewat batas waktu (tugas harian belum jalan). */
    private function saringStatus(Builder $query, ?string $status): void
    {
        $sekarang = now()->format('Y-m-d H:i:s.uP');
        match ($status) {
            null => null,
            'menunggu' => $query->where('status', 'menunggu')->where('kedaluwarsa_pada', '>', $sekarang),
            'kedaluwarsa' => $query->where(fn ($q) => $q->where('status', 'kedaluwarsa')
                ->orWhere(fn ($q) => $q->where('status', 'menunggu')->where('kedaluwarsa_pada', '<=', $sekarang))),
            default => $query->where('status', $status),
        };
    }

    private function temukan(Request $request, string $id): PersetujuanAkses
    {
        /** @var Pengguna $saya */
        $saya = $request->user();
        $p = PersetujuanAkses::query()->find($id);
        $terlihat = $p !== null && ($p->diajukan_oleh === $saya->id || $p->sasaran_pengguna_id === $saya->id
            || in_array($p->penyetuju_peran_id, $this->alur->peranDipegang($saya)['berlaku'], true));
        if (! $terlihat) {
            throw new ApiError(404, 'tidak_ditemukan', 'Data tidak ditemukan.');
        }

        return $p;
    }

    private function respons(PersetujuanAkses $p, Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->satu($p->refresh(), $request->user())]);
    }
}
