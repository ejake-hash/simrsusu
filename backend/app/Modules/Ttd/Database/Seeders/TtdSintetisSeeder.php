<?php

namespace App\Modules\Ttd\Database\Seeders;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\Ttd\Domain\AturanPin;
use App\Modules\Ttd\Domain\KunciTtd;
use App\Modules\Ttd\Infrastructure\KunciServer;
use App\Modules\Ttd\Models\Kredensial;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * TTE sintetis untuk lokal/staging/e2e (tiket 1.6). Dijalankan sesudah AkunSintetisSeeder. Keadaan awal sama tiap
 * seeding: TTE lama akun ini dicabut (tidak dihapus), lalu dibuat baru dengan PIN sintetis dari
 * `AKUN_SINTETIS_TTD_PIN` (kosong = acak, dicetak sekali). Ditolak di production; dilewati bila TTD_KUNCI kosong.
 */
final class TtdSintetisSeeder extends Seeder
{
    /** Akun dengan TTE aktif: akun TTE contoh dan superadmin penyetuju e2e kontrol ganda (menandatangani keputusannya). */
    public const AKTIF = ['contoh.ttd', 'contoh.superadmin.dua'];

    /** Akun tanpa TTE (alur aktivasi). */
    public const BELUM_AKTIF = ['contoh.ttd.baru'];

    public function run(KunciServer $kunciServer): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('TtdSintetisSeeder ditolak berjalan di production.');
        }
        if (config('ttd.kunci') === null || config('ttd.kunci') === '') {
            $this->command->warn('TTD_KUNCI kosong: TTE sintetis dilewati.');

            return;
        }
        $pin = (string) config('ttd.pin_sintetis');
        $acak = $pin === '';
        while ($pin === '' || AturanPin::lemah($pin)) {
            if (! $acak) {
                throw new RuntimeException('AKUN_SINTETIS_TTD_PIN harus 6 angka, tidak berurutan, dan tidak berulang.');
            }
            $pin = sprintf('%06d', random_int(0, 999999));
        }
        $server = $kunciServer->aktif();

        foreach (Pengguna::query()->whereIn('nama_pengguna', [...self::AKTIF, ...self::BELUM_AKTIF])->get() as $akun) {
            Kredensial::query()->where('pengguna_id', $akun->id)->where('status', 'aktif')
                ->update(['status' => 'dicabut', 'dicabut_pada' => now(), 'alasan_cabut' => 'Seeding ulang data sintetis']);
            if (! in_array($akun->nama_pengguna, self::AKTIF, true)) {
                continue;
            }
            $kunci = KunciTtd::buat($pin, $server['kunci'], $akun->id);
            Kredensial::query()->create([
                'pengguna_id' => $akun->id, 'kunci_publik' => $kunci['publik'], 'kunci_privat_terbungkus' => $kunci['terbungkus'],
                'garam' => $kunci['garam'], 'parameter_kdf' => [...$kunci['parameter'], 'kunci' => $server['id']],
                'pin_hash' => Hash::make(KunciTtd::berlada($pin, $server['kunci'])), 'diaktifkan_pada' => now(),
            ]);
        }

        $this->command->info('TTE sintetis aktif: '.implode(', ', self::AKTIF).'.');
        if ($acak) {
            $this->command->warn("PIN TTE sintetis (hanya ditampilkan sekali): {$pin}");
        }
    }
}
