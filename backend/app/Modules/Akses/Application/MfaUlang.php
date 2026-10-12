<?php

namespace App\Modules\Akses\Application;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

/**
 * Waktu verifikasi MFA terakhir di sesi ini (K11, PRD 10.3.2 langkah 3; tiket 1.5). Diisi saat masuk lewat MFA dan
 * saat verifikasi ulang. Izin `butuh_mfa` hanya lolos selama verifikasi itu belum lebih dari
 * `autentikasi.mfa.ulang_menit` menit. Disimpan di sesi, bukan di akun: verifikasi di peramban lain tidak berlaku di sini.
 */
final class MfaUlang
{
    private const KUNCI = 'autentikasi.mfa_terverifikasi_pada';

    /** @return CarbonImmutable waktu verifikasi yang dicatat */
    public static function catat(Session $sesi): CarbonImmutable
    {
        $sekarang = CarbonImmutable::now();
        $sesi->put(self::KUNCI, $sekarang->getTimestamp());

        return $sekarang;
    }

    public static function berlakuSampai(CarbonImmutable $terverifikasi): CarbonImmutable
    {
        return $terverifikasi->addMinutes((int) config('autentikasi.mfa.ulang_menit'));
    }

    /** Tanpa sesi (konsol, token mesin) tidak pernah lolos. */
    public static function masihBerlaku(?Request $request): bool
    {
        if ($request === null || ! $request->hasSession()) {
            return false;
        }
        $pada = $request->session()->get(self::KUNCI);

        return is_int($pada) && self::berlakuSampai(CarbonImmutable::createFromTimestamp($pada))->isFuture();
    }
}
