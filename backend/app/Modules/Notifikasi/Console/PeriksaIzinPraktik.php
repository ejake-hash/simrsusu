<?php

namespace App\Modules\Notifikasi\Console;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\Notifikasi\Application\KotakKerja;
use App\Modules\Notifikasi\Models\JenisTugas;
use App\Modules\Sdm\Models\IzinPraktik;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tugas harian (tiket 1.7, FR-53-05 "SIP berakhir 30 hari"): izin praktik aktif yang berakhir dalam batas jenis
 * IZIN_PRAKTIK_BERAKHIR → tugas untuk akun pegawai itu (tenggat = tanggal berakhir). Izin yang sudah punya pengganti
 * jenis sama dengan masa berlaku lebih panjang dilewati. Aman diulang (tugas terbuka tidak digandakan).
 */
final class PeriksaIzinPraktik extends Command
{
    protected $signature = 'notifikasi:periksa-izin-praktik';

    protected $description = 'Membuat tugas untuk izin praktik yang akan berakhir';

    public function handle(KotakKerja $kotakKerja): int
    {
        $jenis = JenisTugas::query()->where('kode', 'IZIN_PRAKTIK_BERAKHIR')->where('aktif', true)->first();
        if ($jenis === null) {
            return self::SUCCESS;
        }
        $zona = (string) config('instalasi.zona_waktu');
        $batas = now($zona)->addMinutes($jenis->batas_menit)->toDateString();
        $dibuat = 0;
        foreach (IzinPraktik::query()->where('status', 'aktif')->whereNotNull('berlaku_sampai')->where('berlaku_sampai', '<=', $batas)
            ->where('berlaku_sampai', '>=', now($zona)->toDateString())->get() as $izin) {
            $pengganti = IzinPraktik::query()->where('pegawai_id', $izin->pegawai_id)->where('jenis', $izin->jenis)->where('status', 'aktif')
                ->where(fn ($q) => $q->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>', $izin->berlaku_sampai?->toDateString()))->exists();
            $akun = Pengguna::query()->where('pegawai_id', $izin->pegawai_id)->where('status', 'aktif')->value('id');
            if ($pengganti || $akun === null) {
                continue;
            }
            $pegawai = Pegawai::query()->find($izin->pegawai_id);
            DB::transaction(function () use ($kotakKerja, $izin, $akun, $pegawai, $zona, &$dibuat) {
                $tugas = $kotakKerja->buat('IZIN_PRAKTIK_BERAKHIR', $izin->id, strtoupper($izin->jenis).' akan berakhir '.$izin->berlaku_sampai?->format('d-m-Y'),
                    ['pengguna', (string) $akun], $pegawai?->unit_organisasi_id, ['pegawai_id' => $izin->pegawai_id],
                    Carbon::parse((string) $izin->berlaku_sampai?->toDateString(), $zona)->endOfDay());
                $dibuat += $tugas !== null && $tugas->wasRecentlyCreated ? 1 : 0;
            });
        }
        $this->info('Tugas izin praktik dibuat: '.$dibuat);

        return self::SUCCESS;
    }
}
