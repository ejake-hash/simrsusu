<?php

namespace App\Modules\RekamMedis\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Catatan klinis (PRD 11.2 `klinis.catatan_klinis`). Tiket 2.13: asesmen awal perawat RJ; 2.14: CPPT (jenis soap), label, adendum. `isi` data klinis: jangan dilog.
 *
 * @property string $id
 * @property string $episode_id
 * @property string $kunjungan_id
 * @property string $unit_id
 * @property string $pasien_id
 * @property string $penulis_id
 * @property string $profesi
 * @property string $tatanan
 * @property string $jenis
 * @property array<string, mixed> $isi
 * @property string|null $label
 * @property string $status
 * @property Carbon|null $selesai_pada
 * @property Carbon|null $batas_perbaikan_pada
 * @property int $jumlah_versi
 * @property int $revisi
 * @property Carbon $dibuat_pada
 * @property Carbon $diubah_pada
 */
final class CatatanKlinis extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'klinis.catatan_klinis';

    protected $guarded = ['id'];

    protected $attributes = ['revisi' => 0, 'jumlah_versi' => 0, 'status' => 'draf', 'isi' => '{}'];

    protected function casts(): array
    {
        return ['isi' => 'array', 'selesai_pada' => 'datetime', 'batas_perbaikan_pada' => 'datetime', 'jumlah_versi' => 'integer', 'revisi' => 'integer'];
    }
}
