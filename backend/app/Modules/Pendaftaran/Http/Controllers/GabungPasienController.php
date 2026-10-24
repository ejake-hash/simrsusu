<?php

namespace App\Modules\Pendaftaran\Http\Controllers;

use App\Core\Http\Paginasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Pendaftaran\Application\GabungPasien;
use App\Modules\Pendaftaran\Models\UsulanGabung;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * /api/v1/gabung-pasien (kontrak v0.21.0, tiket 2.9). Izin pendaftaran.master_pasien.lihat|usul|verifikasi, cakupan rs.
 * Nama pasien hanya dikirim sebagai ringkasan usulan (No. RM + nama), tidak pernah di URL atau audit.
 */
final class GabungPasienController
{
    public function index(Request $request): JsonResponse
    {
        self::izin('pendaftaran.master_pasien.lihat');
        $request->validate(['tahap' => ['nullable', Rule::in(['diusulkan', 'digabung', 'ditolak'])]]);
        $query = UsulanGabung::query()->when($request->query('tahap'), fn ($q, $tahap) => $q->where('tahap', $tahap));
        $pengguna = $request->user();

        return Paginasi::respons($query, $request, 'dibuat_pada', bentuk: fn (Collection $baris) => $this->bentuk($baris, $pengguna))
            ->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, GabungPasien $gabung): JsonResponse
    {
        self::izin('pendaftaran.master_pasien.usul');
        $isi = $request->validate([
            'pasien_sumber_id' => ['required', 'uuid'], 'pasien_tujuan_id' => ['required', 'uuid'],
            'alasan' => ['required', 'string', 'min:5', 'max:300'],
        ]);
        $u = $gabung->usulkan($request->user(), $isi['pasien_sumber_id'], $isi['pasien_tujuan_id'], $isi['alasan']);

        return $this->satu($u, $request->user(), 201);
    }

    public function verifikasi(Request $request, string $usulan, GabungPasien $gabung): JsonResponse
    {
        self::izin('pendaftaran.master_pasien.verifikasi');
        $isi = $request->validate(['keputusan' => ['required', Rule::in(['setuju', 'tolak'])], 'catatan' => ['nullable', 'string', 'min:5', 'max:300', 'required_if:keputusan,tolak']]);
        $u = $gabung->verifikasi($request->user(), $usulan, $isi['keputusan'] === 'setuju', $isi['catatan'] ?? null);

        return $this->satu($u, $request->user());
    }

    private function satu(UsulanGabung $u, Pengguna $pengguna, int $status = 200): JsonResponse
    {
        return (new JsonResponse(['data' => $this->bentuk(collect([$u->refresh()]), $pengguna)[0]], $status))->header('Cache-Control', 'no-store');
    }

    /**
     * @param  Collection<int, UsulanGabung>  $baris
     * @return list<array<string, mixed>>
     */
    private function bentuk(Collection $baris, Pengguna $penonton): array
    {
        $pasien = DB::table('pasien.pasien')->whereIn('id', $baris->pluck('sumber_id')->merge($baris->pluck('tujuan_id'))->unique()->values())
            ->get(['id', 'no_rm', 'nama', 'tanggal_lahir'])->keyBy('id');
        $akun = DB::table('akses.pengguna')->whereIn('id', $baris->pluck('diusulkan_oleh')->merge($baris->pluck('diverifikasi_oleh'))->filter()->unique()->values())
            ->pluck('nama_pengguna', 'id');
        $boleh = Gate::forUser($penonton)->allows('pendaftaran.master_pasien.verifikasi', Cakupan::rs());
        $ringkas = fn (string $id) => ['id' => $id, 'no_rm' => (string) $pasien->get($id)?->no_rm, 'nama' => (string) $pasien->get($id)?->nama,
            'tanggal_lahir' => $pasien->get($id)?->tanggal_lahir];

        return $baris->map(fn (UsulanGabung $u) => [
            'id' => $u->id, 'tahap' => $u->tahap, 'sumber' => $ringkas($u->sumber_id), 'tujuan' => $ringkas($u->tujuan_id), 'alasan' => $u->alasan,
            'diusulkan_oleh' => ['id' => $u->diusulkan_oleh, 'nama_pengguna' => $akun->get($u->diusulkan_oleh)],
            'diusulkan_pada' => $u->diusulkan_pada->toIso8601String(),
            'diverifikasi_oleh' => $u->diverifikasi_oleh === null ? null : ['id' => $u->diverifikasi_oleh, 'nama_pengguna' => $akun->get($u->diverifikasi_oleh)],
            'diverifikasi_pada' => $u->diverifikasi_pada?->toIso8601String(),
            'catatan_verifikasi' => $u->catatan_verifikasi,
            'jumlah_dipindah' => $u->rincian_pindah === null ? null : array_map('count', $u->rincian_pindah),
            'dapat_verifikasi' => $boleh && $u->tahap === 'diusulkan' && $u->diusulkan_oleh !== $penonton->id,
            'dibuat_pada' => $u->dibuat_pada->toIso8601String(),
        ])->values()->all();
    }

    private static function izin(string $kode): void
    {
        abort_unless(Gate::allows($kode, Cakupan::rs()), 403);
    }
}
