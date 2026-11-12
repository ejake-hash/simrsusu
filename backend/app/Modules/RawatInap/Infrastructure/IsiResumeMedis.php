<?php

namespace App\Modules\RawatInap\Infrastructure;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\RawatInap\Application\KelolaRencanaPulang;
use App\Modules\RawatInap\Application\KelolaResumeMedis;
use App\Modules\Ttd\Application\PenyediaIsiTtd;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Isi kanonik resume medis (tiket 2.40): id + kunjungan + pasien + ringkasan (potret sumber dan isian DPJP), hanya di-hash.
 * Ditandatangani DPJP sekali, di dalam transaksi pengesahan (KelolaResumeMedis) sesudah status menjadi sah; bukan lewat
 * POST /ttd/tandatangani.
 */
final class IsiResumeMedis implements PenyediaIsiTtd
{
    public function jenis(): string
    {
        return 'resume_medis';
    }

    public function isi(string $id): ?array
    {
        $r = Str::isUuid($id) ? DB::table('klinis.resume_medis')->where('id', $id)->first(['id', 'kunjungan_id', 'pasien_id', 'ringkasan']) : null;

        return $r === null ? null : ['id' => (string) $r->id, 'kunjungan_id' => (string) $r->kunjungan_id, 'pasien_id' => (string) $r->pasien_id,
            'ringkasan' => json_decode((string) $r->ringkasan, true)];
    }

    public function alasanTidakBoleh(Pengguna $penandaTangan, string $id): ?array
    {
        $r = Str::isUuid($id) ? DB::table('klinis.resume_medis')->where('id', $id)->first(['id', 'kunjungan_id', 'status', 'tanda_tangan_id']) : null;
        if ($r === null || ! $this->terlihat($penandaTangan, $id)) {
            return ['tidak_ditemukan', 'Resume medis tidak ditemukan.'];
        }
        if (! KelolaResumeMedis::dpjp($penandaTangan, (string) $r->kunjungan_id)) {
            return ['bukan_pemutus', 'Resume medis hanya bisa ditandatangani DPJP pasien ini.'];
        }

        return $r->status === 'sah' && $r->tanda_tangan_id === null ? null : ['belum_diputuskan', 'Resume medis disahkan dari layar pemulangan.'];
    }

    /** Pemegang lihat atau sahkan pemulangan di cakupan yang memuat unit bangsal kunjungannya. */
    public function terlihat(Pengguna $pengguna, string $id): bool
    {
        $unit = Str::isUuid($id) ? DB::table('klinis.resume_medis as r')->join('kunjungan.kunjungan as k', 'k.id', '=', 'r.kunjungan_id')
            ->where('r.id', $id)->first(['k.unit_id']) : null;
        if ($unit === null) {
            return false;
        }
        $cakupan = KelolaRencanaPulang::cakupan($unit->unit_id === null ? null : (string) $unit->unit_id);
        $gate = Gate::forUser($pengguna);

        return $gate->allows(KelolaRencanaPulang::IZIN_LIHAT, $cakupan) || $gate->allows(KelolaResumeMedis::IZIN_SAHKAN, $cakupan);
    }
}
