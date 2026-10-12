<?php

namespace App\Modules\Sdm\Application;

use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Support\Facades\DB;

/**
 * Data pegawai yang dibaca modul Integrasi untuk IHS Practitioner SATUSEHAT (tiket 1.9, FR-39-07, FR-49-06). PRD 10.3:
 * lewat antarmuka modul ini, bukan membaca `sdm.pegawai` langsung. NIK hanya keluar dari sini untuk dikirim ke SATUSEHAT.
 */
final class PegawaiIntegrasi
{
    /** Profesi tenaga kesehatan yang punya IHS Practitioner (sdm.pegawai.profesi). */
    public const PROFESI_NAKES = ['dokter', 'dokter_gigi', 'perawat', 'bidan', 'apoteker', 'tenaga_kesehatan_lain'];

    /**
     * Pegawai aktif berprofesi tenaga kesehatan (ratusan sampai beberapa ribu baris). `sidik` berubah bila NIK berubah
     * (dari sidik NIK yang sudah ada, tanpa membuka NIK).
     *
     * @return list<array{id: string, sidik: string}>
     */
    public function nakes(): array
    {
        return DB::table('sdm.pegawai')->where('status', 'aktif')->whereIn('profesi', self::PROFESI_NAKES)->orderBy('id')
            ->get(['id', 'nik_sidik'])->map(fn (object $p) => ['id' => $p->id, 'sidik' => md5((string) $p->nik_sidik)])->all();
    }

    /** @return array{nik: string|null, unit_organisasi_id: string|null, sidik: string}|null */
    public function untukIhs(string $pegawaiId): ?array
    {
        $pegawai = Pegawai::query()->find($pegawaiId);

        return $pegawai === null ? null
            : ['nik' => $pegawai->nik(), 'unit_organisasi_id' => $pegawai->unit_organisasi_id, 'sidik' => md5((string) $pegawai->nik_sidik)];
    }

    /** IHS produksi ke kolom tampilan; bukan suntingan pegawai (tanpa revisi/diubah_pada, agar tidak memicu sinkron ulang). */
    public function simpanIhs(string $pegawaiId, string $ihs): void
    {
        DB::table('sdm.pegawai')->where('id', $pegawaiId)->update(['ihs_practitioner_id' => $ihs]);
    }
}
