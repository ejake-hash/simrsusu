<?php

namespace App\Modules\Akses\Application;

use App\Modules\Akses\Models\Pengguna;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Challenge MFA disimpan di sesi yang membuatnya (PRD 10.3.2): tidak bisa dipakai dari sesi lain,
 * berlaku 5 menit, dan dihapus setelah dipakai. Satu challenge per sesi; yang lama diganti.
 *
 * Jenis `mfa` = masukkan kode; `daftar` = daftarkan autentikator (rahasia baru disimpan
 * terenkripsi di sesi sampai dikonfirmasi).
 */
final class MfaChallenge
{
    private const KUNCI = 'autentikasi.challenge_mfa';

    /** @return array{id: string, kedaluwarsa_pada: CarbonImmutable} */
    public function buat(Session $sesi, Pengguna $pengguna, string $jenis): array
    {
        $kedaluwarsa = CarbonImmutable::now()->addMinutes((int) config('autentikasi.mfa.challenge_menit'));
        $id = (string) Str::uuid7();

        $sesi->put(self::KUNCI, [
            'id' => $id,
            'jenis' => $jenis,
            'pengguna_id' => $pengguna->id,
            'kedaluwarsa' => $kedaluwarsa->getTimestamp(),
            'rahasia_baru' => null,
        ]);

        return ['id' => $id, 'kedaluwarsa_pada' => $kedaluwarsa];
    }

    /**
     * Challenge yang masih berlaku dengan id dan jenis ini di sesi ini, atau null (kedaluwarsa,
     * sudah dipakai, jenis lain, atau dibuat di sesi lain).
     *
     * @return array{id: string, jenis: string, pengguna_id: string, kedaluwarsa: int, rahasia_baru: ?string}|null
     */
    public function ambil(Session $sesi, string $id, string $jenis): ?array
    {
        $t = $sesi->get(self::KUNCI);

        if (! is_array($t) || ! hash_equals($t['id'], $id) || $t['jenis'] !== $jenis) {
            return null;
        }
        if ($t['kedaluwarsa'] <= now()->getTimestamp()) {
            $this->hapus($sesi);

            return null;
        }

        return $t;
    }

    public function simpanRahasiaBaru(Session $sesi, string $rahasia): void
    {
        $sesi->put(self::KUNCI.'.rahasia_baru', Crypt::encryptString($rahasia));
    }

    /** @param  array{rahasia_baru: ?string}  $challenge */
    public function rahasiaBaru(array $challenge): ?string
    {
        return $challenge['rahasia_baru'] === null ? null : Crypt::decryptString($challenge['rahasia_baru']);
    }

    public function hapus(Session $sesi): void
    {
        $sesi->forget(self::KUNCI);
    }
}
