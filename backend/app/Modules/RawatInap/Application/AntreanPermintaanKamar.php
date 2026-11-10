<?php

namespace App\Modules\RawatInap\Application;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Antrean permintaan kamar (tiket 2.28, kontrak v0.36.0; FR-08-03): satu antrean dari IGD dan poli, hanya permintaan
 * aktif (`menunggu`, `dialokasikan`), paling lama menunggu di atas. Lama menunggu dihitung server dari `menunggu_sejak`
 * (jam SPRI, atau awal tanggal rencana bila rawat direncanakan nanti) dengan jam aplikasi, dibulatkan ke atas seperti
 * papan IGD. Data pasien = dua penanda identitas untuk
 * pemegang izin admisi; tanpa alasan klinis SPRI.
 */
final class AntreanPermintaanKamar
{
    public function __construct(private readonly RingkasanPegawai $pegawai) {}

    /** Kueri antrean; kolom kursor `menunggu_sejak` dan `id` tidak di-alias (Paginasi membaca nama kolom aslinya). */
    public function kueri(?string $status = null, ?string $id = null): Builder
    {
        return DB::table('kunjungan.permintaan_tempat_tidur as ptt')
            ->join('kunjungan.kunjungan as k', 'k.id', '=', 'ptt.kunjungan_id')
            ->join('pasien.pasien as p', 'p.id', '=', 'k.pasien_id')
            ->join('kunjungan.surat_kontrol as sk', 'sk.id', '=', 'ptt.surat_kontrol_id')
            ->leftJoin('inti.kelas_rawat as kr', 'kr.id', '=', 'ptt.kelas_diminta_id')
            ->leftJoin('inti.bangsal as bp', 'bp.id', '=', 'ptt.bangsal_pilihan_id')
            ->leftJoin('inti.tempat_tidur as tt', 'tt.id', '=', 'ptt.tempat_tidur_id')
            ->leftJoin('inti.kamar as km', 'km.id', '=', 'tt.kamar_id')
            ->leftJoin('inti.bangsal as bt', 'bt.id', '=', 'km.bangsal_id')
            ->when($id !== null, fn (Builder $q) => $q->where('ptt.id', $id), fn (Builder $q) => $q->whereIn('ptt.status', $status === null ? PermintaanKamar::AKTIF : [$status]))
            ->select([
                'ptt.id', 'ptt.menunggu_sejak', 'ptt.diminta_pada', 'ptt.asal', 'ptt.status', 'ptt.diminta_oleh', 'ptt.dialokasikan_pada',
                'k.id as kunjungan_id', 'k.nomor as kunjungan_nomor', 'k.jenis_kunjungan',
                'p.id as pasien_id', 'p.no_rm', 'p.nama', 'p.jenis_kelamin', 'p.tanggal_lahir',
                'sk.id as spri_id', 'sk.tanggal_rencana', 'sk.no_surat_bpjs',
                'kr.id as kelas_id', 'kr.kode as kelas_kode', 'kr.nama as kelas_nama',
                'bp.id as bangsal_pilihan_id', 'bp.kode as bangsal_pilihan_kode', 'bp.nama as bangsal_pilihan_nama',
                'tt.id as tt_id', 'tt.kode as tt_kode', 'bt.id as tt_bangsal_id', 'bt.kode as tt_bangsal_kode', 'bt.nama as tt_bangsal_nama',
            ]);
    }

    /** @return array<string, mixed>|null */
    public function satu(string $id): ?array
    {
        return $this->bentuk($this->kueri(id: $id)->get())[0] ?? null;
    }

    /**
     * @param  Collection<int, mixed>  $baris
     * @return list<array<string, mixed>>
     */
    public function bentuk(Collection $baris): array
    {
        if ($baris->isEmpty()) {
            return [];
        }
        $akun = Pengguna::query()->whereIn('id', $baris->pluck('diminta_oleh')->unique()->values())->get(['id', 'pegawai_id', 'nama_pengguna']);
        $namaPegawai = $this->pegawai->nama($akun->pluck('pegawai_id')->filter());
        $pelaku = $akun->mapWithKeys(fn (Pengguna $a) => [$a->id => ['id' => $a->id, 'nama' => $namaPegawai[$a->pegawai_id] ?? $a->nama_pengguna]]);
        $sekarang = now();
        $ringkas = fn (mixed $id, mixed $kode, mixed $nama) => $id === null ? null : ['id' => (string) $id, 'kode' => (string) $kode, 'nama' => (string) $nama];

        return $baris->map(function ($b) use ($pelaku, $sekarang, $ringkas) {
            $diminta = Carbon::parse((string) $b->diminta_pada);
            $sejak = Carbon::parse((string) $b->menunggu_sejak);

            return [
                'id' => (string) $b->id,
                'kunjungan' => ['id' => (string) $b->kunjungan_id, 'nomor' => (string) $b->kunjungan_nomor, 'jenis_kunjungan' => (string) $b->jenis_kunjungan],
                'pasien' => ['id' => (string) $b->pasien_id, 'no_rm' => (string) $b->no_rm, 'nama' => (string) $b->nama, 'jenis_kelamin' => $b->jenis_kelamin,
                    'tanggal_lahir' => $b->tanggal_lahir === null ? null : substr((string) $b->tanggal_lahir, 0, 10)],
                'asal' => (string) $b->asal,
                'status' => (string) $b->status,
                'kelas_diminta' => $ringkas($b->kelas_id, $b->kelas_kode, $b->kelas_nama),
                'bangsal_pilihan' => $ringkas($b->bangsal_pilihan_id, $b->bangsal_pilihan_kode, $b->bangsal_pilihan_nama),
                'spri' => ['id' => (string) $b->spri_id, 'tanggal_rencana' => substr((string) $b->tanggal_rencana, 0, 10), 'no_surat_bpjs' => $b->no_surat_bpjs],
                'diminta_oleh' => $pelaku[$b->diminta_oleh] ?? null,
                'diminta_pada' => $diminta->toIso8601String(),
                'menunggu_sejak' => $sejak->toIso8601String(),
                // Menit dibulatkan ke atas (pola papan IGD); tidak negatif (rencana rawat yang belum tiba = 0).
                'lama_menunggu_menit' => (int) ceil(max(0, $sekarang->getTimestampMs() - $sejak->getTimestampMs()) / 60000),
                'tempat_tidur' => $b->tt_id === null ? null : ['id' => (string) $b->tt_id, 'kode' => (string) $b->tt_kode,
                    'bangsal' => $ringkas($b->tt_bangsal_id, $b->tt_bangsal_kode, $b->tt_bangsal_nama)],
                'dialokasikan_pada' => $b->dialokasikan_pada === null ? null : Carbon::parse((string) $b->dialokasikan_pada)->toIso8601String(),
            ];
        })->values()->all();
    }
}
