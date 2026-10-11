<?php

namespace App\Modules\Akses\Http\Resources;

use App\Modules\Akses\Application\PenjagaPenyunting;
use App\Modules\Akses\Database\MenuBawaan;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Peran;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PeranRinci (kontrak): ringkas + versi (= ETag), kode izin, dan apakah penyunting boleh mengubahnya.
 *
 * @mixin Peran
 */
final class PeranRinciResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $peran = Peran::query()->denganRingkasan()->findOrFail($this->id);
        $izin = $peran->izin()->get(['akses.izin.kode', 'akses.izin.sensitif']);
        $alasanTerkunci = match (true) {
            $peran->bawaan_sistem => 'peran_bawaan_terkunci',
            app(PenjagaPenyunting::class)->memegangPeran($request->user(), $peran->id) => 'peran_sendiri',
            default => null,
        };

        return [
            ...(new PeranResource($peran))->toArray($request),
            'versi' => self::versi($peran),
            'izin' => $izin->pluck('kode')->sort()->values()->all(),
            'wajib_mfa_terkunci' => $peran->kode === MenuBawaan::PERAN_SUPERADMIN || $izin->contains(fn (Izin $i) => $i->sensitif),
            'dapat_disunting' => $alasanTerkunci === null,
            'alasan_terkunci' => $alasanTerkunci,
            'diubah_pada' => $peran->diubah_pada->toIso8601String(),
        ];
    }

    public static function versi(Peran $peran): string
    {
        return (string) $peran->revisi;
    }
}
