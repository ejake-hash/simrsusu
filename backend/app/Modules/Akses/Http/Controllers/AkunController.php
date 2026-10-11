<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Application\MenuSaya;
use App\Modules\Akses\Http\Resources\AkunPresenter;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Pengaturan → Akun & akses: daftar, rincian, dan Lihat sebagai (kontrak /akun, /akun/{id}, /akun/{id}/menu). */
final class AkunController
{
    public function __construct(private readonly AkunPresenter $presenter) {}

    public function index(Request $request, RingkasanPegawai $pegawai): JsonResponse
    {
        $filter = $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['aktif', 'nonaktif', 'terkunci'])],
            'peran_id' => ['nullable', 'uuid'],
            'mfa' => ['nullable', Rule::in(['aktif', 'belum'])],
        ]);
        $sekarang = now();

        $query = Pengguna::query()
            ->when($filter['cari'] ?? null, fn ($q, $cari) => $q->where(fn ($q) => $q
                ->whereRaw('nama_pengguna ILIKE ?', ['%'.RingkasanPegawai::escapeLike($cari).'%'])
                ->orWhereIn('pegawai_id', $pegawai->idCocokNama($cari))))
            ->when($filter['status'] ?? null, fn ($q, $status) => match ($status) {
                'terkunci' => $q->where('terkunci_sampai', '>', $sekarang),
                'nonaktif' => $q->where('status', 'nonaktif'),
                default => $q->where('status', 'aktif')->where(fn ($q) => $q->whereNull('terkunci_sampai')->orWhere('terkunci_sampai', '<=', $sekarang)),
            })
            ->when($filter['peran_id'] ?? null, fn ($q, $peranId) => $q->whereIn('id',
                PenggunaPeran::query()->berlaku()->where('peran_id', $peranId)->select('pengguna_id')))
            ->when($filter['mfa'] ?? null, fn ($q, $mfa) => $mfa === 'aktif'
                ? $q->whereNotNull('mfa_aktif_pada')->whereNotNull('mfa_rahasia_enkripsi')
                : $q->where(fn ($q) => $q->whereNull('mfa_aktif_pada')->orWhereNull('mfa_rahasia_enkripsi')));

        return Paginasi::respons($query, $request, 'dibuat_pada', arah: 'asc', bentuk: fn ($baris) => $this->presenter->ringkas($baris));
    }

    public function show(Request $request, Pengguna $akun): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->rinci($akun, $request->user())]);
    }

    /** Lihat sebagai: menu efektif akun lain dari AccessEvaluator yang sama; tidak membuat sesi atas namanya. */
    public function menu(Request $request, Pengguna $akun, MenuSaya $menu, CatatAktivitas $audit): JsonResponse
    {
        $data = $menu->untuk($akun);
        $audit->catat('akses.lihat_sebagai', $request->user()->id, 'akses.pengguna', $akun->id, ['versi' => $data['versi']]);

        return new JsonResponse(['data' => $data]);
    }
}
