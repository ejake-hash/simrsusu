<?php

namespace App\Modules\Antrean\Console;

use App\Core\Audit\CatatAktivitas;
use App\Modules\Antrean\Models\Anjungan;
use App\Modules\MasterData\Models\Lokasi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Token perangkat anjungan (tiket 2.20) sampai halaman Perangkat Anjungan & Display (FR-01-23) dibangun; pola TokenDisplay.
 * Perangkat baru wajib --lokasi (kode lengkap lokasi aktif). Membuat token baru mencabut token lama perangkat itu dan
 * mengaktifkannya lagi; nilai token hanya tampil sekali dan tersimpan sebagai hash.
 */
final class TokenAnjungan extends Command
{
    protected $signature = 'antrean:token-anjungan {kode : Kode perangkat, mis. apm-lobi-1}
        {--lokasi= : Kode lengkap lokasi perangkat (wajib untuk perangkat baru)}
        {--cabut : Cabut semua token perangkat ini dan nonaktifkan perangkatnya}';

    protected $description = 'Membuat atau mencabut token perangkat anjungan';

    public function handle(CatatAktivitas $audit): int
    {
        $kode = (string) $this->argument('kode');
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $kode) !== 1) {
            $this->error("Kode tidak valid: {$kode} (huruf kecil, angka, - atau _; paling banyak 40).");

            return self::FAILURE;
        }
        $perangkat = Anjungan::query()->where('kode', $kode)->first();

        if ($this->option('cabut')) {
            if ($perangkat === null) {
                $this->error("Perangkat {$kode} tidak ditemukan.");

                return self::FAILURE;
            }
            $jumlah = DB::transaction(function () use ($perangkat, $audit) {
                $perangkat->forceFill(['status' => 'nonaktif'])->save();
                $jumlah = $perangkat->tokens()->delete();
                $audit->catat('antrean.token_anjungan_dicabut', null, $perangkat->getTable(), $perangkat->id, ['kode' => $perangkat->kode, 'jumlah' => $jumlah]);

                return $jumlah;
            });
            $this->info("Token dicabut: {$jumlah}; perangkat dinonaktifkan.");

            return self::SUCCESS;
        }

        $lokasiId = null;
        if ($this->option('lokasi') !== null) {
            $lokasiId = Lokasi::query()->where('kode_lengkap', (string) $this->option('lokasi'))->where('status', 'aktif')->value('id');
            if ($lokasiId === null) {
                $this->error('Lokasi tidak ditemukan atau tidak aktif.');

                return self::FAILURE;
            }
        } elseif ($perangkat === null) {
            $this->error('Perangkat baru: isi --lokasi=<kode lengkap lokasi>.');

            return self::FAILURE;
        }

        $token = DB::transaction(function () use ($kode, $perangkat, $lokasiId, $audit) {
            $perangkat ??= new Anjungan;
            $perangkat->forceFill(['kode' => $kode, 'lokasi_id' => $lokasiId ?? $perangkat->lokasi_id, 'status' => 'aktif'])->save();
            $perangkat->tokens()->delete();
            $token = $perangkat->createToken('anjungan', [Anjungan::KEMAMPUAN])->plainTextToken;
            $audit->catat('antrean.token_anjungan_dibuat', null, $perangkat->getTable(), $perangkat->id, ['kode' => $kode, 'lokasi_id' => $perangkat->lokasi_id]);

            return $token;
        });
        $this->line($token);
        $this->info('Simpan token ini di perangkat anjungan (header Authorization: Bearer). Token tidak bisa ditampilkan lagi.');

        return self::SUCCESS;
    }
}
