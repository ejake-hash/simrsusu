<?php

namespace Tests\Unit;

use App\Modules\Akses\Domain\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    /** RFC 6238 Lampiran B, SHA-1, rahasia ASCII "12345678901234567890", 8 digit. */
    #[DataProvider('vektorRfc6238')]
    public function test_vektor_resmi_rfc_6238(int $detik, string $harapan): void
    {
        $rahasia = Totp::base32Enkode('12345678901234567890');

        $this->assertSame($harapan, Totp::kode($rahasia, Totp::langkah($detik), 8));
    }

    /** @return array<string, array{int, string}> */
    public static function vektorRfc6238(): array
    {
        return [
            't=59' => [59, '94287082'],
            't=1111111109' => [1111111109, '07081804'],
            't=1111111111' => [1111111111, '14050471'],
            't=1234567890' => [1234567890, '89005924'],
            't=2000000000' => [2000000000, '69279037'],
            't=20000000000' => [20000000000, '65353130'],
        ];
    }

    public function test_cocokkan_toleransi_satu_periode_dan_tolak_format_salah(): void
    {
        $rahasia = Totp::rahasiaBaru();
        $sekarang = 1_760_000_000;
        $langkah = Totp::langkah($sekarang);

        $this->assertSame($langkah - 1, Totp::cocokkan($rahasia, Totp::kode($rahasia, $langkah - 1), $sekarang));
        $this->assertSame($langkah + 1, Totp::cocokkan($rahasia, Totp::kode($rahasia, $langkah + 1), $sekarang));
        $this->assertNull(Totp::cocokkan($rahasia, Totp::kode($rahasia, $langkah - 2), $sekarang));
        $this->assertNull(Totp::cocokkan($rahasia, '12345', $sekarang));
        $this->assertNull(Totp::cocokkan($rahasia, '12345a', $sekarang));
    }

    public function test_base32_bolak_balik_dan_uri_otpauth(): void
    {
        $biner = random_bytes(20);
        $this->assertSame($biner, Totp::base32Dekode(Totp::base32Enkode($biner)));
        $this->assertSame(32, strlen(Totp::rahasiaBaru()));

        $uri = Totp::uriOtpauth('JBSWY3DPEHPK3PXP', 'RS Contoh', 'contoh.mfa');
        $this->assertSame('otpauth://totp/RS%20Contoh:contoh.mfa?secret=JBSWY3DPEHPK3PXP&issuer=RS%20Contoh&algorithm=SHA1&digits=6&period=30', $uri);
    }
}
