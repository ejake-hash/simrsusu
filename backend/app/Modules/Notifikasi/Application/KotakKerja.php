<?php

namespace App\Modules\Notifikasi\Application;

use App\Modules\Notifikasi\Models\JenisTugas;
use App\Modules\Notifikasi\Models\Tugas;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mesin tugas (tiket 1.7, FR-53-01…07). Tugas dibuat dan diselesaikan oleh kejadian domain (bukan tombol), satu tugas
 * terbuka per jenis + objek + pelaksana (idempoten). Eskalasi bertingkat dari waktu yang dihitung saat tugas dibuat.
 * Dipanggil di dalam transaksi pemanggil: tugas dan kejadian domainnya berhasil atau gagal bersama.
 */
final class KotakKerja
{
    private const PRIORITAS = [0 => 'biasa', 1 => 'biasa', 2 => 'penting', 3 => 'penting'];

    public function __construct(
        private readonly PenerimaTugas $penerima,
        private readonly Pemberitahu $pemberitahu,
    ) {}

    /**
     * Buat tugas bila jenisnya aktif dan belum ada tugas terbuka yang sama.
     *
     * @param  array{0: string, 1: string}  $pelaksana  [pengguna|peran|unit, id]
     * @param  array<string, string>  $parameterTautan  pengganti {kunci} di tautan_layar selain {objek_id}
     * @param  Carbon|null  $tanggalObjek  untuk jenis bertenggat objek (mis. tanggal izin berakhir)
     */
    public function buat(string $kode, string $objekId, string $judul, array $pelaksana, ?string $unitId = null,
        array $parameterTautan = [], ?Carbon $tanggalObjek = null): ?Tugas
    {
        $jenis = JenisTugas::query()->where('kode', $kode)->where('aktif', true)->first();
        if ($jenis === null) {
            return null;
        }
        $terbuka = fn () => Tugas::query()->where('jenis_tugas_id', $jenis->id)->where('objek_jenis', $jenis->objek_jenis)->where('objek_id', $objekId)
            ->where('pelaksana_jenis', $pelaksana[0])->where('pelaksana_id', $pelaksana[1])->where('status', 'terbuka')->first();
        $ada = $terbuka();
        if ($ada !== null) {
            return $ada;
        }

        $sekarang = now();
        $tenggat = $jenis->tenggat_dari === 'objek' && $tanggalObjek !== null ? $tanggalObjek->copy() : $sekarang->copy()->addMinutes($jenis->batas_menit);
        $tautan = str_replace(['{objek_id}', ...array_map(fn ($k) => '{'.$k.'}', array_keys($parameterTautan))],
            [$objekId, ...array_values($parameterTautan)], $jenis->tautan_layar);
        $tugas = new Tugas([
            'jenis_tugas_id' => $jenis->id, 'objek_jenis' => $jenis->objek_jenis, 'objek_id' => $objekId, 'judul' => $judul, 'tautan' => $tautan,
            'pelaksana_jenis' => $pelaksana[0], 'pelaksana_id' => $pelaksana[1], 'unit_id' => $unitId ?? $jenis->unit_teguran_id,
            'unit_eskalasi_id' => $jenis->unit_eskalasi_id,
            'tenggat_pada' => $tenggat, 'pengingat_pada' => $tenggat->copy()->addMinutes($jenis->pengingat_menit),
            'teguran_pada' => $tenggat->copy()->addMinutes($jenis->teguran_menit), 'eskalasi_pada' => $tenggat->copy()->addMinutes($jenis->eskalasi_menit),
            'kritis' => $jenis->kritis,
        ]);
        try {
            // Savepoint: balapan dua kejadian yang sama ditangkap indeks unik tanpa membatalkan transaksi pemanggil.
            DB::transaction(fn () => $tugas->save());
        } catch (UniqueConstraintViolationException) {
            return $terbuka();
        }
        $penerima = $this->penerima->pelaksana(...$pelaksana);
        $kanal = $this->pemberitahu->kirim($penerima, $jenis->kode, $judul, 'Tugas baru: '.$judul.'. Tenggat '.$this->waktu($tenggat).'.',
            $tautan, $tugas->kritis ? 'kritis' : 'biasa', $tugas->id);
        $this->kejadian($tugas, 'dibuat', 0, $penerima, $kanal, null);
        // Pengingat yang waktunya sudah lewat saat dibuat (tenggat dekat) dikirim sekarang.
        $this->naikkan($tugas, $jenis);

        return $tugas;
    }

