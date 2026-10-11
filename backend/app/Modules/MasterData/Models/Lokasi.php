<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $induk_id
 * @property string $jenis situs | gedung | sayap | lantai | ruang | tempat_tidur
 * @property string $kode
 * @property string $kode_lengkap
 * @property string $nama
 * @property string|null $fungsi
 * @property string|null $unit_organisasi_id
 * @property string $status aktif | renovasi | ditutup
 * @property Carbon $status_berlaku_dari
 * @property string|null $satusehat_lokasi_id
 * @property int $revisi
 * @property Carbon $diubah_pada
 */
final class Lokasi extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    public const JENIS = ['situs', 'gedung', 'sayap', 'lantai', 'ruang', 'tempat_tidur'];

    /** Jenis anak yang boleh per jenis induk (kontrak v0.5.0); kunci '' = akar. */
    public const ANAK = [
        '' => ['situs', 'gedung'],
        'situs' => ['gedung'],
        'gedung' => ['sayap', 'lantai'],
        'sayap' => ['lantai'],
        'lantai' => ['ruang'],
        'ruang' => ['tempat_tidur'],
        'tempat_tidur' => [],
    ];

    public const FUNGSI = ['rawat_inap', 'rawat_jalan', 'igd', 'kamar_operasi', 'intensif', 'penunjang', 'administrasi', 'gudang', 'lainnya'];

    public const STATUS = ['aktif', 'renovasi', 'ditutup'];

    public static function bolehAnak(?string $jenisInduk, string $jenisAnak): bool
    {
        return in_array($jenisAnak, self::ANAK[$jenisInduk ?? ''] ?? [], true);
    }

    protected $table = 'inti.lokasi';

    protected $fillable = ['induk_id', 'jenis', 'kode', 'kode_lengkap', 'nama', 'fungsi', 'unit_organisasi_id', 'status', 'status_berlaku_dari'];

    protected $attributes = ['revisi' => 0];

    protected function casts(): array
    {
        return ['status_berlaku_dari' => 'date', 'revisi' => 'integer'];
    }
}
