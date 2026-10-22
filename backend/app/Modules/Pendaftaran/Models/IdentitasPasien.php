<?php

namespace App\Modules\Pendaftaran\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * NIK, paspor, atau No. kartu BPJS pasien (tiket 2.5). Nilai terenkripsi; cari dan keunikan lewat sidik HMAC. Tidak pernah
 * ikut toArray()/log.
 *
 * @property string $id
 * @property string $pasien_id
 * @property string $jenis nik | paspor | kartu_bpjs
 * @property string|null $nilai_enkripsi sudah didekripsi oleh cast `encrypted`
 * @property string|null $sidik
 * @property int $revisi
 * @property Carbon $diubah_pada
 */
final class IdentitasPasien extends Model
{
    use HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    /** Field kontrak => jenis. */
    public const JENIS = ['nik' => 'nik', 'no_paspor' => 'paspor', 'no_kartu_bpjs' => 'kartu_bpjs'];

    protected $table = 'pasien.identitas';

    protected $fillable = ['pasien_id', 'jenis'];

    protected $hidden = ['nilai_enkripsi', 'sidik'];

    protected $attributes = ['revisi' => 0];

    protected function casts(): array
    {
        return ['nilai_enkripsi' => 'encrypted', 'revisi' => 'integer'];
    }

    public function aturNilai(?string $nilai): void
    {
        $this->nilai_enkripsi = $nilai;
        $this->sidik = $nilai === null ? null : self::sidik($this->jenis, $nilai);
    }

    /** HMAC dengan kunci turunan APP_KEY per jenis (rotasi APP_KEY = hitung ulang sidik). */
    public static function sidik(string $jenis, string $nilai): string
    {
        return hash_hmac('sha256', $nilai, hash_hmac('sha256', "pasien.identitas.$jenis", (string) config('app.key')));
    }
}
