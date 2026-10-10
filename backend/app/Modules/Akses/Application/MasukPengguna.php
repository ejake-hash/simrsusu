<?php

namespace App\Modules\Akses\Application;

use App\Core\Audit\CatatAktivitas;
use App\Modules\Akses\Models\Pengguna;
use Carbon\CarbonInterface;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Memeriksa nama pengguna + sandi dengan aturan PRD 14.2:
 * - pesan sama untuk "akun tidak ada" dan "sandi salah";
 * - 5 kali gagal beruntun mengunci 15 menit (disimpan di akses.pengguna.terkunci_sampai);
 * - nama pengguna yang tidak ada juga "terkunci" setelah 5 kali (di cache) dengan sisa waktu
 *   yang sama, supaya status terkunci tidak membocorkan apakah akun ada;
 * - status nonaktif hanya diberitahukan bila sandi benar.
 */
final class MasukPengguna
{
    /**
     * Hitungan gagal akun nyata tidak kedaluwarsa (kolom percobaan_gagal). Untuk nama yang tidak
     * ada, hitungan disimpan di cache selama ini agar cache tidak tumbuh tanpa batas.
     */
    private const SIMPAN_HITUNGAN_TANPA_AKUN_DETIK = 86400;

    public function __construct(
        private readonly Hasher $hasher,
        private readonly RateLimiter $pembatas,
        private readonly CatatAktivitas $audit,
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
            } elseif ($this->catatGagalDanKunci($pengguna)) {
                $this->audit->catat('autentikasi.terkunci', $pengguna->id, 'akses.pengguna', $pengguna->id, [
                    'terkunci_sampai' => $pengguna->terkunci_sampai?->toIso8601String(),
                ]);
            }
            $this->gagal($pengguna, AlasanGagalMasuk::KredensialSalah);
        }

        if (! $pengguna->aktif()) {
            $this->gagal($pengguna, AlasanGagalMasuk::AkunNonaktif);
        }

        if ($this->hasher->needsRehash($pengguna->sandi_hash)) {
            $pengguna->sandi_hash = $this->hasher->make($sandi);
        }
        $pengguna->forceFill([
            'percobaan_gagal' => 0,
            'terkunci_sampai' => null,
            'login_terakhir_pada' => now(),
        ])->save();

        $this->audit->catat('autentikasi.masuk', $pengguna->id, 'akses.pengguna', $pengguna->id);

        return $pengguna;
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
        Cache::put($kunci.':terkunci-sampai', $sampai->getTimestamp(), $sampai);
    }

    private function terkunciSampaiTanpaAkun(string $kunci): ?CarbonInterface
    {
        $sampai = Cache::get($kunci.':terkunci-sampai');

        return is_int($sampai) && $sampai > now()->getTimestamp() ? Carbon::createFromTimestamp($sampai) : null;
    }

    /**
     * Menambah hitungan gagal secara atomik dan mengunci bila mencapai batas. Akun yang sedang
     * terkunci sudah ditolak sebelumnya, jadi terkunci() sesudah ini berarti baru saja terkunci.
     */
    private function catatGagalDanKunci(Pengguna $pengguna): bool
    {
        DB::update(
            'UPDATE akses.pengguna
                SET percobaan_gagal = CASE WHEN percobaan_gagal + 1 >= ? THEN 0 ELSE percobaan_gagal + 1 END,
                    terkunci_sampai = CASE WHEN percobaan_gagal + 1 >= ? THEN CAST(? AS timestamptz) ELSE terkunci_sampai END
              WHERE id = ?',
            [
                $this->maksGagal(),
                $this->maksGagal(),
                now()->addMinutes($this->durasiKunciMenit())->toIso8601String(),
                $pengguna->id,
            ],
        );

        return $pengguna->refresh()->terkunci();
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
