<?php

namespace App\Modules\Sdm\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Sdm\Application\KelolaKredensial;
use App\Modules\Sdm\Http\Resources\PegawaiResource;
use App\Modules\Sdm\Models\IzinPraktik;
use App\Modules\Sdm\Models\KewenanganKlinis;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** /api/v1/pegawai/{id}/izin-praktik dan /kewenangan-klinis (kontrak v0.6.0, tiket 1.8d). Izin sdm.kredensial.* cakupan rs. */
final class KredensialController
{
    private const ALASAN = ['required', 'string', 'min:5', 'max:500'];

    public function __construct(
        private readonly KelolaKredensial $kelola,
        private readonly PegawaiResource $resource,
    ) {}

    public function daftarIzin(Request $request, Pegawai $pegawai): JsonResponse
    {
        self::izin('lihat');

        return Paginasi::respons(IzinPraktik::query()->where('pegawai_id', $pegawai->id), $request, 'dibuat_pada',
            bentuk: fn ($baris) => $this->resource->izinPraktik($baris));
    }

    public function catatIzin(Request $request, Pegawai $pegawai): JsonResponse
    {
        self::izin('input');
        $isi = $request->validate([
            'jenis' => ['required', Rule::in(['str', 'sip', 'sik', 'spk'])],
            'nomor' => ['required', 'string', 'min:3', 'max:100'],
            'terbit' => ['required', 'date_format:Y-m-d'],
            'berlaku_sampai' => ['nullable', 'date_format:Y-m-d'],
            'berkas' => ['nullable', 'file'],
        ]);
        $izin = $this->kelola->catatIzinPraktik($request->user(), $pegawai, $isi, $request->file('berkas'));

        return new JsonResponse(['data' => $this->resource->izinPraktik(collect([$izin->refresh()]))[0]], 201);
    }

    public function batalkanIzin(Request $request, Pegawai $pegawai, string $izinPraktikId): JsonResponse
    {
        self::izin('perbaiki');
        $isi = $request->validate(['alasan_batal' => self::ALASAN]);
        $izin = IzinPraktik::query()->where('pegawai_id', $pegawai->id)->findOrFail($izinPraktikId);

        return new JsonResponse(['data' => $this->resource->izinPraktik(collect([$this->kelola->batalkan($request->user(), $izin, $isi['alasan_batal'])]))[0]]);
    }

    public function dokumen(Pegawai $pegawai, string $izinPraktikId): StreamedResponse
    {
        self::izin('lihat');
        $izin = IzinPraktik::query()->where('pegawai_id', $pegawai->id)->findOrFail($izinPraktikId);
        $disk = Storage::disk((string) config('sdm.dokumen_disk'));
        if ($izin->dokumen_jalur === null || ! $disk->exists($izin->dokumen_jalur)) {
            throw new ApiError(404, 'tidak_ditemukan', 'Dokumen tidak ada.');
        }
        $nama = $izin->jenis.'-'.$izin->id.'.'.pathinfo($izin->dokumen_jalur, PATHINFO_EXTENSION);

        return $disk->download($izin->dokumen_jalur, $nama, [
            'Content-Type' => (string) $izin->dokumen_mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function daftarKewenangan(Request $request, Pegawai $pegawai): JsonResponse
    {
        self::izin('lihat');

        return Paginasi::respons(KewenanganKlinis::query()->where('pegawai_id', $pegawai->id), $request, 'dibuat_pada',
            bentuk: fn ($baris) => $this->resource->kewenangan($baris));
    }

    public function catatKewenangan(Request $request, Pegawai $pegawai): JsonResponse
    {
        self::izin('input');
        $isi = $request->validate([
            'kategori' => ['required', 'string', 'min:2', 'max:100'],
            'kode_tindakan' => ['required', 'array', 'min:1', 'max:500'],
            'kode_tindakan.*' => ['string', 'distinct', 'regex:/^[0-9A-Za-z.\-]{1,20}$/'],
            'berlaku_dari' => ['required', 'date_format:Y-m-d'],
            'berlaku_sampai' => ['required', 'date_format:Y-m-d'],
            'komite' => ['required', 'string', 'min:2', 'max:150'],
            'dasar' => ['nullable', 'string', 'max:200'],
        ]);
        $rkk = $this->kelola->catatKewenangan($request->user(), $pegawai, $isi);

        return new JsonResponse(['data' => $this->resource->kewenangan(collect([$rkk->refresh()]))[0]], 201);
    }

    public function batalkanKewenangan(Request $request, Pegawai $pegawai, string $kewenanganId): JsonResponse
    {
        self::izin('perbaiki');
        $isi = $request->validate(['alasan_batal' => self::ALASAN]);
        $rkk = KewenanganKlinis::query()->where('pegawai_id', $pegawai->id)->findOrFail($kewenanganId);

        return new JsonResponse(['data' => $this->resource->kewenangan(collect([$this->kelola->batalkan($request->user(), $rkk, $isi['alasan_batal'])]))[0]]);
    }

    private static function izin(string $aksi): void
    {
        abort_unless(Gate::allows("sdm.kredensial.$aksi", Cakupan::rs()), 403);
    }
}
