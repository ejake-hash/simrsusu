<?php

namespace App\Modules\Integrasi\Application;

use App\Core\ProfilRs\ProfilRs;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * GET /integrasi/sistem (kontrak v0.13.0): StatusSistemIntegrasi per sistem yang boleh dilihat penonton. Pemegang
 * pengaturan.integrasi.lihat melihat semua sistem beserta kredensial (tanpa nilai rahasia); pemantau per tujuan
 * (integrasi.satusehat/bpjs.lihat) hanya sistemnya, tanpa kredensial (FR-50-08).
 */
final class StatusSistem
{
    public function __construct(
        private readonly KelolaKredensial $kredensial,
        private readonly RingkasanPegawai $pegawai,
    ) {}

    /** @return list<SistemIntegrasi> */
    public static function bolehDilihat(): array
    {
        if (Gate::allows('pengaturan.integrasi.lihat', Cakupan::rs())) {
            return SistemIntegrasi::cases();
        }

        return array_values(array_filter(SistemIntegrasi::cases(), fn (SistemIntegrasi $s) => Gate::allows($s->subMenuPemantauan().'.lihat', Cakupan::rs())));
    }

    /**
     * @param  list<SistemIntegrasi>  $sistem
     * @return list<array<string, mixed>>
     */
    public function bentuk(array $sistem): array
    {
        $denganKredensial = Gate::allows('pengaturan.integrasi.lihat', Cakupan::rs());
        $status = DB::table('integrasi.status_sistem')->get()->keyBy('tujuan');
        $jumlah = DB::table('integrasi.kotak_keluar')->whereIn('status', ['menunggu', 'tertahan', 'gagal'])
            ->selectRaw('tujuan, status, count(*) AS n, min(dibuat_pada) AS tertua')->groupBy('tujuan', 'status')->get()
            ->groupBy('tujuan');
        $terkirim = DB::table('integrasi.kotak_keluar')->where('status', 'terkirim')->where('terkirim_pada', '>=', now()->subDay())
            ->selectRaw('tujuan, count(*) AS n')->groupBy('tujuan')->pluck('n', 'tujuan');
        $kodePpkKosong = trim((string) ProfilRs::satu()?->kode_ppk_bpjs) === '';

        $hasil = [];
        foreach ($sistem as $s) {
            $st = $status[$s->value] ?? null;
            $j = collect($jumlah[$s->value] ?? [])->keyBy('status');
            $aktif = $this->kredensial->lingkunganAktif($s);
            $peringatan = [];
            if ($aktif === null) {
                $peringatan[] = ['kode' => 'integrasi_nonaktif', 'pesan' => 'Belum ada lingkungan aktif; kiriman ditahan.'];
            } elseif ($this->kredensial->kurang($s, $aktif) !== []) {
                $peringatan[] = ['kode' => 'kredensial_belum_lengkap', 'pesan' => 'Kredensial lingkungan aktif belum lengkap.'];
            }
            if ($s->kodeBpjs() && $kodePpkKosong) {
                $peringatan[] = ['kode' => 'kode_ppk_kosong', 'pesan' => 'Kode PPK BPJS belum diisi di Profil RS; kiriman BPJS ditahan.'];
            }
            $uji = $st?->uji_koneksi_terakhir === null ? null : json_decode($st->uji_koneksi_terakhir, true);
            $hasil[] = [
                'kode' => $s->value, 'nama' => $s->nama(), 'lingkungan_aktif' => $aktif,
                'kredensial' => $denganKredensial ? $this->kredensial->ringkas($s, fn (array $id) => $this->pelaku($id)) : null,
                'peringatan' => $peringatan,
                'circuit_breaker' => [
                    'status' => $st->cb_status ?? 'tertutup', 'gagal_beruntun' => (int) ($st->gagal_beruntun ?? 0),
                    'sejak' => self::waktu($st->cb_sejak ?? null), 'coba_lagi_pada' => self::waktu($st->coba_lagi_pada ?? null),
                ],
                'queue' => [
                    'menunggu' => (int) ($j['menunggu']->n ?? 0), 'tertahan' => (int) ($j['tertahan']->n ?? 0), 'gagal' => (int) ($j['gagal']->n ?? 0),
                    'terkirim_24_jam' => (int) ($terkirim[$s->value] ?? 0), 'menunggu_tertua_pada' => self::waktu($j['menunggu']->tertua ?? null),
                ],
                'error_terakhir' => $st?->error_terakhir === null ? null : ['pesan' => $st->error_terakhir, 'pada' => self::waktu($st->error_terakhir_pada)],
                'uji_koneksi_terakhir' => $uji,
            ];
        }

        return $hasil;
    }

    /**
     * @param  list<string>  $id
     * @return array<string, array{id: string, nama: string}>
     */
    private function pelaku(array $id): array
    {
        $akun = Pengguna::query()->whereIn('id', $id)->get(['id', 'pegawai_id', 'nama_pengguna']);
        $nama = $this->pegawai->nama($akun->pluck('pegawai_id')->filter()->values()->all());
        $peta = [];
        foreach ($akun as $a) {
            $peta[$a->id] = ['id' => $a->id, 'nama' => $nama[$a->pegawai_id] ?? $a->nama_pengguna];
        }

        return $peta;
    }

    private static function waktu(mixed $nilai): ?string
    {
        return $nilai === null ? null : Carbon::parse($nilai)->toIso8601String();
    }
}
