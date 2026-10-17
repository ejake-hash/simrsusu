<?php

namespace App\Modules\Akses\Http\Resources;

use App\Modules\Akses\Application\KelolaPerangkat;
use App\Modules\Akses\Application\PenjagaPenyunting;
use App\Modules\Akses\Database\MenuBawaan;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Peran;
use App\Modules\Akses\Models\PersetujuanAkses;
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
            // Perangkat tepercaya (tiket 1.13).
            'masa_percaya_hari' => $peran->masa_percaya_hari,
            'batas_masa_percaya_hari' => KelolaPerangkat::batasPeran($peran, $izin->contains(fn (Izin $i) => $i->sensitif)),
            'masa_percaya_instalasi_hari' => app(KelolaPerangkat::class)->hariInstalasi(),
            'izin_menunggu' => self::izinMenunggu($peran),
            'dapat_disunting' => $alasanTerkunci === null,
            'alasan_terkunci' => $alasanTerkunci,
            'diubah_pada' => $peran->diubah_pada->toIso8601String(),
        ];
    }

    /**
     * Izin sensitif yang menunggu persetujuan untuk peran ini (tiket 1.2d); belum termasuk di `izin`.
     *
     * @return list<array{kode: string, persetujuan_id: string, penyetuju_peran: array{id: string, kode: string, nama: string}, kedaluwarsa_pada: string}>
     */
    private static function izinMenunggu(Peran $peran): array
    {
        return PersetujuanAkses::query()->menunggu()->where('objek_jenis', 'izin_peran')->where('peran_id', $peran->id)
            ->with(['izin:id,kode', 'penyetujuPeran:id,kode,nama'])->orderBy('id')->get()
            ->map(fn (PersetujuanAkses $p) => [
                'kode' => (string) $p->izin?->kode,
                'persetujuan_id' => $p->id,
                'penyetuju_peran' => ['id' => $p->penyetujuPeran->id, 'kode' => $p->penyetujuPeran->kode, 'nama' => $p->penyetujuPeran->nama],
                'kedaluwarsa_pada' => $p->kedaluwarsa_pada->toIso8601String(),
            ])->sortBy('kode')->values()->all();
    }

    public static function versi(Peran $peran): string
    {
        return (string) $peran->revisi;
    }
}
