<?php

namespace App\Modules\Antrean\Console;

use App\Core\Audit\CatatAktivitas;
use App\Modules\Antrean\Models\DisplayAntrean;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Token perangkat display antrean (tiket 2.8) sampai halaman Perangkat Anjungan & Display (FR-01-23) dibangun. Membuat
 * token baru mencabut token lama perangkat itu; nilai token hanya tampil sekali dan tersimpan sebagai hash.
 */
final class TokenDisplay extends Command
{
    protected $signature = 'antrean:token-display {kode : Kode perangkat, mis. tv-poli-pd}
        {--area=* : Kode area yang boleh dibaca (ulangi untuk beberapa area); kosong = semua area}
        {--cabut : Cabut semua token perangkat ini tanpa membuat yang baru}';

    protected $description = 'Membuat atau mencabut token perangkat display antrean';

    public function handle(CatatAktivitas $audit): int
    {
        $kode = (string) $this->argument('kode');
        /** @var list<string> $area */
        $area = array_values(array_unique((array) $this->option('area')));
        foreach ([$kode, ...$area] as $nilai) {
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $nilai) !== 1) {
                $this->error("Kode tidak valid: {$nilai} (huruf kecil, angka, - atau _; paling banyak 40).");

                return self::FAILURE;
            }
        }

        if ($this->option('cabut')) {
            $perangkat = DisplayAntrean::query()->where('kode', $kode)->first();
            if ($perangkat === null) {
                $this->error("Perangkat {$kode} tidak ditemukan.");

                return self::FAILURE;
            }
            $jumlah = DB::transaction(function () use ($perangkat, $audit) {
                $jumlah = $perangkat->tokens()->delete();
                $audit->catat('antrean.token_display_dicabut', null, $perangkat->getTable(), $perangkat->id, ['kode' => $perangkat->kode, 'jumlah' => $jumlah]);

                return $jumlah;
            });
            $this->info("Token dicabut: {$jumlah}.");

            return self::SUCCESS;
        }

        $token = DB::transaction(function () use ($kode, $area, $audit) {
            $perangkat = DisplayAntrean::query()->where('kode', $kode)->first() ?? new DisplayAntrean;
            $perangkat->forceFill(['kode' => $kode, 'area' => $area, 'status' => 'aktif'])->save();
            $perangkat->tokens()->delete();
            $token = $perangkat->createToken('display', [DisplayAntrean::KEMAMPUAN])->plainTextToken;
            $audit->catat('antrean.token_display_dibuat', null, $perangkat->getTable(), $perangkat->id, ['kode' => $kode, 'area' => $area]);

            return $token;
        });
        $this->line($token);
        $this->info('Simpan token ini di perangkat display (header Authorization: Bearer). Token tidak bisa ditampilkan lagi.');

        return self::SUCCESS;
    }
}
