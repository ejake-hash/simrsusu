<?php

namespace App\Modules\Integrasi\Application;

use App\Core\Http\ApiError;
use App\Modules\Integrasi\Domain\Kiriman;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\Integrasi\Infrastructure\Penyamar;
use App\Modules\Integrasi\Jobs\KirimKiriman;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pekerja outbox (PRD 10): ambil satu kiriman dengan sewa (tanpa memegang kunci baris selama panggilan HTTP), periksa
 * lingkungan, syarat data induk (tertahan, FR-49-04) dan circuit breaker, kirim lewat penangannya, lalu retry
 * eksponensial dengan batas. Putaran terjadwal juga memeriksa ulang kiriman tertahan karena data induk (FR-49-05).
 */
final class PengirimKiriman
{
    /** Lama sewa satu kiriman yang sedang dikirim; sesudahnya pekerja lain boleh mengambilnya lagi. */
    private const SEWA_MENIT = 10;

    /** Jeda pemeriksaan ulang kiriman tertahan karena data induk. */
    private const PERIKSA_ULANG_MENIT = 15;

    public function __construct(
        private readonly DaftarPenangan $penangan,
        private readonly KelolaKredensial $kredensial,
        private readonly SirkuitIntegrasi $sirkuit,
    ) {}

    public function proses(string $id): void
    {
        $baris = $this->sewa($id);
        if ($baris === null) {
            return;
        }
        $kiriman = Kiriman::dariBaris($baris);
        $aktif = $this->kredensial->lingkunganAktif($kiriman->tujuan);
        if ($aktif === null || $kiriman->lingkungan !== $aktif) {
            $this->tahan($baris, $aktif === null ? 'integrasi_nonaktif' : 'lingkungan_berubah',
                $aktif === null ? 'Integrasi sistem ini dinonaktifkan.' : 'Lingkungan integrasi berganti; kiriman ini tidak dipindah ke lingkungan baru.', null, null);

            return;
        }
        $penangan = $this->penangan->untuk($kiriman->tujuan, $kiriman->jenisResource);
        if ($penangan === null) {
            $this->simpan($baris, ['status' => 'gagal', 'error_terakhir' => 'Jenis kiriman '.$kiriman->jenisResource.' tidak dikenal.', 'coba_lagi_pada' => null]);

            return;
        }
        try {
            $syarat = $penangan->periksa($kiriman);
        } catch (Throwable $e) {
            $this->galatPenangan($baris, $kiriman, $e, false);

            return;
        }
        if ($syarat !== null) {
            $this->tahan($baris, $syarat['kode'], $syarat['pesan'], $syarat['pemilik_unit_id'], now()->addMinutes(self::PERIKSA_ULANG_MENIT));

            return;
        }
        if (! $this->sirkuit->boleh($kiriman->tujuan)) {
            $this->simpan($baris, ['coba_lagi_pada' => $this->sirkuit->cobaLagiPada($kiriman->tujuan) ?? now()->addMinute()]);

            return;
        }
        try {
            $nilai = (array) $this->kredensial->nilai($kiriman->tujuan, $aktif);
        } catch (Throwable $e) {
            // Kunci kredensial belum dikonfigurasi atau tidak cocok (rotasi belum lengkap): bukan gangguan sistem luar;
            // dicoba lagi nanti tanpa menambah percobaan. Pesan selain ApiError tidak disimpan.
            $pesan = $e instanceof ApiError ? $e->getMessage() : 'Kredensial tidak bisa dibuka; periksa KREDENSIAL_KUNCI/KREDENSIAL_KUNCI_LAMA.';
            $this->sirkuit->lepasJatah($kiriman->tujuan);
            $this->sirkuit->catatError($kiriman->tujuan, $pesan);
            $this->simpan($baris, ['coba_lagi_pada' => now()->addMinutes(5), 'error_terakhir' => $pesan]);

            return;
        }

        try {
            $hasil = $penangan->kirim($kiriman, $aktif, $nilai);
        } catch (Throwable $e) {
            $this->galatPenangan($baris, $kiriman, $e, true);

            return;
        }
        if ($hasil->tunda) {
            $this->sirkuit->lepasJatah($kiriman->tujuan);
            $this->simpan($baris, ['coba_lagi_pada' => now()->addMinute(), 'error_terakhir' => $hasil->pesan]);

            return;
        }
        if ($hasil->berhasil) {
            $this->sirkuit->berhasil($kiriman->tujuan);
            $this->simpan($baris, ['status' => 'terkirim', 'terkirim_pada' => now(), 'coba_lagi_pada' => null, 'error_terakhir' => null,
                'percobaan' => $kiriman->percobaan + 1, 'id_luar' => $hasil->idLuar ?? $kiriman->idLuar]);

            return;
        }
        $pesan = Penyamar::teks((string) $hasil->pesan);
        $percobaan = $kiriman->percobaan + 1;
        if ($hasil->gangguan) {
            $this->sirkuit->gangguan($kiriman->tujuan, (string) $pesan);
            $habis = $percobaan >= (int) config('integrasi.maks_percobaan');
            $this->simpan($baris, ['status' => $habis ? 'gagal' : 'menunggu', 'percobaan' => $percobaan, 'error_terakhir' => $pesan,
                'coba_lagi_pada' => $habis ? null : now()->addMinutes(self::tunggu($percobaan))]);

            return;
        }
        // Ditolak sistem luar karena isinya: sistem itu sehat, kirim ulang otomatis tidak akan mengubah jawabannya.
        $this->sirkuit->berhasil($kiriman->tujuan);
        $this->sirkuit->catatError($kiriman->tujuan, (string) $pesan);
        $this->simpan($baris, ['status' => 'gagal', 'percobaan' => $percobaan, 'error_terakhir' => $pesan, 'coba_lagi_pada' => null]);
    }

