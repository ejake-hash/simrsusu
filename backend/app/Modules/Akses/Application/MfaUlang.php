<?php

namespace App\Modules\Akses\Application;

use App\Modules\Akses\Models\Pengguna;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

/**
 * Waktu verifikasi MFA terakhir di sesi ini (K11, PRD 10.3.2 langkah 3; tiket 1.5). Diisi saat masuk lewat MFA dan
 * saat verifikasi ulang, dihapus saat masuk tanpa MFA. Izin `butuh_mfa` hanya lolos selama verifikasi itu milik akun
 * yang dinilai dan belum lebih dari `autentikasi.mfa.ulang_menit` menit. Disimpan di sesi, bukan di akun: verifikasi
 * di peramban lain tidak berlaku di sini. Id akun ikut disimpan: regenerate() saat masuk mempertahankan isi sesi,
 * jadi akun lain yang masuk di peramban yang sama tidak boleh mewarisinya.
 */
final class MfaUlang
{
    private const KUNCI = 'autentikasi.mfa_terverifikasi_pada';

    /** @return CarbonImmutable waktu verifikasi yang dicatat */
    public static function catat(Session $sesi, Pengguna $pengguna): CarbonImmutable
    {
        $sekarang = CarbonImmutable::now();
        $sesi->put(self::KUNCI, ['pengguna_id' => $pengguna->id, 'pada' => $sekarang->getTimestamp()]);

        return $sekarang;
    }

    public static function lupakan(Session $sesi): void
    {
        $sesi->forget(self::KUNCI);
    }

    public static function berlakuSampai(CarbonImmutable $terverifikasi): CarbonImmutable
    {
        return $terverifikasi->addMinutes((int) config('autentikasi.mfa.ulang_menit'));
    }

    /** Tanpa sesi (konsol, token mesin), atau sesi milik akun lain, tidak pernah lolos. */
    public static function masihBerlaku(?Request $request, Pengguna $pengguna): bool
    {
        if ($request === null || ! $request->hasSession() || $request->user()?->getAuthIdentifier() !== $pengguna->id) {
            return false;
        }
        $isi = $request->session()->get(self::KUNCI);

        return is_array($isi) && ($isi['pengguna_id'] ?? null) === $pengguna->id && is_int($isi['pada'] ?? null)
            && self::berlakuSampai(CarbonImmutable::createFromTimestamp($isi['pada']))->isFuture();
    }
}
