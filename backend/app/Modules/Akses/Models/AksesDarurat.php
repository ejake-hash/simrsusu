<?php

namespace App\Modules\Akses\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Akses darurat (break-glass) RM satu pasien (tiket 2.21, UC-33). Berakhir sendiri pada berakhir_pada; tinjauan sekali tulis.
 *
 * @property string $id
 * @property string $pengguna_id
 * @property string $pasien_id
 * @property string $kategori_alasan
 * @property string $alasan
 * @property Carbon $mulai_pada
 * @property Carbon $berakhir_pada
 * @property string|null $hasil_tinjauan
 * @property string|null $catatan_tinjauan
 * @property string|null $ditinjau_oleh
 * @property Carbon|null $ditinjau_pada
 */
final class AksesDarurat extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'akses.akses_darurat';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['mulai_pada' => 'datetime', 'berakhir_pada' => 'datetime', 'ditinjau_pada' => 'datetime'];
    }

    public function aktif(): bool
    {
        return $this->mulai_pada->lessThanOrEqualTo(now()) && $this->berakhir_pada->greaterThan(now());
    }
}
