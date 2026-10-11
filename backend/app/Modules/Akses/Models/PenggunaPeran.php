<?php

namespace App\Modules\Akses\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penugasan peran ke akun (FR-09-04).
 *
 * @property string $peran_id
 * @property-read Peran $peran
 */
final class PenggunaPeran extends PemberianAkses
{
    protected $table = 'akses.pengguna_peran';

    protected $fillable = [
        'pengguna_id', 'peran_id', 'cakupan_jenis', 'cakupan_id', 'berlaku_dari', 'berlaku_sampai',
        'dasar_pemberian', 'alasan', 'diberikan_oleh', 'disetujui_oleh',
    ];

    /** @return BelongsTo<Peran, $this> */
    public function peran(): BelongsTo
    {
        return $this->belongsTo(Peran::class);
    }
}
