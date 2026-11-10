<?php

namespace App\Modules\RawatInap\Application;

use App\Modules\MasterData\Domain\PemeriksaPenempatan;
use Illuminate\Support\Facades\DB;

/**
 * Pemeriksa penempatan nyata untuk penutupan TT (FR-08-17; tiket 2.29, menggantikan PenempatanBelumAda): terisi =
 * penempatan aktif di TT itu, dipesan = permintaan kamar berstatus `dialokasikan` ke TT itu. Penempatan belum punya
 * rencana selesai (rencana pulang 2.40), jadi keduanya dianggap berlangsung pada tanggal mana pun sesudah hari ini.
 */
final class PemeriksaPenempatanTempatTidur implements PemeriksaPenempatan
{
    public function terisiAtauDipesan(string $tempatTidurId, string $tanggal): bool
    {
        return DB::table('kunjungan.penempatan_tempat_tidur')->where('tempat_tidur_id', $tempatTidurId)->whereNull('selesai_pada')->exists()
            || DB::table('kunjungan.permintaan_tempat_tidur')->where('tempat_tidur_id', $tempatTidurId)->where('status', 'dialokasikan')->exists();
    }
}
