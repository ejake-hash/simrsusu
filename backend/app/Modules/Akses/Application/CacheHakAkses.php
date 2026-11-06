<?php

namespace App\Modules\Akses\Application;

use App\Modules\Akses\Domain\HakAkses;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cache hak akses per akun di Valkey cache (PRD 9.2.5, FR-09-10). Kunci memuat "generasi": perubahan
 * peran, menu, atau izin mengganti generasi sehingga semua akun dihitung ulang; perubahan penugasan
 * atau izin khusus hanya menghapus kunci akun itu. Bila generasi terbuang dari cache (allkeys-lru),
 * generasi baru dibuat dan semua kunci lama ikut tidak terpakai: terbuang = dihitung ulang, bukan usang.
 *
 * Perubahan per akun mengganti "versi" akun itu, sekarang dan lagi setelah commit. Kunci (generasi +
 * versi) dibaca sebelum menghitung, jadi permintaan lain yang sempat menghitung dari data lama menyimpan
 * hasilnya di kunci yang sudah tidak terpakai, bukan di kunci yang dibaca berikutnya.
 */
final class CacheHakAkses
{
    private const GENERASI = 'akses:hak:generasi';

    private const VERSI = 'akses:hak:v:';

    public function __construct(private readonly Repository $cache) {}

    /** @param  callable(): array{0: HakAkses, 1: int}  $hitung  hasil dan lama simpan (detik) */
    public function ambil(string $penggunaId, callable $hitung): HakAkses
    {
        $kunci = $this->kunci($penggunaId);
        $tersimpan = $this->cache->get($kunci);
        if (is_array($tersimpan)) {
            return HakAkses::dariArray($tersimpan);
        }

        [$hak, $detik] = $hitung();
        $this->cache->put($kunci, $hak->keArray(), $detik);

        return $hak;
    }

    public function lupakan(string $penggunaId): void
    {
        $ganti = fn () => $this->cache->forever(self::VERSI.$penggunaId, Str::random(16));
        $ganti();
        DB::afterCommit($ganti);
    }

    public function lupakanSemua(): void
    {
        $ganti = fn () => $this->cache->forever(self::GENERASI, Str::random(16));
        $ganti();
        DB::afterCommit($ganti);
    }

    private function kunci(string $penggunaId): string
    {
        $generasi = $this->cache->rememberForever(self::GENERASI, fn () => Str::random(16));
        $versi = $this->cache->rememberForever(self::VERSI.$penggunaId, fn () => Str::random(16));

        return "akses:hak:$generasi:$versi:$penggunaId";
    }
}
