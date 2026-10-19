<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $kode
 * @property string $nama
 * @property string $jenis
 * @property string $status aktif | nonaktif
 * @property int|null $masa_berlaku_gl_hari
 * @property string|null $plafon rupiah desimal (string, bukan float)
 * @property int|null $jatuh_tempo_hari
 * @property int $revisi
 * @property Carbon $diubah_pada
 */
final class Penjamin extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    public const JENIS = ['bpjs', 'umum', 'asuransi', 'perusahaan', 'jasa_raharja', 'jamkesda', 'sponsor_riset'];

    protected $table = 'inti.penjamin';

    protected $fillable = ['kode', 'nama', 'jenis', 'status', 'masa_berlaku_gl_hari', 'plafon', 'jatuh_tempo_hari'];

    protected $attributes = ['revisi' => 0];

    protected function casts(): array
    {
        return ['masa_berlaku_gl_hari' => 'integer', 'jatuh_tempo_hari' => 'integer', 'revisi' => 'integer'];
    }
}
