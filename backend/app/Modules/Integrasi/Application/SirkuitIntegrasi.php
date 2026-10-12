<?php

namespace App\Modules\Integrasi\Application;

use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\Integrasi\Infrastructure\Penyamar;
use Illuminate\Support\Facades\DB;

/**
 * Circuit breaker per sistem untuk lingkungan aktif (PRD 10 pola teknis wajib; kontrak v0.13.0). `tertutup` = normal;
 * sesudah `ambang` gangguan berturut-turut → `terbuka` sampai `coba_lagi_pada`; lalu tepat satu percobaan
 * (`setengah_terbuka`): berhasil → tertutup, gagal → terbuka lagi dengan durasi berlipat. Penolakan isi (4xx selain
 * 429) bukan gangguan sistem luar dan tidak dihitung. Keadaan di `integrasi.status_sistem`, diubah dengan UPDATE
 * bersyarat agar aman dipakai banyak pekerja.
 */
final class SirkuitIntegrasi
{
    /** true = boleh mengirim sekarang. Bila sirkuit terbuka dan waktunya habis, pemanggil ini yang mendapat jatah percobaan. */
    public function boleh(SistemIntegrasi $sistem): bool
    {
        $s = DB::table('integrasi.status_sistem')->where('tujuan', $sistem->value)->first(['cb_status', 'coba_lagi_pada']);
        if ($s === null || $s->cb_status === 'tertutup') {
            return true;
        }
        if ($s->cb_status === 'setengah_terbuka') {
            // Pemegang jatah percobaan yang tidak pernah melapor (pekerja mati) tidak menahan sirkuit selamanya.
            return DB::table('integrasi.status_sistem')->where('tujuan', $sistem->value)->where('cb_status', 'setengah_terbuka')
                ->where('diubah_pada', '<=', now()->subMinutes(5))->update(['diubah_pada' => now()]) === 1;
        }

        return DB::table('integrasi.status_sistem')->where('tujuan', $sistem->value)->where('cb_status', 'terbuka')
            ->where('coba_lagi_pada', '<=', now())->update(['cb_status' => 'setengah_terbuka', 'diubah_pada' => now()]) === 1;
    }

    /** Waktu paling cepat sistem ini bisa dicoba lagi bila sirkuit tidak tertutup. */
    public function cobaLagiPada(SistemIntegrasi $sistem): ?string
    {
        return DB::table('integrasi.status_sistem')->where('tujuan', $sistem->value)->where('cb_status', '<>', 'tertutup')->value('coba_lagi_pada');
    }

    public function berhasil(SistemIntegrasi $sistem): void
    {
        DB::table('integrasi.status_sistem')->where('tujuan', $sistem->value)->where(fn ($q) => $q->where('cb_status', '<>', 'tertutup')->orWhere('gagal_beruntun', '>', 0))
            ->update(['cb_status' => 'tertutup', 'gagal_beruntun' => 0, 'cb_sejak' => null, 'coba_lagi_pada' => null, 'buka_detik' => 0, 'diubah_pada' => now()]);
    }

    public function gangguan(SistemIntegrasi $sistem, string $pesan): void
    {
        $ambang = (int) config('integrasi.circuit_breaker.ambang');
        $dasar = (int) config('integrasi.circuit_breaker.buka_detik');
        $maks = (int) config('integrasi.circuit_breaker.buka_maks_detik');
        DB::transaction(function () use ($sistem, $pesan, $ambang, $dasar, $maks) {
            $s = DB::table('integrasi.status_sistem')->where('tujuan', $sistem->value)->lockForUpdate()->first();
            if ($s === null) {
                return;
            }
            $gagal = $s->gagal_beruntun + 1;
            $ubah = ['gagal_beruntun' => $gagal, 'error_terakhir' => Penyamar::teks($pesan), 'error_terakhir_pada' => now(), 'diubah_pada' => now()];
            $buka = match (true) {
                $s->cb_status === 'setengah_terbuka' => min(max($s->buka_detik * 2, $dasar), $maks),
                $s->cb_status === 'tertutup' && $gagal >= $ambang => $dasar,
                default => null,
            };
            if ($buka !== null) {
                $ubah += ['cb_status' => 'terbuka', 'cb_sejak' => $s->cb_sejak ?? now(), 'coba_lagi_pada' => now()->addSeconds($buka), 'buka_detik' => $buka];
            }
            DB::table('integrasi.status_sistem')->where('tujuan', $sistem->value)->update($ubah);
        });
    }

    /** Error terakhir tanpa mengubah sirkuit (mis. penolakan isi). */
    public function catatError(SistemIntegrasi $sistem, string $pesan): void
    {
        DB::table('integrasi.status_sistem')->where('tujuan', $sistem->value)
            ->update(['error_terakhir' => Penyamar::teks($pesan), 'error_terakhir_pada' => now(), 'diubah_pada' => now()]);
    }
}
