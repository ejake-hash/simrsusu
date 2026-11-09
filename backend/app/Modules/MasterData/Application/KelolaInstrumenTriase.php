<?php

namespace App\Modules\MasterData\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\SuntinganBerversi;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\InstrumenTriase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Data induk instrumen triase (tiket 2.24, kontrak v0.32.0). Pola penjamin (2.1): edit langsung oleh pemegang izin
 * master_data.instrumen_triase.*, setiap perubahan butuh dasar dan tercatat di audit (sebelum/sesudah). Tanpa DELETE
 * (status nonaktif). Versi = revisi (412 bila berubah). Kode ganda → 422 pada field `kode`. Isi definisi instrumen
 * bukan data pasien, jadi boleh utuh di audit.
 */
final class KelolaInstrumenTriase
{
    public function __construct(private readonly CatatAktivitas $audit) {}

    /** @param  array<string, mixed>  $isi  sudah divalidasi bentuknya */
    public function buat(Pengguna $penyunting, array $isi): InstrumenTriase
    {
        $instrumen = new InstrumenTriase(['kode' => $isi['kode'], 'nama' => $isi['nama'], 'skala' => self::skala($isi['skala'] ?? []), 'status' => 'aktif']);

        return $this->tangkapKodeGanda(fn () => DB::transaction(function () use ($penyunting, $instrumen, $isi) {
            $instrumen->forceFill(['dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('master_data.instrumen_triase_dibuat', $penyunting->id, $instrumen->getTable(), $instrumen->id,
                [...$this->isiAudit($instrumen->refresh()), 'dasar_perubahan' => $isi['dasar_perubahan']]);

            return $instrumen;
        }));
    }

    /** @param  array<string, mixed>  $isi  sudah divalidasi bentuknya */
    public function ubah(Pengguna $penyunting, InstrumenTriase $instrumen, string $versi, array $isi): InstrumenTriase
    {
        return $this->tangkapKodeGanda(fn () => DB::transaction(function () use ($penyunting, $instrumen, $versi, $isi) {
            SuntinganBerversi::naikkan(InstrumenTriase::class, $instrumen->id, $versi, $penyunting->id, 'Instrumen triase');
            $instrumen->refresh();
            $sebelum = $this->isiAudit($instrumen);
            $ubahan = array_intersect_key($isi, array_flip(['kode', 'nama', 'status']));
            if (array_key_exists('skala', $isi)) {
                $ubahan['skala'] = self::skala($isi['skala'] ?? []);
            }
            $instrumen->fill($ubahan)->forceFill(['diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('master_data.instrumen_triase_diubah', $penyunting->id, $instrumen->getTable(), $instrumen->id,
                [...$this->isiAudit($instrumen->refresh()), 'dasar_perubahan' => $isi['dasar_perubahan']], $sebelum);

            return $instrumen;
        }));
    }

    /**
     * Hanya kode dan label, label dirapikan; urutan isian = urutan tampil.
     *
     * @param  array<int, array<string, mixed>>  $skala
     * @return list<array{kode: string, label: string}>
     */
    private static function skala(array $skala): array
    {
        return array_values(array_map(fn (array $s) => ['kode' => (string) $s['kode'], 'label' => trim((string) $s['label'])], $skala));
    }

    /** @return array<string, mixed> */
    private function isiAudit(InstrumenTriase $instrumen): array
    {
        return ['kode' => $instrumen->kode, 'nama' => $instrumen->nama, 'skala' => $instrumen->skala, 'status' => $instrumen->status];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $kerja
     * @return T
     */
    private function tangkapKodeGanda(callable $kerja): mixed
    {
        try {
            return $kerja();
        } catch (QueryException $e) {
            throw $e->getCode() === '23505'
                ? ValidationException::withMessages(['kode' => 'Kode sudah dipakai instrumen lain.'])
                : $e;
        }
    }
}