    /** Selesaikan semua tugas terbuka jenis itu untuk objek itu (kejadian penyelesai). @return int jumlah */
    public function selesaikan(string $kode, string $objekId, string $alasan, string $status = 'selesai'): int
    {
        $jumlah = 0;
        $tugas = Tugas::query()->whereIn('jenis_tugas_id', JenisTugas::query()->where('kode', $kode)->select('id'))
            ->where('objek_id', $objekId)->where('status', 'terbuka')->lockForUpdate()->get();
        foreach ($tugas as $t) {
            $t->forceFill(['status' => $status, 'selesai_pada' => now(), 'alasan_selesai' => $alasan])->save();
            $this->kejadian($t, $status === 'batal' ? 'batal' : 'selesai', $t->tingkat_saat_ini, [], [], $alasan);
            $jumlah++;
        }

        return $jumlah;
    }

    /** Tugas terjadwal: naikkan tingkat tugas yang waktunya tercapai. Aman diulang. @return int tugas yang naik */
    public function prosesEskalasi(): int
    {
        $sekarang = now()->format('Y-m-d H:i:s.uP');
        $id = Tugas::query()->where('status', 'terbuka')->where(fn ($q) => $q
            ->where(fn ($q) => $q->where('tingkat_saat_ini', '<', 1)->where('pengingat_pada', '<=', $sekarang))
            ->orWhere(fn ($q) => $q->where('tingkat_saat_ini', '<', 2)->where('teguran_pada', '<=', $sekarang))
            ->orWhere(fn ($q) => $q->where('tingkat_saat_ini', '<', 3)->where('eskalasi_pada', '<=', $sekarang)))
            ->orderBy('tenggat_pada')->limit(500)->pluck('id');
        $naik = 0;
        foreach ($id as $tugasId) {
            try {
                $naik += DB::transaction(function () use ($tugasId) {
                    $tugas = Tugas::query()->whereKey($tugasId)->where('status', 'terbuka')->lockForUpdate()->first();

                    return $tugas !== null && $this->naikkan($tugas, $tugas->jenis) ? 1 : 0;
                });
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $naik;
    }

    /** Kirim setiap tingkat yang waktunya sudah tercapai, berurutan. */
    private function naikkan(Tugas $tugas, JenisTugas $jenis): bool
    {
        $berubah = false;
        foreach ([1 => [$tugas->pengingat_pada, 'pengingat'], 2 => [$tugas->teguran_pada, 'teguran'], 3 => [$tugas->eskalasi_pada, 'eskalasi']] as $tingkat => [$waktu, $jenisKejadian]) {
            if ($tugas->tingkat_saat_ini >= $tingkat || $waktu->isFuture()) {
                continue;
            }
            [$penerima, $catatan] = $this->penerimaTingkat($tugas, $jenis, $tingkat);
            $judul = match ($tingkat) {
                1 => 'Pengingat: '.$tugas->judul,
                2 => 'Teguran: '.$tugas->judul.' melewati tenggat',
                default => 'Eskalasi: '.$tugas->judul.' belum diselesaikan',
            };
            $kanal = $this->pemberitahu->kirim($penerima, $jenis->kode, $judul, $judul.'. Tenggat '.$this->waktu($tugas->tenggat_pada).'.',
                $tugas->tautan, $tugas->kritis ? 'kritis' : self::PRIORITAS[$tingkat], $tugas->id);
            $tugas->forceFill(['tingkat_saat_ini' => $tingkat])->save();
            $this->kejadian($tugas, $jenisKejadian, $tingkat, $penerima, $kanal, $catatan);
            $berubah = true;
        }

        return $berubah;
    }

    /** @return array{0: list<string>, 1: string|null} penerima dan catatan bila tanpa penerima */
    private function penerimaTingkat(Tugas $tugas, JenisTugas $jenis, int $tingkat): array
    {
        if ($tingkat === 1) {
            return [$this->penerima->pelaksana($tugas->pelaksana_jenis, $tugas->pelaksana_id), null];
        }
        $unit = $tingkat === 2 ? $tugas->unit_id : ($tugas->unit_eskalasi_id ?? $this->penerima->induk($tugas->unit_id));
        $penerima = $this->penerima->kepalaUnit($unit);

        return [$penerima, $penerima === [] ? ($unit === null ? 'Tanpa penerima: unit belum ditetapkan.' : 'Tanpa penerima: kepala unit belum ditetapkan.') : null];
    }

    /**
     * @param  list<string>  $penerima
     * @param  list<string>  $kanal
     */
    private function kejadian(Tugas $tugas, string $jenis, int $tingkat, array $penerima, array $kanal, ?string $catatan): void
    {
        DB::table('inti.tugas_kejadian')->insert([
            'id' => (string) Str::uuid7(), 'tugas_id' => $tugas->id, 'jenis' => $jenis, 'tingkat' => $tingkat, 'terjadi_pada' => now(),
            'penerima' => json_encode($penerima), 'kanal' => json_encode($kanal), 'catatan' => $catatan,
        ]);
    }

    private function waktu(Carbon $waktu): string
    {
        return $waktu->copy()->setTimezone((string) config('instalasi.zona_waktu'))->format('d-m-Y H:i');
    }
}
