<?php

namespace App\Modules\Ttd\Application;

use App\Modules\Akses\Models\Pengguna;
use Illuminate\Support\Facades\DB;

/**
 * Atasan langsung pemilik TTE (aturan rinci 1.6): akun aktif kepala unit pegawainya. Bila kepala itu pemilik sendiri,
 * akun yang dikecualikan (mis. pengaju), atau tanpa akun aktif, naik ke kepala unit induk, terus sampai unit akar.
 * Null bila pegawai tanpa unit atau tidak ada kepala yang memenuhi sampai unit akar (mis. Direktur).
 */
final class AtasanLangsung
{
    /** Batas pendakian, pengaman bila data induk unit berputar. */
    private const MAKS_TINGKAT = 20;

    /** @param  list<string>  $kecuali  id akun yang tidak boleh menjadi atasan */
    public function untuk(Pengguna $pemilik, array $kecuali = []): ?Pengguna
    {
        $kecuali[] = $pemilik->id;
        $unitId = DB::table('sdm.pegawai')->where('id', $pemilik->pegawai_id)->value('unit_organisasi_id');
        for ($i = 0; $unitId !== null && $i < self::MAKS_TINGKAT; $i++) {
            $unit = DB::table('inti.unit_organisasi')->where('id', $unitId)->first(['kepala_pegawai_id', 'induk_id']);
            if ($unit === null) {
                return null;
            }
            if ($unit->kepala_pegawai_id !== null && $unit->kepala_pegawai_id !== $pemilik->pegawai_id) {
                $akun = Pengguna::query()->where('pegawai_id', $unit->kepala_pegawai_id)->where('status', 'aktif')
                    ->whereNotIn('id', $kecuali)->first();
                if ($akun !== null) {
                    return $akun;
                }
            }
            $unitId = $unit->induk_id;
        }

        return null;
    }
}
