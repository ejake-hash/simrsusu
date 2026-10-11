<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Core\Audit\CatatAktivitas;
use App\Core\Database\Like;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Application\MenuSaya;
use App\Modules\Akses\Application\PohonMenu;
use App\Modules\Akses\Http\Resources\AkunPresenter;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaIzinKhusus;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Pengaturan → Akun & akses: daftar, rincian, dan Lihat sebagai (kontrak /akun, /akun/{id}, /akun/{id}/menu). */
final class AkunController
{
    private const SARAN_MINIMAL_AKUN = 5;

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
                ->whereRaw('nama_pengguna ILIKE ?', [Like::memuat($cari)])
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

    /**
     * Saran jadikan peran (FR-09-15): pengecualian berlaku yang sama dipakai >= 5 akun, terbanyak dulu, paling banyak 50.
     */
    public function saranPeran(): JsonResponse
    {
        $baris = PenggunaIzinKhusus::query()->berlaku()
            ->selectRaw('efek, menu_id, izin_id, count(DISTINCT pengguna_id) AS jumlah_akun')
            ->groupBy('efek', 'menu_id', 'izin_id')->havingRaw('count(DISTINCT pengguna_id) >= ?', [self::SARAN_MINIMAL_AKUN])
            ->orderByDesc('jumlah_akun')->orderBy('efek')->orderBy('menu_id')->orderBy('izin_id')->limit(50)->toBase()->get();
        $izin = Izin::query()->whereIn('id', $baris->pluck('izin_id')->filter()->unique()->values())->get(['id', 'kode', 'menu_id', 'jenis_aksi'])->keyBy('id');
        $menu = Menu::query()->get(['id', 'induk_id', 'tingkat', 'kode', 'label'])->keyBy('id');

        return new JsonResponse(['data' => $baris->map(function (object $b) use ($izin, $menu) {
            $i = $b->izin_id === null ? null : $izin->get($b->izin_id);
            [$jenis, $kode, $label] = $i !== null
                ? ['izin', $i->kode, PohonMenu::jalurLabel($menu, $i->menu_id).' → '.PohonMenu::labelAksi($i->jenis_aksi)]
                : ['menu', (string) $menu->get((string) $b->menu_id)?->kode, PohonMenu::jalurLabel($menu, (string) $b->menu_id)];

            return ['efek' => $b->efek, 'sasaran_jenis' => $jenis, 'sasaran_kode' => $kode, 'sasaran_label' => $label, 'jumlah_akun' => (int) $b->jumlah_akun];
        })->values()->all()]);
    }
}
