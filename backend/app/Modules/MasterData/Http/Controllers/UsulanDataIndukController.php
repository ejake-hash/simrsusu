<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Http\EtagIsi;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\MasterData\Application\MesinUsulan;
use App\Modules\MasterData\Http\Resources\UsulanPresenter;
use App\Modules\MasterData\Models\UsulanDataInduk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** /api/v1/usulan-data-induk (kontrak v0.9.0, tiket 1.8c). Izin sub-menu master_data.usulan, cakupan rs. */
final class UsulanDataIndukController
{
    private const TAHAP = ['terbuka', 'diusulkan', 'diverifikasi', 'disetujui', 'berlaku', 'dikembalikan', 'gagal_diterapkan'];

    public function __construct(
        private readonly MesinUsulan $mesin,
        private readonly UsulanPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->izin('lihat');
        $filter = $request->validate([
            'tahap' => ['nullable', Rule::in(self::TAHAP)],
            'jenis_data' => ['nullable', Rule::in($this->mesin->jenisTerdaftar())],
            'perlu_saya' => ['nullable', Rule::in(['true', 'false', '1', '0'])],
        ]);
        /** @var Pengguna $saya */
        $saya = $request->user();
        $jenis = fn (Builder $q) => $q->when($filter['jenis_data'] ?? null, fn ($q, $j) => $q->where('jenis_data', $j));
        $query = UsulanDataInduk::query()->where($jenis)
            ->when($filter['tahap'] ?? null, fn ($q, $t) => $t === 'terbuka' ? $q->whereIn('tahap', UsulanDataInduk::TERBUKA) : $q->where('tahap', $t))
            ->when(in_array($filter['perlu_saya'] ?? null, ['true', '1'], true), fn ($q) => $this->perluSaya($q, $saya));

        $respons = Paginasi::respons($query, $request, 'dibuat_pada', bentuk: fn ($baris) => $this->presenter->daftar($baris, $saya));
        $jumlah = UsulanDataInduk::query()->where($jenis)->selectRaw('tahap, count(*) AS n')->groupBy('tahap')->pluck('n', 'tahap');
        $gagal = UsulanDataInduk::query()->where($jenis)->where('tahap', 'gagal_diterapkan')
            ->where('diubah_pada', '>=', now()->subDays(30)->format('Y-m-d H:i:sP'))->count();

        return EtagIsi::tambahMeta($respons, ['jumlah' => [
            'diusulkan' => (int) ($jumlah['diusulkan'] ?? 0), 'diverifikasi' => (int) ($jumlah['diverifikasi'] ?? 0),
            'disetujui' => (int) ($jumlah['disetujui'] ?? 0), 'dikembalikan' => (int) ($jumlah['dikembalikan'] ?? 0), 'gagal_diterapkan' => $gagal,
        ]]);
    }

    public function show(Request $request, UsulanDataInduk $usulan): JsonResponse
    {
        $this->izin('lihat');

        return $this->satu($usulan, $request);
    }

    public function store(Request $request): JsonResponse
    {
        $this->izin('input');
        $isi = $request->validate([
            'jenis_data' => ['required', Rule::in($this->mesin->jenisTerdaftar())],
            ...$this->aturanIsi(),
        ]);

        return $this->satu($this->mesin->ajukan($request->user(), $isi), $request, 201);
    }

    public function kirimUlang(Request $request, UsulanDataInduk $usulan): JsonResponse
    {
        $this->izin('input');
        $isi = $request->validate($this->aturanIsi());

        return $this->satu($this->mesin->kirimUlang($request->user(), $usulan, $isi), $request);
    }

    public function verifikasi(Request $request, UsulanDataInduk $usulan): JsonResponse
    {
        $isi = $request->validate(['catatan' => ['nullable', 'string', 'max:500']]);

        return $this->satu($this->mesin->verifikasi($request->user(), $usulan, $isi['catatan'] ?? null), $request);
    }

    public function setujui(Request $request, UsulanDataInduk $usulan): JsonResponse
    {
        $isi = $request->validate(['catatan' => ['nullable', 'string', 'max:500']]);

        return $this->satu($this->mesin->setujui($request->user(), $usulan, $isi['catatan'] ?? null), $request);
    }

    public function kembalikan(Request $request, UsulanDataInduk $usulan): JsonResponse
    {
        $isi = $request->validate(['catatan' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->satu($this->mesin->kembalikan($request->user(), $usulan, $isi['catatan']), $request);
    }

    /** @return array<string, list<mixed>> */
    private function aturanIsi(): array
    {
        return [
            'perubahan' => ['required', 'array', 'min:1', 'max:50'],
            'perubahan.*' => ['required', 'array:objek_jenis,aksi,objek_id,nilai_baru'],
            'perubahan.*.objek_jenis' => ['required', Rule::in($this->mesin->jenisObjek())],
            'perubahan.*.aksi' => ['required', Rule::in(['tambah', 'ubah', 'tutup'])],
            'perubahan.*.objek_id' => ['present', 'nullable', 'uuid'],
            'perubahan.*.nilai_baru' => ['present', 'array'],
            'berlaku_dari' => ['required', 'date_format:Y-m-d'],
            'alasan' => ['required', 'string', 'min:5', 'max:500'],
            'dasar' => IsianOrganisasi::DASAR,
        ];
    }

    /** Usulan yang bisa ditindaklanjuti akun ini sekarang (sama dengan MesinUsulan::dapat, dalam SQL). */
    private function perluSaya(Builder $q, Pengguna $saya): void
    {
        $peran = PenggunaPeran::query()->berlaku()->where('pengguna_id', $saya->id)->pluck('peran_id')->all();
        $alur = DB::table('inti.alur_usulan')->get()->keyBy('jenis_data');
        $sesuaiPeran = fn (Builder $q, string $kolom) => $q->where(fn ($q) => $q->whereIn('jenis_data', $alur->filter(fn ($a) => $a->$kolom === null)->keys())
            ->orWhereIn('jenis_data', $alur->filter(fn ($a) => in_array($a->$kolom, $peran, true))->keys()));
        $boleh = fn (string $aksi) => Gate::allows("master_data.usulan.$aksi", Cakupan::rs());

        $q->where(function ($q) use ($saya, $boleh, $sesuaiPeran) {
            $q->whereRaw('false');
            if ($boleh('verifikasi')) {
                $q->orWhere(fn ($q) => $sesuaiPeran($q->where('tahap', 'diusulkan')->where('diusulkan_oleh', '!=', $saya->id), 'peran_pemverifikasi_id'));
            }
            if ($boleh('setujui')) {
                $q->orWhere(fn ($q) => $sesuaiPeran($q->where('tahap', 'diverifikasi')->where('diusulkan_oleh', '!=', $saya->id)
                    ->where('diverifikasi_oleh', '!=', $saya->id), 'peran_penyetuju_id'));
            }
            if ($boleh('input')) {
                $q->orWhere(fn ($q) => $q->where('tahap', 'dikembalikan')->where('diusulkan_oleh', $saya->id));
            }
        });
    }

    private function izin(string $aksi): void
    {
        abort_unless(Gate::allows("master_data.usulan.$aksi", Cakupan::rs()), 403);
    }

    private function satu(UsulanDataInduk $u, Request $request, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->satu($u->refresh(), $request->user())], $status);
    }
}
