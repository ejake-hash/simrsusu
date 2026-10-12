<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Application\KelolaPermintaanAkses;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Http\Resources\PermintaanPresenter;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PermintaanAkses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Permintaan akses (kontrak /permintaan-akses*, tiket 1.2d). Membuat dan melihat milik sendiri: semua akun yang
 * masuk. Melihat semua: pengaturan.akun.lihat; menolak: pengaturan.akun.perbaiki (rute).
 */
final class PermintaanAksesController
{
    private const ALASAN = ['required', 'string', 'min:5', 'max:500'];

    public function __construct(
        private readonly KelolaPermintaanAkses $kelola,
        private readonly PermintaanPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'milik_saya' => ['nullable', Rule::in(['true', 'false', '1', '0'])],
            'status' => ['nullable', Rule::in(['menunggu', 'ditindaklanjuti', 'ditolak'])],
        ]);
        /** @var Pengguna $saya */
        $saya = $request->user();
        $milikSaya = in_array($filter['milik_saya'] ?? 'false', ['true', '1'], true);
        if (! $milikSaya && Gate::denies('pengaturan.akun.lihat', Cakupan::rs())) {
            throw new ApiError(403, 'akses_ditolak', 'Anda tidak punya izin untuk tindakan ini.');
        }

        $query = PermintaanAkses::query()
            ->when($milikSaya, fn ($q) => $q->where('pengguna_id', $saya->id))
            ->when($filter['status'] ?? null, fn ($q, $status) => $q->where('status', $status));

        return Paginasi::respons($query, $request, 'akses.permintaan_akses.dibuat_pada', 'akses.permintaan_akses.id',
            bentuk: fn ($baris) => $this->presenter->daftar($baris));
    }

    public function store(Request $request): JsonResponse
    {
        $isi = $request->validate([
            'sasaran_jenis' => ['required', Rule::in(['menu', 'izin'])],
            'sasaran_kode' => ['required', 'string', 'max:200'],
            'alasan' => self::ALASAN,
        ]);

        $permintaan = $this->kelola->buat($request->user(), $isi['sasaran_jenis'], $isi['sasaran_kode'], $isi['alasan']);

        return new JsonResponse(['data' => $this->presenter->satu($permintaan->refresh())], 201);
    }

    public function tolak(Request $request, string $permintaan): JsonResponse
    {
        $isi = $request->validate(['alasan' => self::ALASAN]);
        $p = PermintaanAkses::query()->find($permintaan) ?? throw new ApiError(404, 'tidak_ditemukan', 'Data tidak ditemukan.');

        return new JsonResponse(['data' => $this->presenter->satu($this->kelola->tolak($request->user(), $p, $isi['alasan'])->refresh())]);
    }
}
