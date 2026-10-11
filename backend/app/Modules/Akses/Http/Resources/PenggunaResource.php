<?php

namespace App\Modules\Akses\Http\Resources;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Akun yang sedang masuk (kontrak skema Saya): GET /saya dan `pengguna` di respons masuk (PRD 10.3.2).
 * Tidak mengirim email, NIP, atau data rahasia: tidak dipakai layar. Tanda instalasi demo bukan milik akun
 * (GET /profil-rs, tiket 1.8a).
 *
 * @mixin Pengguna
 */
final class PenggunaResource extends JsonResource
{
    /** Waktu masuk yang memulai sesi ini, disimpan di sesi saat masuk (PenyelesaiMasuk). */
    public const LOGIN_SESI_INI = 'autentikasi.login_pada';

    public function toArray(Request $request): array
    {
        $peran = PenggunaPeran::query()->berlaku()->where('pengguna_id', $this->id)
            ->whereHas('peran', fn ($p) => $p->where('status', 'aktif'))
            ->with('peran:id,nama')->get()
            ->pluck('peran')->unique('id')->sortBy('nama')
            ->map(fn ($p) => ['id' => $p->id, 'nama' => $p->nama])->values()->all();

        // "Apakah ini Anda?": bila akun ini masuk lagi di tempat lain sesudah sesi ini dimulai, masuk itulah
        // yang ditampilkan, bukan masuk sebelum sesi ini. Sesi lama tanpa catatan memakai nilai di akun.
        $terakhir = $this->login_terakhir_pada?->toIso8601String();
        $sesiIni = $request->hasSession() ? $request->session()->get(self::LOGIN_SESI_INI) : null;
        $adaMasukLain = is_string($sesiIni) && $terakhir !== null && $sesiIni !== $terakhir;
        [$sebelumnya, $sebelumnyaDariRs] = $adaMasukLain
            ? [$terakhir, $this->login_terakhir_dari_jaringan_rs]
            : [$this->masuk_sebelumnya_pada?->toIso8601String(), $this->masuk_sebelumnya_dari_jaringan_rs];

        return [
            'id' => $this->id,
            'nama_pengguna' => $this->nama_pengguna,
            'login_terakhir_pada' => is_string($sesiIni) ? $sesiIni : $terakhir,
            'mfa_aktif' => $this->mfaAktif(),
            'pegawai' => app(RingkasanPegawai::class)->untuk($this->pegawai_id),
            'peran' => $peran,
            'masuk_sebelumnya_pada' => $sebelumnya,
            'masuk_sebelumnya_dari_jaringan_rs' => $sebelumnya === null ? null : $sebelumnyaDariRs,
        ];
    }
}
