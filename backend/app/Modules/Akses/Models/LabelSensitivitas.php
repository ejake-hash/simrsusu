<?php

namespace App\Modules\Akses\Models;

use App\Modules\Akses\Application\CacheHakAkses;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Label sensitivitas data (lapis 4; tiket 1.5, PRD 9.2.2). Data induk; penyetuju (pejabat PDP) ditetapkan RS.
 *
 * @property string $id
 * @property string $kode
 * @property string $nama
 * @property string|null $uraian
 * @property bool $butuh_relasi_perawatan
 * @property string|null $peran_penyetuju_id
 * @property bool $aktif
 */
final class LabelSensitivitas extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'akses.label_sensitivitas';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // Status aktif dan butuh_relasi_perawatan ikut di cache hak akses semua akun pemegang label.
        self::saved(fn () => app(CacheHakAkses::class)->lupakanSemua());
    }

    protected function casts(): array
    {
        return ['butuh_relasi_perawatan' => 'boolean', 'aktif' => 'boolean'];
    }
}
