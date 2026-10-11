<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Modules\Akses\Application\KelolaAksesAkun;
use App\Modules\Akses\Http\Resources\AkunPresenter;
use App\Modules\Akses\Http\Resources\PersetujuanPresenter;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaIzinKhusus;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\Akses\Models\Peran;
use App\Modules\Akses\Models\PersetujuanAkses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Pengaturan → Akun & akses: penugasan, pengecualian per akun, dan MFA (kontrak /akun/{id}/*). */
final class AksesAkunController
{
    private const DASAR = ['required', 'string', 'min:3', 'max:200'];

    private const ALASAN = ['required', 'string', 'min:5', 'max:500'];

    public function __construct(
        private readonly KelolaAksesAkun $kelola,
        private readonly AkunPresenter $presenter,
        private readonly PersetujuanPresenter $persetujuan,
    ) {}

    public function tugaskan(Request $request, Pengguna $akun): JsonResponse
    {
        $isi = $request->validate([
            'peran_id' => ['required', 'uuid', Rule::exists(Peran::class, 'id')],
            'cakupan_jenis' => ['required', Rule::in(['rs', 'unit', 'ruang', 'poli', 'ksm', 'depo', 'pasien_dirawat'])],
            'cakupan_id' => ['nullable', 'uuid'],
            'berlaku_dari' => ['nullable', 'date'],
            'berlaku_sampai' => ['nullable', 'date'],
            'dasar_pemberian' => self::DASAR,
            'alasan' => ['nullable', 'string', 'max:500'],
            'permintaan_akses_id' => ['nullable', 'uuid'],
        ]);

        $penugasan = $this->kelola->tugaskan($request->user(), $akun, $isi);
        if ($penugasan instanceof PersetujuanAkses) {
            return $this->menunggu($request, $penugasan);
        }

        return new JsonResponse(['data' => $this->presenter->satuPenugasan($penugasan)], 201);
    }

    public function batalkanPenugasan(Request $request, Pengguna $akun, string $penugasanId): JsonResponse
    {
        $isi = $request->validate(['alasan_batal' => self::ALASAN]);
        $penugasan = PenggunaPeran::query()->where('pengguna_id', $akun->id)->findOrFail($penugasanId);

        $penugasan = $this->kelola->batalkanPenugasan($request->user(), $akun, $penugasan, $isi['alasan_batal']);
        if ($penugasan instanceof PersetujuanAkses) {
            return $this->menunggu($request, $penugasan);
        }

        return new JsonResponse(['data' => $this->presenter->satuPenugasan($penugasan)]);
    }

    public function buatIzinKhusus(Request $request, Pengguna $akun): JsonResponse
    {
        $isi = $request->validate([
            'efek' => ['required', Rule::in(['tambah', 'cabut'])],
            'sasaran_jenis' => ['required', Rule::in(['menu', 'izin'])],
            'sasaran_kode' => ['required', 'string', 'max:200'],
            'berlaku_sampai' => ['nullable', 'date'],
            'dasar_pemberian' => self::DASAR,
            'alasan' => self::ALASAN,
            'permintaan_akses_id' => ['nullable', 'uuid'],
        ]);

        $khusus = $this->kelola->buatIzinKhusus($request->user(), $akun, $isi);
        if ($khusus instanceof PersetujuanAkses) {
            return $this->menunggu($request, $khusus);
        }

        return new JsonResponse(['data' => $this->presenter->satuIzinKhusus($khusus)], 201);
    }

    public function batalkanIzinKhusus(Request $request, Pengguna $akun, string $izinKhususId): JsonResponse
    {
        $isi = $request->validate(['alasan_batal' => self::ALASAN]);
        $khusus = PenggunaIzinKhusus::query()->where('pengguna_id', $akun->id)->findOrFail($izinKhususId);

        $khusus = $this->kelola->batalkanIzinKhusus($request->user(), $akun, $khusus, $isi['alasan_batal']);

        return new JsonResponse(['data' => $this->presenter->satuIzinKhusus($khusus)]);
    }

    public function aturWajibMfa(Request $request, Pengguna $akun): JsonResponse
    {
        $isi = $request->validate(['wajib_mfa' => ['required', 'boolean'], 'alasan' => self::ALASAN]);

        $akun = $this->kelola->aturWajibMfa($request->user(), $akun, (bool) $isi['wajib_mfa'], $isi['alasan']);

        return new JsonResponse(['data' => $this->presenter->statusMfa($akun, $request->user())]);
    }

    public function nonaktifkan(Request $request, Pengguna $akun): JsonResponse
    {
        $isi = $request->validate(['alasan' => self::ALASAN]);

        return new JsonResponse(['data' => $this->presenter->rinci($this->kelola->nonaktifkan($request->user(), $akun, $isi['alasan']), $request->user())]);
    }

    public function aktifkan(Request $request, Pengguna $akun): JsonResponse
    {
        $isi = $request->validate(['alasan' => self::ALASAN]);

        return new JsonResponse(['data' => $this->presenter->rinci($this->kelola->aktifkan($request->user(), $akun, $isi['alasan']), $request->user())]);
    }

    public function resetMfa(Request $request, Pengguna $akun): JsonResponse
    {
        $isi = $request->validate(['alasan' => self::ALASAN, 'dasar' => self::DASAR]);

        $akun = $this->kelola->resetMfa($request->user(), $akun, $isi['alasan'], $isi['dasar']);

        return new JsonResponse(['data' => $this->presenter->statusMfa($akun, $request->user())]);
    }

    /** 202: pemberian berisiko menunggu persetujuan (data = persetujuan pertama dalam grup, tiket 1.2d). */
    private function menunggu(Request $request, PersetujuanAkses $p): JsonResponse
    {
        return new JsonResponse(['data' => $this->persetujuan->satu($p->refresh(), $request->user())], 202);
    }
}
