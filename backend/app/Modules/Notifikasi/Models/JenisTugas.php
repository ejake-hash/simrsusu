<?php

namespace App\Modules\Notifikasi\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Jenis tugas dan aturan tenggat/eskalasi (tiket 1.7, FR-53-04).
 *
 * @property string $id
 * @property string $kode
 * @property string $nama
 * @property string|null $uraian
 * @property string $modul
 * @property string $objek_jenis
 * @property string $tautan_layar rute SPA dengan {objek_id} (dan parameter lain)
 * @property string $tenggat_dari dibuat | objek
 * @property int $batas_menit
 * @property string|null $unit_teguran_id
 * @property string|null $unit_eskalasi_id
 * @property int $pengingat_menit
 * @property int $teguran_menit
 * @property int $eskalasi_menit
 * @property bool $kritis
 * @property bool $aktif
 * @property int $revisi
 * @property Carbon $diubah_pada
 */
final class JenisTugas extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'inti.jenis_tugas';

    protected $fillable = ['nama', 'batas_menit', 'unit_teguran_id', 'unit_eskalasi_id', 'pengingat_menit', 'teguran_menit', 'eskalasi_menit', 'kritis', 'aktif'];

    protected function casts(): array
    {
        return ['batas_menit' => 'integer', 'pengingat_menit' => 'integer', 'teguran_menit' => 'integer', 'eskalasi_menit' => 'integer',
            'kritis' => 'boolean', 'aktif' => 'boolean', 'revisi' => 'integer'];
    }
}
