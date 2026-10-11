<?php

namespace App\Modules\Ttd\Application;

use App\Modules\Akses\Http\Middleware\SesiPengguna;
use App\Modules\Ttd\Models\Kredensial;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;

/**
 * Sesi tanda tangan (PRD 7.57.2): sesudah PIN benar, penandatanganan berikutnya di sesi login yang sama tanpa PIN
 * selama ttd.sesi_menit. Kunci privat disimpan terbungkus kunci acak: bungkusan di sesi server, kunci acaknya di cookie
 * HttpOnly. Akses ke penyimpanan sesi saja, atau cookie saja, tidak cukup untuk menandatangani. Sesi berakhir saat
 * keluar, ganti sandi (waktu masuk sesi berganti), atau revisi kredensial berganti (ganti PIN, terkunci, dicabut) —
 * juga di sesi login lain akun itu.
 */
final class SesiTtd
{
    private const KUNCI = 'ttd.sesi';

    private const KUE = 'ttd_sesi';

    public function mulai(Request $request, Kredensial $k, string $rahasia): void
    {
        $kunci = sodium_crypto_secretbox_keygen();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $menit = (int) config('ttd.sesi_menit');
        $request->session()->put(self::KUNCI, [
            'sampai' => now()->addMinutes($menit)->getTimestamp(),
            'masuk_pada' => $request->session()->get(SesiPengguna::MASUK_PADA),
            'kredensial_id' => $k->id,
            'revisi' => $k->revisi,
            'terbungkus' => base64_encode($nonce.sodium_crypto_secretbox($rahasia, $nonce, $kunci)),
        ]);
        Cookie::queue(Cookie::make(self::KUE, base64_encode($kunci), $menit, '/api/v1', null, null, true, false, 'strict'));
        sodium_memzero($kunci);
    }

    public function berlakuSampai(Request $request, ?Kredensial $k): ?Carbon
    {
        $sesi = $this->sesi($request, $k);

        return $sesi === null ? null : Carbon::createFromTimestamp($sesi['sampai']);
    }

    /** Kunci privat dari sesi yang masih berlaku; null bila sesi tidak ada/berakhir. Pemanggil wajib sodium_memzero. */
    public function buka(Request $request, Kredensial $k): ?string
    {
        $sesi = $this->sesi($request, $k);
        $kunci = base64_decode((string) $request->cookie(self::KUE), true);
        $isi = $sesi === null ? false : base64_decode($sesi['terbungkus'], true);
        if ($sesi === null || $kunci === false || strlen($kunci) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES || $isi === false
            || strlen($isi) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $rahasia = sodium_crypto_secretbox_open(substr($isi, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($isi, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $kunci);

        return $rahasia === false ? null : $rahasia;
    }

    public function akhiri(Request $request): void
    {
        $request->session()->forget(self::KUNCI);
        Cookie::queue(Cookie::forget(self::KUE, '/api/v1'));
    }

    /** @return array{sampai: int, masuk_pada: mixed, kredensial_id: string, revisi: int, terbungkus: string}|null */
    private function sesi(Request $request, ?Kredensial $k): ?array
    {
        $sesi = $request->hasSession() ? $request->session()->get(self::KUNCI) : null;
        $sah = is_array($sesi) && $k !== null && ! $k->terkunci() && ($sesi['kredensial_id'] ?? null) === $k->id
            && ($sesi['revisi'] ?? null) === $k->revisi
            && ($sesi['masuk_pada'] ?? null) === $request->session()->get(SesiPengguna::MASUK_PADA)
            && (int) ($sesi['sampai'] ?? 0) > now()->getTimestamp();

        return $sah ? $sesi : null;
    }
}