    /** Putaran terjadwal: kirim yang jatuh tempo per sistem, periksa ulang yang tertahan karena data induk. */
    public function putaran(): void
    {
        $batas = (int) config('integrasi.per_putaran');
        foreach (SistemIntegrasi::cases() as $sistem) {
            $status = DB::table('integrasi.status_sistem')->where('tujuan', $sistem->value)->first(['cb_status', 'coba_lagi_pada']);
            $tertutup = $status === null || $status->cb_status === 'tertutup';
            if (! $tertutup && $status->cb_status === 'terbuka' && Carbon::parse($status->coba_lagi_pada)->isFuture()) {
                continue;
            }
            DB::table('integrasi.kotak_keluar')->where('tujuan', $sistem->value)->where('status', 'menunggu')->where('coba_lagi_pada', '<=', now())
                ->orderBy('coba_lagi_pada')->limit($tertutup ? $batas : 1)->pluck('id')->each(fn (string $id) => KirimKiriman::dispatch($id));

            $tertahan = DB::table('integrasi.kotak_keluar')->where('tujuan', $sistem->value)->where('status', 'tertahan')
                ->whereNotIn('penyebab_tertahan_kode', self::tahanManual())->where('coba_lagi_pada', '<=', now())->orderBy('coba_lagi_pada')->limit($batas)->get();
            foreach ($tertahan as $b) {
                $penangan = $this->penangan->untuk($sistem, $b->jenis_resource);
                $syarat = $penangan?->periksa(Kiriman::dariBaris($b));
                if ($penangan !== null && $syarat === null) {
                    $this->simpan($b, ['status' => 'menunggu', 'penyebab_tertahan_kode' => null, 'penyebab_tertahan_pesan' => null, 'coba_lagi_pada' => now()]);
                    KirimKiriman::dispatch($b->id);
                } else {
                    $this->simpan($b, ['coba_lagi_pada' => now()->addMinutes(self::PERIKSA_ULANG_MENIT),
                        ...($syarat === null ? [] : ['penyebab_tertahan_kode' => $syarat['kode'], 'penyebab_tertahan_pesan' => $syarat['pesan'], 'pemilik_unit_id' => $syarat['pemilik_unit_id']])]);
                }
            }
        }
    }

    /**
     * Exception dari penangan = cacat kode, bukan gangguan sistem luar. Pesan exception bisa memuat URL atau data
     * pasien, jadi yang dicatat hanya kelasnya. Kiriman dihitung satu percobaan (tidak berulang tanpa batas) dan jatah
     * percobaan circuit breaker dilepas.
     */
    private function galatPenangan(object $baris, Kiriman $kiriman, Throwable $e, bool $sudahMemakaiJatah): void
    {
        Log::error('Penangan kiriman integrasi gagal', ['kiriman_id' => $kiriman->id, 'tujuan' => $kiriman->tujuan->value,
            'jenis_resource' => $kiriman->jenisResource, 'exception' => $e::class]);
        if ($sudahMemakaiJatah) {
            $this->sirkuit->lepasJatah($kiriman->tujuan);
        }
        $percobaan = $kiriman->percobaan + 1;
        $habis = $percobaan >= (int) config('integrasi.maks_percobaan');
        $this->simpan($baris, ['status' => $habis ? 'gagal' : 'menunggu', 'percobaan' => $percobaan,
            'error_terakhir' => 'Kesalahan internal saat memproses kiriman ('.class_basename($e).').',
            'coba_lagi_pada' => $habis ? null : now()->addMinutes(self::tunggu($percobaan))]);
    }

    /** Menit tunggu sebelum percobaan berikutnya: dasar × 2^(n-1), paling lama tunggu_maks. */
    public static function tunggu(int $percobaan): int
    {
        return (int) min((int) config('integrasi.tunggu_dasar_menit') * 2 ** max(0, $percobaan - 1), (int) config('integrasi.tunggu_maks_menit'));
    }

    /** @return list<string> */
    private static function tahanManual(): array
    {
        return KotakKeluar::TAHAN_MANUAL;
    }

    private function sewa(string $id): ?object
    {
        return DB::transaction(function () use ($id) {
            $baris = DB::table('integrasi.kotak_keluar')->where('id', $id)->where('status', 'menunggu')
                ->where(fn ($q) => $q->whereNull('coba_lagi_pada')->orWhere('coba_lagi_pada', '<=', now()))->lock('FOR UPDATE SKIP LOCKED')->first();
            if ($baris !== null) {
                DB::table('integrasi.kotak_keluar')->where('id', $id)->where('dibuat_pada', $baris->dibuat_pada)
                    ->update(['coba_lagi_pada' => now()->addMinutes(self::SEWA_MENIT), 'diubah_pada' => now()]);
            }

            return $baris;
        });
    }

    private function tahan(object $baris, string $kode, string $pesan, ?string $pemilikUnitId, mixed $periksaLagi): void
    {
        $this->simpan($baris, ['status' => 'tertahan', 'penyebab_tertahan_kode' => $kode, 'penyebab_tertahan_pesan' => $pesan,
            'pemilik_unit_id' => $pemilikUnitId ?? $baris->pemilik_unit_id, 'coba_lagi_pada' => $periksaLagi]);
    }

    /** @param  array<string, mixed>  $ubah */
    private function simpan(object $baris, array $ubah): void
    {
        DB::table('integrasi.kotak_keluar')->where('id', $baris->id)->where('dibuat_pada', $baris->dibuat_pada)->update([...$ubah, 'diubah_pada' => now()]);
    }
}
