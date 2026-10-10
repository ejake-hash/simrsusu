<?php

namespace App\Modules\Akses\Http\Resources;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Pengguna */
final class PenggunaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nama_pengguna' => $this->nama_pengguna,
            'surel' => $this->surel,
            'login_terakhir_pada' => $this->login_terakhir_pada?->toIso8601String(),
            'pegawai' => app(RingkasanPegawai::class)->untuk($this->pegawai_id),
        ];
    }
}
