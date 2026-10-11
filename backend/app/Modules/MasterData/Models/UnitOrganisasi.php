<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $induk_id
 * @property string $kode
 * @property string $nama
 * @property string $jenis instalasi | bidang | bagian | ruang | poli | depo
 * @property string $status aktif | nonaktif
 * @property Carbon $berlaku_dari
 * @property int $revisi
 * @property Carbon $diubah_pada
 */
final class UnitOrganisasi extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    public const JENIS = ['instalasi', 'bidang', 'bagian', 'ruang', 'poli', 'depo'];

    protected $table = 'inti.unit_organisasi';

    protected $fillable = ['induk_id', 'kode', 'nama', 'jenis', 'status', 'berlaku_dari'];

    protected $attributes = ['status' => 'aktif', 'revisi' => 0];

    protected function casts(): array
    {
        return ['berlaku_dari' => 'date', 'revisi' => 'integer'];
    }
}
