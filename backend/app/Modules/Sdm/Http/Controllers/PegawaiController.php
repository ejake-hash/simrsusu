<?php

namespace App\Modules\Sdm\Http\Controllers;

use App\Core\Database\Like;
use App\Core\Http\IfMatch;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Sdm\Application\KelolaPegawai;
use App\Modules\Sdm\Http\Resources\PegawaiResource;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** /api/v1/pegawai (kontrak v0.6.0, tiket 1.8d). Izin sdm.pegawai.* cakupan rs; NIK lewat izin bagian identitas. */
final class PegawaiController
{
    private const DASAR = ['required', 'string', 'min:3', 'max:200'];

    public function __construct(private readonly PegawaiResource $resource) {}

    public function index(Request $request): JsonResponse
    {
        self::izin('sdm.pegawai.lihat');
        $filter = $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['aktif', 'akan_keluar', 'keluar'])],
            'profesi' => ['nullable', Rule::in(Pegawai::PROFESI)],
            'unit_organisasi_id' => ['nullable', 'uuid'],
            'punya_akun' => ['nullable', Rule::in(['true', 'false', '1', '0'])],
        ]);
        $hariIni = now(config('instalasi.zona_waktu'))->toDateString();
        $akun = DB::table('akses.pengguna')->whereColumn('akses.pengguna.pegawai_id', 'sdm.pegawai.id');
        $query = Pegawai::query()
            ->when($filter['cari'] ?? null, fn ($q, $cari) => $q->where(fn ($q) => $q
                ->whereRaw('nama ILIKE ?', [Like::memuat($cari)])->orWhereRaw('nip ILIKE ?', [Like::memuat($cari)])))
            ->when($filter['profesi'] ?? null, fn ($q, $profesi) => $q->where('profesi', $profesi))
            ->when($filter['unit_organisasi_id'] ?? null, fn ($q, $unit) => $q->where('unit_organisasi_id', $unit))
            ->when($filter['status'] ?? null, fn ($q, $status) => match ($status) {
                'aktif' => $q->whereNull('tanggal_keluar'),
                'akan_keluar' => $q->where('tanggal_keluar', '>', $hariIni),
                default => $q->where('tanggal_keluar', '<=', $hariIni),
            })
            ->when(isset($filter['punya_akun']), fn ($q) => in_array($filter['punya_akun'], ['true', '1'], true)
                ? $q->whereExists($akun)
                : $q->whereNotExists($akun)->where(fn ($q) => $q->whereNull('tanggal_keluar')->orWhere('tanggal_keluar', '>', $hariIni)));

        return Paginasi::respons($query, $request, 'nama', arah: 'asc', bentuk: fn ($baris) => $this->resource->ringkas($baris, $request->user()));
    }

    public function show(Request $request, Pegawai $pegawai): JsonResponse
    {
        self::izin('sdm.pegawai.lihat');
        $request->validate(['tampilkan_nik' => ['nullable', Rule::in(['true', 'false', '1', '0'])]]);
        $tampilkan = in_array($request->query('tampilkan_nik'), ['true', '1'], true);
        if ($tampilkan) {
            self::izin(PegawaiResource::IZIN_NIK);
        }

        return $this->satu($request, $pegawai, tampilkanNik: $tampilkan);
    }

    public function store(Request $request, KelolaPegawai $kelola): JsonResponse
    {
        self::izin('sdm.pegawai.input');
        $isi = $request->validate([...self::aturan(false), 'nama' => ['required', 'string', 'min:2', 'max:150'], 'dasar_perubahan' => self::DASAR]);

        return $this->satu($request, $kelola->buat($request->user(), $isi, PegawaiResource::bolehNik($request->user())), 201);
    }

    public function update(Request $request, Pegawai $pegawai, KelolaPegawai $kelola): JsonResponse
    {
        self::izin('sdm.pegawai.perbaiki');
        $isi = $request->validate([...self::aturan(true), 'dasar_perubahan' => self::DASAR]);
        if (count($isi) < 2) {
            throw ValidationException::withMessages(['dasar_perubahan' => 'Isi minimal satu perubahan.']);
        }

        return $this->satu($request, $kelola->ubah($request->user(), $pegawai, IfMatch::versi($request), $isi, PegawaiResource::bolehNik($request->user())));
    }

    /** @return array<string, list<mixed>> */
    private static function aturan(bool $ubah): array
    {
        $opsional = $ubah ? ['sometimes', 'nullable'] : ['nullable'];
        $aturan = [
            'nip' => [...$opsional, 'string', 'max:30'],
            'nik' => [...$opsional, 'string', 'regex:/^[0-9]{16}$/'],
            'jenis_kepegawaian' => [...$opsional, 'string', 'max:50'],
            'profesi' => [...$opsional, Rule::in(Pegawai::PROFESI)],
            'unit_organisasi_id' => [...$opsional, 'uuid'],
            'jabatan' => [...$opsional, 'string', 'max:150'],
            'tmt' => [...$opsional, 'date_format:Y-m-d'],
        ];
        if ($ubah) {
            $aturan['nama'] = ['sometimes', 'string', 'min:2', 'max:150'];
            $aturan['tanggal_keluar'] = ['sometimes', 'nullable', 'date_format:Y-m-d'];
            $aturan['alasan_keluar'] = ['sometimes', 'nullable', 'string', 'min:5', 'max:500'];
        }

        return $aturan;
    }

    private static function izin(string $kode): void
    {
        abort_unless(Gate::allows($kode, Cakupan::rs()), 403);
    }

    private function satu(Request $request, Pegawai $pegawai, int $status = 200, bool $tampilkanNik = false): JsonResponse
    {
        $pegawai->refresh();
        $respons = new JsonResponse(['data' => $this->resource->rinci($pegawai, $request->user(), $tampilkanNik)], $status);

        return $respons->setEtag((string) $pegawai->revisi)->setPrivate()->header('Cache-Control', 'private, no-store');
    }
}
