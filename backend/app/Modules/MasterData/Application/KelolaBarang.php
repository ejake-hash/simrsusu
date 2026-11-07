<?php

namespace App\Modules\MasterData\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\SuntinganBerversi;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\Barang;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Katalog barang (tiket 2.11, kontrak v0.23.0). Diisi langsung juga sesudah siap melayani (yang lewat usulan hanya
 * formularium). Tanpa DELETE: status nonaktif. Kode ganda → 422 field `kode`; `jenis` terkunci bila barang ada di formularium.
 */
final class KelolaBarang
{
    public function __construct(private readonly CatatAktivitas $audit) {}

    /** @param  array<string, mixed>  $isi */
    public function buat(Pengguna $penyunting, array $isi): Barang
    {
        return self::tangkapKodeGanda(fn () => DB::transaction(function () use ($penyunting, $isi) {
            $barang = new Barang(array_intersect_key($isi, array_flip(Barang::ISIAN)));
            $barang->forceFill(['dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('master_data.barang_dibuat', $penyunting->id, $barang->getTable(), $barang->id,
                [...$this->isiAudit($barang->refresh()), 'dasar_perubahan' => $isi['dasar_perubahan']]);

            return $barang;
        }));
    }

    /** @param  array<string, mixed>  $isi */
    public function ubah(Pengguna $penyunting, Barang $barang, string $versi, array $isi): Barang
    {
        return self::tangkapKodeGanda(fn () => DB::transaction(function () use ($penyunting, $barang, $versi, $isi) {
            SuntinganBerversi::naikkan(Barang::class, $barang->id, $versi, $penyunting->id, 'Barang');
            $barang->refresh();
            $sebelum = $this->isiAudit($barang);
            $barang->fill(array_intersect_key($isi, array_flip([...Barang::ISIAN, 'status'])));
            if ($barang->isDirty('jenis') && DB::table('farmasi.formularium_item')->where('barang_id', $barang->id)->exists()) {
                throw ValidationException::withMessages(['jenis' => 'Jenis barang tidak dapat diubah karena barang sudah ada di formularium.']);
            }
            if ($barang->jenis === 'obat' && $barang->generik_paten === null) {
                throw ValidationException::withMessages(['generik_paten' => 'Generik atau paten wajib diisi untuk obat.']);
            }
            $barang->forceFill(['diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('master_data.barang_diubah', $penyunting->id, $barang->getTable(), $barang->id,
                [...$this->isiAudit($barang->refresh()), 'dasar_perubahan' => $isi['dasar_perubahan']], $sebelum);

            return $barang;
        }));
    }

    /** @return array<string, mixed> */
    private function isiAudit(Barang $barang): array
    {
        return array_diff_key($barang->attributesToArray(), array_flip(['id', 'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'revisi']));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $kerja
     * @return T
     */
    public static function tangkapKodeGanda(callable $kerja, string $pesan = 'Kode sudah dipakai barang lain.'): mixed
    {
        try {
            return $kerja();
        } catch (QueryException $e) {
            throw $e->getCode() === '23505' ? ValidationException::withMessages(['kode' => $pesan]) : $e;
        }
    }
}
