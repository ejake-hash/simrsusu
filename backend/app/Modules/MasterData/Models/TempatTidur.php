<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $kamar_id
 * @property string $lokasi_id
 * @property string $kode tetap sejak dibuat, unik selamanya
 * @property string $kelas_rawat_id
 * @property string $aturan_jenis_kelamin campur | laki_laki | perempuan
 * @property bool $isolasi
 * @property bool $ekstra
 * @property Carbon $berlaku_dari hari pertama dihitung
 * @property Carbon|null $berlaku_sampai hari terakhir dihitung
 * @property string|null $alasan_tutup
 * @property string|null $satusehat_lokasi_id
 * @property int $revisi
 * @property Carbon $diubah_pada
 */
final class TempatTidur extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'inti.tempat_tidur';

    protected $fillable = ['kamar_id', 'lokasi_id', 'kode', 'kelas_rawat_id', 'aturan_jenis_kelamin', 'isolasi', 'ekstra', 'berlaku_dari', 'berlaku_sampai', 'alasan_tutup'];

    protected $attributes = ['revisi' => 0];

    protected function casts(): array
    {
        return ['berlaku_dari' => 'date', 'berlaku_sampai' => 'date', 'isolasi' => 'boolean', 'ekstra' => 'boolean', 'revisi' => 'integer'];
    }
}
