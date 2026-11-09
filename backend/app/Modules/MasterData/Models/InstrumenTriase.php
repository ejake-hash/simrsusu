<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Instrumen triase yang disetujui RS (tiket 2.24, FR-03-02; aturan Tahap C: nilai klinis hanya dari data induk). Tidak
 * dihapus: nonaktif = tidak bisa dipakai triase baru, triase lama tetap terbaca.
 *
 * @property string $id
 * @property string $kode
 * @property string $nama
 * @property list<array{kode: string, label: string}> $skala urut tampil; [] = tanpa skala
 * @property string $status aktif | nonaktif
 * @property int $revisi
 * @property Carbon $diubah_pada
 */
final class InstrumenTriase extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'inti.instrumen_triase';

    protected $fillable = ['kode', 'nama', 'skala', 'status'];

    protected $attributes = ['revisi' => 0, 'skala' => '[]', 'status' => 'aktif'];

    protected function casts(): array
    {
        return ['skala' => 'array', 'revisi' => 'integer'];
    }
}
