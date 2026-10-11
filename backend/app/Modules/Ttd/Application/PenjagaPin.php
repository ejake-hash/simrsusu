<?php

namespace App\Modules\Ttd\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Ttd\Domain\KunciTtd;
use App\Modules\Ttd\Events\TtdTerkunci;
use App\Modules\Ttd\Infrastructure\KunciServer;
use App\Modules\Ttd\Models\Kredensial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Membuka kunci privat dengan PIN (PRD 7.57.2). Hitungan salah disimpan di transaksinya sendiri agar tetap tercatat
 * walau permintaan gagal; baris kredensial dikunci sehingga tebakan paralel tidak melewati batas. Batas tercapai →
 * terkunci (konfigurasi ttd.kunci_menit) dan kejadian TtdTerkunci (notifikasi ke pemilik dan atasan, 1.7).
 */
final class PenjagaPin
{
    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly AtasanLangsung $atasan,
        private readonly KunciServer $kunciServer,
    ) {}

    public function kredensialAktif(Pengguna $pengguna): Kredensial
    {
        return Kredensial::query()->where('pengguna_id', $pengguna->id)->where('tingkat', 'internal')->where('status', 'aktif')->first()
            ?? throw new ApiError(409, 'ttd_belum_aktif', 'Tanda tangan elektronik belum diaktifkan.');
    }

    /**
     * @param  string  $kolom  kolom rincian bila PIN salah (pin | pin_lama)
     * @return array{0: Kredensial, 1: string} kredensial dan kunci privat (pemanggil wajib sodium_memzero)
     */
    public function buka(Pengguna $pengguna, string $pin, string $kolom = 'pin'): array
    {
        [$hasil, $kredensial, $rahasia] = DB::transaction(function () use ($pengguna, $pin) {
            $k = Kredensial::query()->where('pengguna_id', $pengguna->id)->where('tingkat', 'internal')->where('status', 'aktif')->lockForUpdate()->first();
            if ($k === null) {
                return ['belum_aktif', null, null];
            }
            if ($k->terkunci()) {
                return ['terkunci', $k, null];
            }
            $lada = $this->kunciServer->menurutId((string) ($k->parameter_kdf['kunci'] ?? ''));
            if ($lada === null) {
                return ['tanpa_kunci_server', $k, null];
            }
            $rahasia = Hash::check(KunciTtd::berlada($pin, $lada), (string) $k->pin_hash)
                ? KunciTtd::buka((string) $k->kunci_privat_terbungkus, (string) $k->garam, (array) $k->parameter_kdf, $pin, $lada, $pengguna->id, (string) $k->kunci_publik)
                : null;
            if ($rahasia !== null) {
                if ($k->percobaan_gagal > 0) {
                    $k->forceFill(['percobaan_gagal' => 0])->save();
                }

                return ['benar', $k, $rahasia];
            }

            $gagal = $k->percobaan_gagal + 1;
            if ($gagal < (int) config('ttd.maks_pin_salah')) {
                $k->forceFill(['percobaan_gagal' => $gagal])->save();

                return ['salah', $k, null];
            }
            $k->forceFill(['percobaan_gagal' => 0, 'terkunci_sampai' => now()->addMinutes((int) config('ttd.kunci_menit'))])->save();
            TtdTerkunci::dispatch($pengguna->id, $this->atasan->untuk($pengguna)?->id, $k->terkunci_sampai->toIso8601String());
            $this->audit->catat('ttd.terkunci', $pengguna->id, 'ttd.kredensial', $k->id, ['terkunci_sampai' => $k->terkunci_sampai->toIso8601String()]);

            return ['terkunci', $k, null];
        });

        return match ($hasil) {
            'benar' => [$kredensial, $rahasia],
            'belum_aktif' => throw new ApiError(409, 'ttd_belum_aktif', 'Tanda tangan elektronik belum diaktifkan.'),
            'terkunci' => throw $this->terkunci($kredensial),
            'tanpa_kunci_server' => throw new ApiError(503, 'ttd_belum_dikonfigurasi', 'Kunci server tanda tangan tidak tersedia. Hubungi admin.'),
            default => throw $this->pinSalah($kolom, (int) config('ttd.maks_pin_salah') - $kredensial->percobaan_gagal),
        };
    }

    private function pinSalah(string $kolom, int $sisa): ApiError
    {
        return new ApiError(422, 'pin_salah', 'PIN salah.', [['kolom' => $kolom, 'kode' => 'pin_salah', 'pesan' => "PIN salah. Sisa percobaan: {$sisa}.", 'sisa_percobaan' => $sisa]]);
    }

    public function terkunci(Kredensial $k): ApiError
    {
        $detik = max(1, (int) ceil(now()->diffInSeconds($k->terkunci_sampai, true)));

        return new ApiError(403, 'ttd_terkunci', 'Tanda tangan terkunci karena PIN salah beberapa kali. Coba lagi nanti.', [],
            ['Retry-After' => (string) $detik]);
    }
}
