<?php

namespace App\Modules\Ttd\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tanda tangan satu objek oleh satu penanda tangan (tiket 1.6). Hanya INSERT.
 *
 * @property string $id
 * @property string $objek_jenis
 * @property string $objek_id
 * @property string|null $pengguna_id
 * @property string|null $kredensial_id
 * @property string $tingkat
 * @property string $metode
 * @property string $hash_isi
 * @property string $nilai_tanda_tangan
 * @property Carbon $cap_waktu
 * @property array<string, mixed> $bukti
 * @property Carbon $ditandatangani_pada
 */
final class TandaTangan extends Model
{
    use HasUuids;

    public $timestamps = false;

    /** Mikrodetik dipertahankan: cap_waktu ikut ditandatangani (IsiKanonik::pesan). */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $table = 'ttd.tanda_tangan';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cap_waktu' => 'datetime', 'ditandatangani_pada' => 'datetime', 'bukti' => 'array'];
    }
}
