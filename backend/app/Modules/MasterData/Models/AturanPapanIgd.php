<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Batas waktu papan IGD (tiket 2.25; FR-03-04, FR-03-05, FR-00-06). Satu baris, dipasang migrasi (batas tanggap 5 menit
 * dari PRD, ambang mendekati 6 jam kosong) dan diubah RS lewat layar data induk.
 *
 * @property string $id
 * @property int $batas_tanggap_menit
 * @property int|null $peringatan_lama_igd_menit null = tanda mendekati 6 jam tidak dipakai
 * @property int $revisi
 * @property Carbon $diubah_pada
 */
final class AturanPapanIgd extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'inti.aturan_papan_igd';

    protected $fillable = ['batas_tanggap_menit', 'peringatan_lama_igd_menit'];

    protected function casts(): array
    {
        return ['batas_tanggap_menit' => 'integer', 'peringatan_lama_igd_menit' => 'integer', 'revisi' => 'integer'];
    }

    /** Baris tunggal dari migrasi; tidak pernah dihapus (tanpa DELETE di aplikasi). */
    public static function berlaku(): self
    {
        return self::query()->firstOrFail();
    }
}
