<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Butir daftar periksa pulang rawat inap (tiket 2.40, FR-05-03). Data induk RS; tidak dihapus (nonaktif). Kode dan nama
 * disalin ke rencana pulang saat rencana dibuat, jadi perubahan hanya berlaku untuk rencana berikutnya.
 *
 * @property string $id
 * @property string $kode
 * @property string $nama
 * @property string $sumber manual | kejadian
 * @property bool $wajib
 * @property int $urutan
 * @property string $status aktif | nonaktif
 * @property int $revisi
 * @property Carbon $diubah_pada
 */
final class DaftarPeriksaPulang extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    /** Dicentang petugas di layar pemulangan. */
    public const SUMBER_MANUAL = 'manual';

    /** Diselesaikan kejadian modul lain (tagihan/jaminan oleh kasir, Tahap D); tidak menahan pulang. */
    public const SUMBER_KEJADIAN = 'kejadian';

    protected $table = 'inti.daftar_periksa_pulang';

    protected $fillable = ['nama', 'wajib', 'urutan', 'status'];

    protected $attributes = ['revisi' => 0, 'urutan' => 0, 'status' => 'aktif', 'wajib' => true, 'sumber' => self::SUMBER_MANUAL];

    protected function casts(): array
    {
        return ['wajib' => 'boolean', 'urutan' => 'integer', 'revisi' => 'integer'];
    }
}
