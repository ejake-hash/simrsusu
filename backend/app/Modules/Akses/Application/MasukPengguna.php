<?php

namespace App\Modules\Akses\Application;

use App\Core\Audit\CatatAktivitas;
use App\Modules\Akses\Models\Pengguna;
use Carbon\CarbonInterface;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Memeriksa nama pengguna + sandi dengan aturan PRD 14.2:
 * - pesan sama untuk "akun tidak ada" dan "sandi salah";
 * - 5 kali gagal beruntun mengunci 15 menit (disimpan di akses.pengguna.terkunci_sampai);
 * - nama pengguna yang tidak ada juga "terkunci" setelah 5 kali (di store batas laju) dengan sisa waktu
 *   yang sama, supaya status terkunci tidak membocorkan apakah akun ada;
 * - status nonaktif hanya diberitahukan bila sandi benar.
 *
 * jalankan() hanya memverifikasi sandi. Penghitung gagal direset dan `autentikasi.masuk` dicatat di
 * selesaikan(), sesudah langkah MFA bila ada (PRD 10.3.2).
 */
final class MasukPengguna
{
    /**
     * Hitungan gagal akun nyata tidak kedaluwarsa (kolom percobaan_gagal). Untuk nama yang tidak
     * ada, hitungan disimpan di store batas laju selama ini agar tidak tumbuh tanpa batas.
     */
    private const SIMPAN_HITUNGAN_TANPA_AKUN_DETIK = 86400;

    public function __construct(
        private readonly Hasher $hasher,
        private readonly RateLimiter $pembatas,
        private readonly CatatAktivitas $audit,
        private readonly PenghitungGagal $penghitung,
    ) {}

    /** @throws GagalMasuk */
    public function jalankan(string $namaPengguna, string $sandi): Pengguna
    {
        $nama = mb_strtolower(trim($namaPengguna));
        $pengguna = Pengguna::query()->whereRaw('lower(nama_pengguna) = ?', [$nama])->first();
        $kunciTanpaAkun = 'autentikasi:tanpa-akun:'.hash('sha256', $nama);

        $terkunciSampai = $pengguna !== null
            ? ($pengguna->terkunci() ? $pengguna->terkunci_sampai : null)
            : $this->terkunciSampaiTanpaAkun($kunciTanpaAkun);

        if ($terkunciSampai !== null) {
            $this->gagal($pengguna, AlasanGagalMasuk::AkunTerkunci, $terkunciSampai);
        }

        // Selalu verifikasi hash (hash tiruan bila akun tidak ada) agar waktu respons setara.
        $cocok = $this->hasher->check($sandi, $pengguna?->sandi_hash ?? $this->hashTiruan());

        if ($pengguna === null || ! $cocok) {
            if ($pengguna === null) {
                $this->catatGagalTanpaAkun($kunciTanpaAkun);
            } else {
                $this->penghitung->catat($pengguna);
            }
            $this->gagal($pengguna, AlasanGagalMasuk::KredensialSalah);
        }

        if (! $pengguna->aktif()) {
            $this->gagal($pengguna, AlasanGagalMasuk::AkunNonaktif);
        }

        if ($this->hasher->needsRehash($pengguna->sandi_hash)) {
            $pengguna->forceFill(['sandi_hash' => $this->hasher->make($sandi)])->save();
        }

        return $pengguna;
    }

    /** Semua langkah (sandi, lalu MFA bila wajib) sudah lolos. */
    public function selesaikan(Pengguna $pengguna): void
    {
        $pengguna->forceFill([
            'percobaan_gagal' => 0,
            'terkunci_sampai' => null,
            'login_terakhir_pada' => now(),
        ])->save();

        $this->audit->catat('autentikasi.masuk', $pengguna->id, 'akses.pengguna', $pengguna->id);
    }

    /** @return never */
    private function gagal(?Pengguna $pengguna, AlasanGagalMasuk $alasan, ?CarbonInterface $terkunciSampai = null): void
    {
        $this->audit->catat('autentikasi.gagal', $pengguna?->id, 'akses.pengguna', $pengguna?->id, [
            'alasan' => $alasan->value,
        ]);

        throw new GagalMasuk($alasan, $terkunciSampai);
    }

    /**
     * Tiruan catatGagalDanKunci() untuk nama yang tidak ada: hitungan gagal beruntun, lalu kunci
     * selama durasi penuh sejak gagal terakhir. Sisa waktu kunci dikirim ke klien (Retry-After),
     * jadi harus sama dengan akun nyata; jendela tetap RateLimiter tidak memenuhi itu.
     */
    private function catatGagalTanpaAkun(string $kunci): void
    {
        if ($this->pembatas->hit($kunci.':gagal', self::SIMPAN_HITUNGAN_TANPA_AKUN_DETIK) < $this->maksGagal()) {
            return;
        }

        $this->pembatas->clear($kunci.':gagal');
        $sampai = now()->addMinutes($this->durasiKunciMenit());
        $this->penyimpanKunci()->put($kunci.':terkunci-sampai', $sampai->getTimestamp(), $sampai);
    }

    private function terkunciSampaiTanpaAkun(string $kunci): ?CarbonInterface
    {
        $sampai = $this->penyimpanKunci()->get($kunci.':terkunci-sampai');

        return is_int($sampai) && $sampai > now()->getTimestamp() ? Carbon::createFromTimestamp($sampai) : null;
    }

    /**
     * Store yang sama dengan penghitung RateLimiter (config cache.limiter, instans noeviction): kunci
     * nama fiktif tidak boleh dibuang lebih awal saat memori cache penuh.
     */
    private function penyimpanKunci(): Repository
    {
        return Cache::store(config('cache.limiter'));
    }

    private function hashTiruan(): string
    {
        return Cache::rememberForever('autentikasi:hash-tiruan', fn () => $this->hasher->make(Str::random(40)));
    }

    private function maksGagal(): int
    {
        return (int) config('autentikasi.penguncian.maks_gagal');
    }

    private function durasiKunciMenit(): int
    {
        return (int) config('autentikasi.penguncian.durasi_menit');
    }
}
