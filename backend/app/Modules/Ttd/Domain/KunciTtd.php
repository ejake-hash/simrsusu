<?php

namespace App\Modules\Ttd\Domain;

/**
 * Kriptografi T1 (PRD 7.57.2 huruf a–b): pasangan kunci Ed25519, kunci privat dibungkus XChaCha20-Poly1305 dengan kunci
 * turunan PIN (Argon2id + garam per kredensial). PIN lebih dulu di-HMAC dengan kunci server (lada, di luar DB), sehingga
 * salinan DB saja tidak cukup untuk menebak PIN 6 digit secara offline. Data tambahan (AD) mengikat bungkusan ke akun
 * dan kunci publiknya, sehingga bungkusan milik akun lain tidak bisa dipindahkan. Tanpa PIN, kunci privat tidak terbuka.
 */
final class KunciTtd
{
    /**
     * @return array{publik: string, terbungkus: string, garam: string, parameter: array{opslimit: int, memlimit: int, alg: int}}
     */
    public static function buat(string $pin, string $lada, string $penggunaId): array
    {
        $pasangan = sodium_crypto_sign_keypair();
        $rahasia = sodium_crypto_sign_secretkey($pasangan);
        $publik = sodium_crypto_sign_publickey($pasangan);
        sodium_memzero($pasangan);

        $hasil = ['publik' => base64_encode($publik), ...self::bungkus($rahasia, $pin, $lada, $penggunaId, base64_encode($publik))];
        sodium_memzero($rahasia);

        return $hasil;
    }

    /**
     * Bungkus kunci privat dengan PIN (aktivasi atau ganti PIN). Garam dan nonce baru setiap kali.
     *
     * @return array{terbungkus: string, garam: string, parameter: array{opslimit: int, memlimit: int, alg: int}}
     */
    public static function bungkus(string $rahasia, string $pin, string $lada, string $penggunaId, string $publik): array
    {
        $garam = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        // KDF minimum hanya berlaku di lingkungan tes.
        $minimum = config('ttd.kdf') === 'minimum' && app()->environment('testing');
        $parameter = ['opslimit' => $minimum ? 1 : SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            'memlimit' => $minimum ? 8192 : SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            'alg' => SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13];
        $kek = self::kek(self::berlada($pin, $lada), $garam, $parameter);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $sandi = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($rahasia, self::ad($penggunaId, $publik), $nonce, $kek);
        sodium_memzero($kek);
        sodium_memzero($rahasia);

        return ['terbungkus' => base64_encode($nonce.$sandi), 'garam' => base64_encode($garam), 'parameter' => $parameter];
    }

    /**
     * Buka kunci privat; null bila PIN salah, bungkusan/AD tidak cocok, atau parameter KDF tersimpan di luar rentang wajar.
     * Pemanggil wajib sodium_memzero hasilnya.
     *
     * @param  array<string, mixed>  $parameter  parameter_kdf tersimpan
     */
    public static function buka(string $terbungkus, string $garam, array $parameter, string $pin, string $lada, string $penggunaId, string $publik): ?string
    {
        $isi = base64_decode($terbungkus, true);
        $garamMentah = base64_decode($garam, true);
        $kdf = ['opslimit' => (int) ($parameter['opslimit'] ?? 0), 'memlimit' => (int) ($parameter['memlimit'] ?? 0), 'alg' => (int) ($parameter['alg'] ?? 0)];
        // Parameter dari DB dibatasi: nilai di luar rentang wajar (mis. diubah agar KDF murah/berat) tidak dipakai.
        $wajar = $kdf['alg'] === SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13 && $kdf['opslimit'] >= 1 && $kdf['opslimit'] <= 10
            && $kdf['memlimit'] >= 8192 && $kdf['memlimit'] <= 1073741824
            && (app()->environment('testing') || $kdf['memlimit'] >= SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE);
        if (! $wajar || $isi === false || $garamMentah === false || strlen($isi) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            return null;
        }
        $kek = self::kek(self::berlada($pin, $lada), $garamMentah, $kdf);
        $nonce = substr($isi, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $rahasia = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($isi, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES),
            self::ad($penggunaId, $publik), $nonce, $kek);
        sodium_memzero($kek);

        return $rahasia === false ? null : $rahasia;
    }

    public static function tandatangani(string $rahasia, string $pesan): string
    {
        return base64_encode(sodium_crypto_sign_detached($pesan, $rahasia));
    }

    public static function cocok(string $publik, string $pesan, string $tandaTangan): bool
    {
        $kunci = base64_decode($publik, true);
        $nilai = base64_decode($tandaTangan, true);
        if ($kunci === false || $nilai === false || strlen($kunci) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($nilai) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($nilai, $pesan, $kunci);
    }

    /** Bahan hash PIN (pin_hash) dan turunan kunci: HMAC-SHA256 PIN dengan kunci server, hex. */
    public static function berlada(string $pin, string $lada): string
    {
        return hash_hmac('sha256', 'simrs-ttd-pin|'.$pin, $lada);
    }

    /** 16 hex awal SHA-256 kunci publik (TandaTangan.sidik_kunci). */
    public static function sidik(string $publik): string
    {
        return substr(hash('sha256', (string) base64_decode($publik, true)), 0, 16);
    }

    /** @param  array{opslimit: int, memlimit: int, alg: int}  $parameter */
    private static function kek(string $pin, string $garam, array $parameter): string
    {
        return sodium_crypto_pwhash(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, $pin, $garam,
            $parameter['opslimit'], $parameter['memlimit'], $parameter['alg']);
    }

    private static function ad(string $penggunaId, string $publik): string
    {
        return 'simrs-ttd-t1|'.$penggunaId.'|'.$publik;
    }
}
