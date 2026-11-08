<?php

namespace App\Modules\Akses\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Events\AksesDaruratDibuka;
use App\Modules\Akses\Events\AksesDaruratDitinjau;
use App\Modules\Akses\Models\AksesDarurat;
use App\Modules\Akses\Models\Pengguna;
use Illuminate\Support\Facades\DB;

/** Buka dan tinjau akses darurat (tiket 2.21, UC-33). Izin dan lapis 5 diperiksa di rute/controller. */
final class KelolaAksesDarurat
{
    public function __construct(private readonly CatatAktivitas $audit) {}

    /**
     * Akses aktif yang sudah ada untuk pasien yang sama dikembalikan apa adanya (tidak diperpanjang, alasan baru diabaikan).
     *
     * @return array{0: AksesDarurat, 1: bool} akses dan apakah baru
     */
    public function buka(Pengguna $pengguna, string $pasienId, string $kategori, string $alasan): array
    {
        return DB::transaction(function () use ($pengguna, $pasienId, $kategori, $alasan) {
            // Dua permintaan bersamaan dari akun yang sama tidak membuka dua akses.
            Pengguna::query()->whereKey($pengguna->id)->lockForUpdate()->first();
            $sekarang = now();
            $ada = AksesDarurat::query()->where('pengguna_id', $pengguna->id)->where('pasien_id', $pasienId)
                ->where('mulai_pada', '<=', $sekarang)->where('berakhir_pada', '>', $sekarang)->orderByDesc('berakhir_pada')->first();
            if ($ada !== null) {
                return [$ada, false];
            }

            $menit = (int) (DB::table('akses.kebijakan_keamanan')->value('akses_darurat_menit') ?? 240);
            $akses = AksesDarurat::query()->create([
                'pengguna_id' => $pengguna->id, 'pasien_id' => $pasienId, 'kategori_alasan' => $kategori, 'alasan' => $alasan,
                'mulai_pada' => $sekarang, 'berakhir_pada' => $sekarang->copy()->addMinutes($menit),
                'dibuat_oleh' => $pengguna->id, 'diubah_oleh' => $pengguna->id,
            ]);
            AksesDaruratDibuka::dispatch($akses->id);
            $this->audit->catat('akses.akses_darurat_dibuka', $pengguna->id, 'akses.akses_darurat', $akses->id);

            return [$akses, true];
        });
    }

    public function tinjau(Pengguna $peninjau, string $id, string $hasil, string $catatan): AksesDarurat
    {
        return DB::transaction(function () use ($peninjau, $id, $hasil, $catatan) {
            $akses = AksesDarurat::query()->whereKey($id)->lockForUpdate()->first()
                ?? throw new ApiError(404, 'tidak_ditemukan', 'Akses darurat tidak ditemukan.');
            if ($akses->pengguna_id === $peninjau->id) {
                throw new ApiError(403, 'diri_sendiri', 'Akses darurat milik sendiri ditinjau pejabat lain.');
            }
            if ($akses->hasil_tinjauan !== null) {
                throw new ApiError(409, 'sudah_ditinjau', 'Akses darurat ini sudah ditinjau; hasilnya tidak bisa diubah.');
            }
            $akses->forceFill(['hasil_tinjauan' => $hasil, 'catatan_tinjauan' => $catatan, 'ditinjau_oleh' => $peninjau->id,
                'ditinjau_pada' => now(), 'diubah_oleh' => $peninjau->id])->save();
            AksesDaruratDitinjau::dispatch($akses->id, $hasil);
            $this->audit->catat('akses.akses_darurat_ditinjau', $peninjau->id, 'akses.akses_darurat', $akses->id, ['hasil' => $hasil]);

            return $akses;
        });
    }
}
