<?php

namespace App\Modules\Akses\Http\Resources;

use App\Modules\Akses\Models\Peran;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PeranRingkas (kontrak). Query sumbernya memakai Peran::denganRingkasan() agar jumlah_akun dan
 * memuat_izin_sensitif tidak dihitung per baris.
 *
 * @mixin Peran
 */
final class PeranResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kode' => $this->kode,
            'nama' => $this->nama,
            'uraian' => $this->uraian,
            'bawaan_sistem' => $this->bawaan_sistem,
            'wajib_mfa' => $this->wajib_mfa,
            'status' => $this->status,
            'jumlah_akun' => (int) $this->getAttribute('jumlah_akun'),
            'memuat_izin_sensitif' => (bool) $this->getAttribute('memuat_izin_sensitif'),
        ];
    }
}
