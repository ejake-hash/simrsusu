<?php

namespace App\Modules\Akses\Application;

use App\Core\Audit\CatatAktivitas;
use App\Modules\Akses\Domain\Totp;
use App\Modules\Akses\Models\Pengguna;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Langkah MFA (PRD 10.3.2): kode TOTP atau kode cadangan, dan pendaftaran autentikator pertama kali.
 * Kode salah masuk penghitung gagal yang sama dengan sandi. Audit tidak pernah memuat kode,
 * rahasia, maupun URI otpauth.
 */
final class VerifikasiMfa
{
    public const JUMLAH_KODE_CADANGAN = 10;

    // Tanpa huruf/angka yang mudah tertukar (0/o, 1/l/i). 10 karakter dari 31 simbol ≈ 49 bit.
    private const ALFABET_KODE_CADANGAN = 'abcdefghjkmnpqrstuvwxyz23456789';

    public function __construct(
        private readonly Hasher $hasher,
        private readonly CatatAktivitas $audit,
        private readonly PenghitungGagal $penghitung,
    ) {}

    /** @throws GagalMfa */
    public function verifikasi(Pengguna $pengguna, string $metode, string $kode): void
    {
        $this->tolakBilaTerkunci($pengguna);

        $cocok = $metode === 'kode_cadangan'
            ? $this->pakaiKodeCadangan($pengguna, $kode)
            : $this->terimaTotp($pengguna, (string) $pengguna->mfa_rahasia_enkripsi, $kode);

        if (! $cocok) {
            $this->gagal($pengguna, $metode);
        }

        $this->audit->catat('autentikasi.mfa_berhasil', $pengguna->id, 'akses.pengguna', $pengguna->id, ['metode' => $metode]);
    }

    /**
     * Konfirmasi pendaftaran: kode dari rahasia baru harus benar, lalu rahasia disimpan dan 10 kode
     * cadangan dibuat. Kode cadangan dalam bentuk asli hanya dikembalikan sekali di sini.
     *
     * @return list<string>
     *
     * @throws GagalMfa
     */
    public function daftarkan(Pengguna $pengguna, string $rahasia, string $kode): array
    {
        $this->tolakBilaTerkunci($pengguna);

        $langkah = Totp::cocokkan($rahasia, $kode, now()->getTimestamp(), (int) config('autentikasi.mfa.toleransi_periode'));
        if ($langkah === null) {
            $this->gagal($pengguna, 'totp');
        }

        $kodeCadangan = [];
        for ($i = 0; $i < self::JUMLAH_KODE_CADANGAN; $i++) {
            $kodeCadangan[] = $this->kodeCadanganBaru();
        }
        $baris = array_map(fn (string $k) => [
            'id' => (string) Str::uuid7(),
            'pengguna_id' => $pengguna->id,
            'kode_hash' => $this->hasher->make($this->normalkan($k)),
        ], $kodeCadangan);

        DB::transaction(function () use ($pengguna, $rahasia, $langkah, $baris) {
            $pengguna->forceFill([
                'mfa_rahasia_enkripsi' => $rahasia,
                'mfa_aktif_pada' => now(),
                'mfa_langkah_terakhir' => $langkah,
            ])->save();

            DB::table('akses.kode_cadangan_mfa')
                ->where('pengguna_id', $pengguna->id)
                ->whereNull('dipakai_pada')
                ->whereNull('dibatalkan_pada')
                ->update(['dibatalkan_pada' => now()]);
            DB::table('akses.kode_cadangan_mfa')->insert($baris);
        });

        $this->audit->catat('autentikasi.mfa_didaftarkan', $pengguna->id, 'akses.pengguna', $pengguna->id);

        return $kodeCadangan;
    }

    public function punyaKodeCadangan(Pengguna $pengguna): bool
    {
        return $this->kodeCadanganBerlaku($pengguna)->exists();
    }

    /**
     * Kode TOTP diterima bila cocok (±toleransi periode) dan langkahnya lebih baru dari langkah
     * terakhir yang diterima. Pembaruan atomik: dua permintaan bersamaan dengan kode yang sama,
     * hanya satu yang lolos.
     */
    private function terimaTotp(Pengguna $pengguna, string $rahasia, string $kode): bool
    {
        if ($rahasia === '') {
            return false;
        }

        $langkah = Totp::cocokkan($rahasia, $kode, now()->getTimestamp(), (int) config('autentikasi.mfa.toleransi_periode'));
        if ($langkah === null) {
            return false;
        }

        return DB::update(
            'UPDATE akses.pengguna SET mfa_langkah_terakhir = ?
              WHERE id = ? AND (mfa_langkah_terakhir IS NULL OR mfa_langkah_terakhir < ?)',
            [$langkah, $pengguna->id, $langkah],
        ) === 1;
    }

    private function pakaiKodeCadangan(Pengguna $pengguna, string $kode): bool
    {
        $normal = $this->normalkan($kode);
        if (preg_match('/^['.self::ALFABET_KODE_CADANGAN.']{10}$/', $normal) !== 1) {
            return false;
        }

        foreach ($this->kodeCadanganBerlaku($pengguna)->get(['id', 'kode_hash']) as $baris) {
            if (! $this->hasher->check($normal, $baris->kode_hash)) {
                continue;
            }

            $terpakai = DB::table('akses.kode_cadangan_mfa')
                ->where('id', $baris->id)
                ->whereNull('dipakai_pada')
                ->update(['dipakai_pada' => now()]) === 1;

            if ($terpakai) {
                $this->audit->catat('autentikasi.kode_cadangan_dipakai', $pengguna->id, 'akses.pengguna', $pengguna->id, [
                    'sisa' => $this->kodeCadanganBerlaku($pengguna)->count(),
                ]);
            }

            return $terpakai;
        }

        return false;
    }

    private function kodeCadanganBerlaku(Pengguna $pengguna): Builder
    {
        return DB::table('akses.kode_cadangan_mfa')
            ->where('pengguna_id', $pengguna->id)
            ->whereNull('dipakai_pada')
            ->whereNull('dibatalkan_pada');
    }

    /** @throws GagalMfa */
    private function tolakBilaTerkunci(Pengguna $pengguna): void
    {
        if ($pengguna->terkunci()) {
            throw new GagalMfa(AlasanGagalMfa::AkunTerkunci, $pengguna->terkunci_sampai);
        }
    }

    /**
     * @return never
     *
     * @throws GagalMfa
     */
    private function gagal(Pengguna $pengguna, string $metode): void
    {
        $this->audit->catat('autentikasi.mfa_gagal', $pengguna->id, 'akses.pengguna', $pengguna->id, ['metode' => $metode]);
        $this->penghitung->catat($pengguna);

        if ($pengguna->terkunci()) {
            throw new GagalMfa(AlasanGagalMfa::AkunTerkunci, $pengguna->terkunci_sampai);
        }

        throw new GagalMfa(AlasanGagalMfa::KodeSalah);
    }

    private function kodeCadanganBaru(): string
    {
        $kode = '';
        for ($i = 0; $i < 10; $i++) {
            $kode .= self::ALFABET_KODE_CADANGAN[random_int(0, strlen(self::ALFABET_KODE_CADANGAN) - 1)];
        }

        return substr($kode, 0, 5).'-'.substr($kode, 5);
    }

    private function normalkan(string $kode): string
    {
        return strtolower((string) preg_replace('/[\s-]+/', '', $kode));
    }
}
