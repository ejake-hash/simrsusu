<?php

namespace App\Modules\Ttd\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Kredensial tanda tangan (tiket 1.6). Kolom rahasia disembunyikan dari serialisasi.
 *
 * @property string $id
 * @property string $pengguna_id
 * @property string $tingkat internal | psre
 * @property string $penyedia
 * @property string|null $kunci_publik base64
 * @property string|null $kunci_privat_terbungkus
 * @property string|null $garam
 * @property array{opslimit: int, memlimit: int, alg: int, kunci?: string}|null $parameter_kdf kunci = id kunci server
 * @property string|null $pin_hash
 * @property int $percobaan_gagal
 * @property Carbon|null $terkunci_sampai
 * @property string $status aktif | dicabut
 * @property Carbon|null $diaktifkan_pada
 * @property Carbon|null $dicabut_pada
 * @property int $revisi
 */
final class Kredensial extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'ttd.kredensial';

    protected $guarded = ['id'];

    protected $hidden = ['kunci_privat_terbungkus', 'garam', 'parameter_kdf', 'pin_hash'];

    protected $attributes = ['tingkat' => 'internal', 'penyedia' => 'internal', 'status' => 'aktif', 'percobaan_gagal' => 0, 'revisi' => 0];

    protected function casts(): array
    {
        return ['parameter_kdf' => 'array', 'percobaan_gagal' => 'integer', 'terkunci_sampai' => 'datetime', 'diaktifkan_pada' => 'datetime',
            'dicabut_pada' => 'datetime'];
    }

    public function terkunci(): bool
    {
        return $this->terkunci_sampai !== null && $this->terkunci_sampai->isFuture();
    }
}
