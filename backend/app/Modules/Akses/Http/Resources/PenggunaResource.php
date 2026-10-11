<?php

namespace App\Modules\Akses\Http\Resources;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Akun yang sedang masuk (kontrak skema Saya): GET /saya dan `pengguna` di respons masuk (PRD 10.3.2).
 * Tidak mengirim email, NIP, atau data rahasia: tidak dipakai layar.
 *
 * @mixin Pengguna
 */
final class PenggunaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $peran = PenggunaPeran::query()->berlaku()->where('pengguna_id', $this->id)
            ->with('peran:id,nama')->get()
            ->pluck('peran')->unique('id')->sortBy('nama')
            ->map(fn ($p) => ['id' => $p->id, 'nama' => $p->nama])->values()->all();

        return [
            'id' => $this->id,
            'nama_pengguna' => $this->nama_pengguna,
            'login_terakhir_pada' => $this->login_terakhir_pada?->toIso8601String(),
            'mfa_aktif' => $this->mfaAktif(),
            'pegawai' => app(RingkasanPegawai::class)->untuk($this->pegawai_id),
            'peran' => $peran,
            'masuk_sebelumnya_pada' => $this->masuk_sebelumnya_pada?->toIso8601String(),
            'masuk_sebelumnya_dari_jaringan_rs' => $this->masuk_sebelumnya_pada === null ? null : $this->masuk_sebelumnya_dari_jaringan_rs,
            'instalasi_demo' => (bool) config('instalasi.demo'),
        ];
    }
}
