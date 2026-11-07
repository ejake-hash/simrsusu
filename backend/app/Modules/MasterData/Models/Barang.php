<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Katalog obat dan BMHP (tiket 2.11, `logistik.barang`).
 *
 * @property string $id
 * @property string $kode
 * @property string $nama
 * @property string $jenis
 * @property string|null $generik_paten
 * @property string|null $zat_aktif
 * @property string|null $kekuatan
 * @property string|null $sediaan
 * @property string $satuan_terkecil
 * @property string|null $kode_kfa
 * @property bool $kewaspadaan_tinggi
 * @property bool $narkotika
 * @property bool $lasa
 * @property string $status aktif | nonaktif
 * @property int $revisi
 * @property Carbon $diubah_pada
 */
final class Barang extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    public const JENIS = ['obat', 'bmhp', 'reagen', 'gizi', 'umum', 'implan'];

    public const ISIAN = ['kode', 'nama', 'jenis', 'generik_paten', 'zat_aktif', 'kekuatan', 'sediaan', 'satuan_terkecil', 'kode_kfa', 'kewaspadaan_tinggi', 'narkotika', 'lasa'];

    protected $table = 'logistik.barang';

    protected $fillable = [...self::ISIAN, 'status'];

    protected $attributes = ['revisi' => 0, 'status' => 'aktif', 'kewaspadaan_tinggi' => false, 'narkotika' => false, 'lasa' => false];

    protected function casts(): array
    {
        return ['kewaspadaan_tinggi' => 'boolean', 'narkotika' => 'boolean', 'lasa' => 'boolean', 'revisi' => 'integer'];
    }
}
