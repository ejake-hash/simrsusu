<?php

namespace App\Modules\Akses\Http\Resources;

use App\Modules\Akses\Models\Peran;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Peran */
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
        ];
    }
}
