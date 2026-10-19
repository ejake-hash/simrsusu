<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $jenis cuti | libur
 * @property string|null $pegawai_id
 * @property Carbon $dari_tanggal
 * @property Carbon $sampai_tanggal
 * @property string $alasan
 * @property string $status aktif | batal
 * @property string|null $alasan_batal
 * @property int $revisi
 * @property Carbon $diubah_pada
 */
final class JadwalPengecualian extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'kunjungan.jadwal_pengecualian';

    protected $fillable = ['jenis', 'pegawai_id', 'dari_tanggal', 'sampai_tanggal', 'alasan', 'status', 'alasan_batal'];

    protected $attributes = ['status' => 'aktif', 'revisi' => 0];

    protected function casts(): array
    {
        return ['dari_tanggal' => 'date', 'sampai_tanggal' => 'date', 'revisi' => 'integer'];
    }
}
