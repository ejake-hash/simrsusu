<?php

namespace App\Modules\Sdm\Models;

use App\Modules\Sdm\Database\Factories\PegawaiFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Pegawai extends Model
{
    /** @use HasFactory<PegawaiFactory> */
    use HasFactory, HasUuids;

    public const CREATED_AT = 'dibuat_pada';

    public const UPDATED_AT = 'diubah_pada';

    protected $table = 'sdm.pegawai';

    protected $fillable = ['nip', 'nama'];

    protected static function newFactory(): PegawaiFactory
    {
        return PegawaiFactory::new();
    }
}
