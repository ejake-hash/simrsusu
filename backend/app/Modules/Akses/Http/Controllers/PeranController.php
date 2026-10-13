<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Core\Database\Like;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Application\KelolaPeran;
use App\Modules\Akses\Application\PenjagaPenyunting;
use App\Modules\Akses\Http\Resources\PeranResource;
use App\Modules\Akses\Http\Resources\PeranRinciResource;
use App\Modules\Akses\Http\Resources\PersetujuanPresenter;
use App\Modules\Akses\Models\Peran;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Pengaturan → Peran & izin (kontrak /peran*). Izin diperiksa di rute (can.rs:pengaturan.peran.*, cakupan seluruh RS);
 * aturan bisnis di KelolaPeran.
 */
final class PeranController
{
    private const DASAR = ['required', 'string', 'min:3', 'max:200'];

    public function __construct(
        private readonly KelolaPeran $kelola,
        private readonly PersetujuanPresenter $persetujuan,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['aktif', 'nonaktif'])],
        ]);

        $query = Peran::query()->denganRingkasan()
            ->when($filter['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filter['cari'] ?? null, function ($q, $cari) {
                $pola = Like::memuat($cari);
                $q->where(fn ($q) => $q->whereRaw('kode ILIKE ?', [$pola])->orWhereRaw('nama ILIKE ?', [$pola]));
            });

        return Paginasi::respons($query, $request, 'akses.peran.dibuat_pada', 'akses.peran.id', arah: 'asc',
            bentuk: fn ($baris) => PeranResource::collection($baris)->resolve($request));
    }

    public function store(Request $request): JsonResponse
    {
        $isi = $request->validate([
            'kode' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,49}$/'],
            'nama' => ['required', 'string', 'min:3', 'max:100'],
            'uraian' => ['nullable', 'string', 'max:500'],
            'wajib_mfa' => ['required', 'boolean'],
            'salin_dari_id' => ['nullable', 'uuid', Rule::exists(Peran::class, 'id')],
            'dasar_perubahan' => self::DASAR,
        ]);

        $peran = $this->kelola->buat($request->user(), $isi);

        return $this->rinci($request, $peran, 201);
    }

    public function show(Request $request, Peran $peran): JsonResponse
    {
        return $this->rinci($request, $peran);
    }

    public function update(Request $request, Peran $peran): JsonResponse
    {
        $isi = $request->validate([
            'nama' => ['sometimes', 'string', 'min:3', 'max:100'],
            'uraian' => ['sometimes', 'nullable', 'string', 'max:500'],
            'wajib_mfa' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['aktif', 'nonaktif'])],
            'masa_percaya_hari' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:32767'],
            'dasar_perubahan' => self::DASAR,
        ]);
        if (count($isi) < 2) {
            throw ValidationException::withMessages(['nama' => 'Isi minimal satu perubahan (nama, uraian, wajib MFA, status, atau masa percaya).']);
        }
        $versi = PenjagaPenyunting::versiDiminta($request);

        return $this->rinci($request, $this->kelola->ubah($request->user(), $peran, $versi, $isi));
    }

    public function gantiIzin(Request $request, Peran $peran): JsonResponse
    {
        $isi = $request->validate([
            'izin' => ['present', 'array', 'max:5000'],
            'izin.*' => ['string', 'distinct', 'max:200'],
            'dasar_perubahan' => self::DASAR,
            'alasan' => ['nullable', 'string', 'max:500'],
        ]);
        $versi = PenjagaPenyunting::versiDiminta($request);

        $hasil = $this->kelola->gantiIzin($request->user(), $peran, $versi, array_values($isi['izin']), $isi['dasar_perubahan'], $isi['alasan'] ?? null);

        return $this->rinci($request, $hasil['peran'], tambahan: ['perubahan' => [
            'ditambah' => $hasil['ditambah'], 'dicabut' => $hasil['dicabut'], 'akun_terdampak' => $hasil['akun_terdampak'],
            'menunggu_persetujuan' => $this->persetujuan->daftar(collect($hasil['menunggu_persetujuan']), $request->user()),
        ]]);
    }

    /** @param  array<string, mixed>  $tambahan */
    private function rinci(Request $request, Peran $peran, int $status = 200, array $tambahan = []): JsonResponse
    {
        $peran->refresh();
        $respons = new JsonResponse(['data' => (new PeranRinciResource($peran))->resolve($request), ...$tambahan], $status);

        return $respons->setEtag(PeranRinciResource::versi($peran))->setPrivate();
    }
}
