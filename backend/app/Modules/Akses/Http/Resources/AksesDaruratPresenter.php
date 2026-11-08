<?php

namespace App\Modules\Akses\Http\Resources;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\AksesDarurat;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Pendaftaran\Models\Pasien;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Bentuk AksesDarurat (kontrak v0.29.0); pasien, akun, dan jumlah_akses dimuat sekaligus per halaman. */
final class AksesDaruratPresenter
{
    public function __construct(private readonly RingkasanPegawai $pegawai) {}

    /** @return array<string, mixed> */
    public function satu(AksesDarurat $a, Pengguna $saya): array
    {
        return $this->daftar(collect([$a]), $saya)[0];
    }

    /**
     * @param  Collection<int, AksesDarurat>  $baris
     * @return list<array<string, mixed>>
     */
    public function daftar(Collection $baris, Pengguna $saya): array
    {
        if ($baris->isEmpty()) {
            return [];
        }
        $pasien = Pasien::query()->whereIn('id', $baris->pluck('pasien_id')->unique()->values())
            ->get(['id', 'no_rm', 'nama', 'jenis_kelamin', 'tanggal_lahir'])->keyBy('id');
        $akun = Pengguna::query()->whereIn('id', $baris->flatMap(fn (AksesDarurat $a) => [$a->pengguna_id, $a->ditinjau_oleh])->filter()->unique()->values())
            ->get(['id', 'pegawai_id', 'nama_pengguna'])->keyBy('id');
        $nama = $this->pegawai->nama($akun->pluck('pegawai_id')->filter()->values());
        $pelaku = fn (?string $id) => ($a = $akun->get((string) $id)) === null ? null : ['id' => $a->id, 'nama' => $nama[$a->pegawai_id] ?? $a->nama_pengguna];
        // Satu query agregat; rentang terjadi_pada menyaring partisi log_akses.
        $jumlah = DB::table('audit.log_akses')->whereIn('akses_darurat_id', $baris->pluck('id'))
            ->where('terjadi_pada', '>=', $baris->min('mulai_pada'))->where('terjadi_pada', '<=', $baris->max('berakhir_pada'))
            ->groupBy('akses_darurat_id')->selectRaw('akses_darurat_id, count(*) AS n')->pluck('n', 'akses_darurat_id');
        $bolehTinjau = Gate::forUser($saya)->allows('rekam_medis.akses_darurat.tinjau', Cakupan::rs());

        return $baris->map(function (AksesDarurat $a) use ($pasien, $pelaku, $jumlah, $bolehTinjau, $saya) {
            $p = $pasien[$a->pasien_id];

            return [
                'id' => $a->id,
                'pasien' => ['id' => $p->id, 'no_rm' => $p->no_rm, 'nama' => $p->nama, 'jenis_kelamin' => $p->jenis_kelamin,
                    'tanggal_lahir' => $p->tanggal_lahir?->toDateString()],
                'pengguna' => $pelaku($a->pengguna_id),
                'kategori_alasan' => $a->kategori_alasan,
                'alasan' => $a->alasan,
                'mulai_pada' => $a->mulai_pada->toIso8601String(),
                'berakhir_pada' => $a->berakhir_pada->toIso8601String(),
                'aktif' => $a->aktif(),
                'jumlah_akses' => (int) ($jumlah[$a->id] ?? 0),
                'tinjauan' => $a->hasil_tinjauan === null ? null : [
                    'hasil' => $a->hasil_tinjauan, 'catatan' => $a->catatan_tinjauan, 'oleh' => $pelaku($a->ditinjau_oleh),
                    'pada' => $a->ditinjau_pada?->toIso8601String(),
                ],
                'dapat' => ['tinjau' => $bolehTinjau && $a->hasil_tinjauan === null && $a->pengguna_id !== $saya->id],
            ];
        })->values()->all();
    }
}
