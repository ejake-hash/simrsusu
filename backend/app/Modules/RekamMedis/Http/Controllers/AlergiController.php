<?php

namespace App\Modules\RekamMedis\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\RekamMedis\Application\KelolaAlergi;
use App\Modules\RekamMedis\Models\Alergi;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Alergi pasien (tiket 2.13, kontrak v0.23.0). Lintas kunjungan, tidak berlabel (dibutuhkan semua pemberi obat), jadi
 * izin cukup di cakupan mana pun. Pembaca lain (farmasi) ditambah tiketnya (2.17).
 */
final class AlergiController
{
    private const LIHAT = ['rawat_jalan.asesmen_perawat.lihat', 'rawat_jalan.pemeriksaan.lihat'];

    public function __construct(private readonly KelolaAlergi $kelola, private readonly RingkasanPegawai $pegawai) {}

    public function index(Request $request, string $pasien): JsonResponse
    {
        if (! array_any(self::LIHAT, fn ($i) => Gate::allows($i))) {
            throw new ApiError(403, 'akses_ditolak', 'Anda tidak memiliki akses ke halaman ini.');
        }
        self::pastikanPasien($pasien);
        $filter = $request->validate(['status' => ['nullable', Rule::in(['aktif', 'batal'])]]);
        $query = Alergi::query()->where('pasien_id', $pasien)->where('status', $filter['status'] ?? 'aktif');

        return Paginasi::respons($query, $request, 'dibuat_pada', bentuk: fn ($baris) => $this->bentuk($baris))->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, string $pasien): JsonResponse
    {
        Gate::authorize('rawat_jalan.asesmen_perawat.input');
        self::pastikanPasien($pasien);
        $isi = $request->validate([
            'zat' => ['required', 'string', 'min:2', 'max:150'],
            'jenis' => ['required', Rule::in(Alergi::JENIS)],
            'barang_id' => ['nullable', 'uuid'],
            'reaksi' => ['nullable', 'string', 'max:500'],
            'tingkat' => ['nullable', Rule::in(['ringan', 'sedang', 'berat'])],
        ]);

        return new JsonResponse(['data' => $this->bentuk(collect([$this->kelola->catat($request->user(), $pasien, $isi)]))[0]], 201);
    }

    public function batal(Request $request, string $alergi): JsonResponse
    {
        Gate::authorize('rawat_jalan.asesmen_perawat.perbaiki');
        $isi = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:500']]);
        Alergi::query()->whereKey($alergi)->exists() || throw new ApiError(404, 'tidak_ditemukan', 'Catatan alergi tidak ditemukan.');

        return new JsonResponse(['data' => $this->bentuk(collect([$this->kelola->batal($request->user(), $alergi, $isi['alasan'])]))[0]]);
    }

    private static function pastikanPasien(string $id): void
    {
        DB::table('pasien.pasien')->where('id', $id)->exists() || throw new ApiError(404, 'tidak_ditemukan', 'Pasien tidak ditemukan.');
    }

    /**
     * @param  Collection<int, Alergi>  $baris
     * @return list<array<string, mixed>>
     */
    private function bentuk(Collection $baris): array
    {
        $barang = DB::table('logistik.barang')->whereIn('id', $baris->pluck('barang_id')->filter()->unique())->get(['id', 'kode', 'nama'])->keyBy('id');
        $akun = Pengguna::query()->whereIn('id', $baris->pluck('dibuat_oleh')->merge($baris->pluck('dibatalkan_oleh'))->filter()->unique())
            ->get(['id', 'pegawai_id', 'nama_pengguna']);
        $nama = $this->pegawai->nama($akun->pluck('pegawai_id')->filter());
        $pelaku = $akun->mapWithKeys(fn (Pengguna $a) => [$a->id => ['id' => $a->id, 'nama' => $nama[$a->pegawai_id] ?? $a->nama_pengguna]]);

        return $baris->map(fn (Alergi $a) => [
            'id' => $a->id, 'pasien_id' => $a->pasien_id, 'zat' => $a->zat, 'jenis' => $a->jenis,
            'barang' => $a->barang_id === null ? null : (array) $barang[$a->barang_id],
            'reaksi' => $a->reaksi, 'tingkat' => $a->tingkat, 'status' => $a->status,
            'dicatat_oleh' => $pelaku[$a->dibuat_oleh] ?? null, 'dicatat_pada' => $a->dibuat_pada->toIso8601String(),
            'alasan_batal' => $a->alasan_batal, 'dibatalkan_oleh' => $pelaku[$a->dibatalkan_oleh] ?? null,
            'dibatalkan_pada' => $a->dibatalkan_pada?->toIso8601String(),
        ])->values()->all();
    }
}
