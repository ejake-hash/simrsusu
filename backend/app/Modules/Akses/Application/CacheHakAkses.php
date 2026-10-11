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
 * Penghapusan diulang setelah commit, agar permintaan lain yang sempat menghitung dari data lama
 * sebelum commit tidak meninggalkan hasil usang di cache.
 */
final class CacheHakAkses
{
    private const GENERASI = 'akses:hak:generasi';

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
        $hapus = fn () => $this->cache->forget($this->kunci($penggunaId));
        $hapus();
        DB::afterCommit($hapus);
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

        return "akses:hak:$generasi:$penggunaId";
    }
}
