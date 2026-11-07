<?php

namespace App\Modules\Antrean\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Nomor antrean satu titik (PRD 7.02.1; tiket 2.8). Tidak dihapus: batal = status beserta alasan dan pelaku.
 *
 * @property string $id
 * @property Carbon $tanggal
 * @property string|null $kunjungan_id
 * @property string $titik
 * @property string $area
 * @property string $prefiks
 * @property int $nomor
 * @property string $nomor_tampil
 * @property string|null $poli_id
 * @property string|null $pegawai_id
 * @property string|null $ruang_id
 * @property string $sumber
 * @property bool $prioritas
 * @property string $status
 * @property string $urutan numeric, dibiarkan string agar presisi tidak hilang
 * @property int $panggilan_ke
 * @property Carbon|null $dipanggil_pada
 * @property string|null $alasan_batal
 * @property string|null $dibatalkan_oleh
 * @property Carbon|null $dibatalkan_pada
 * @property int $revisi
 * @property Carbon $dibuat_pada
 * @property Carbon $diubah_pada
 */
final class Antrean extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    public const STATUS = ['belum_check_in', 'menunggu', 'dipanggil', 'dilayani', 'dilewati', 'kembali', 'selesai', 'batal'];

    protected $table = 'kunjungan.antrean';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'menunggu', 'sumber' => 'loket', 'prioritas' => false, 'panggilan_ke' => 0, 'revisi' => 0];

    protected function casts(): array
    {
        return ['tanggal' => 'date', 'nomor' => 'integer', 'prioritas' => 'boolean', 'panggilan_ke' => 'integer',
            'dipanggil_pada' => 'datetime', 'dibatalkan_pada' => 'datetime', 'revisi' => 'integer'];
    }
}
