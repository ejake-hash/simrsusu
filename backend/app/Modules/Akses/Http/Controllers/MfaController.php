<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Core\Http\GalatApi;
use App\Modules\Akses\Application\AlasanGagalMfa;
use App\Modules\Akses\Application\GagalMfa;
use App\Modules\Akses\Application\TantanganMfa;
use App\Modules\Akses\Application\VerifikasiMfa;
use App\Modules\Akses\Domain\Totp;
use App\Modules\Akses\Http\Requests\MfaRequest;
use App\Modules\Akses\Models\Pengguna;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;

/**
 * Langkah 2a–2c kontrak PRD 10.3.2. Selama tantangan berjalan sesi belum masuk; semua endpoint di
 * sini hanya bekerja dengan tantangan yang dibuat langkah sandi di sesi yang sama.
 */
final class MfaController
{
    public function __construct(
        private readonly TantanganMfa $tantangan,
        private readonly VerifikasiMfa $mfa,
        private readonly PenyelesaiMasuk $penyelesai,
    ) {}

    /** 2a: kode TOTP atau kode cadangan. */
    public function verifikasi(MfaRequest $request): JsonResponse
    {
        [, $pengguna] = $this->tantanganBerlaku($request, 'mfa');
        if (! $pengguna->mfaAktif()) {
            throw $this->tidakBerlaku($request);
        }

        $this->jalankan($request, fn () => $this->mfa->verifikasi($pengguna, $request->validated('metode'), $request->validated('kode')));

        $this->tantangan->hapus($request->session());

        return response()->json($this->penyelesai->selesaikan($request, $pengguna));
    }

    /** 2b: rahasia baru untuk dipindai. Boleh diulang; rahasia terakhir yang dikonfirmasi. */
    public function daftar(MfaRequest $request): JsonResponse
    {
        [, $pengguna] = $this->tantanganBerlaku($request, 'daftar');

        $rahasia = Totp::rahasiaBaru();
        $this->tantangan->simpanRahasiaBaru($request->session(), $rahasia);
        $uri = Totp::uriOtpauth($rahasia, (string) config('autentikasi.mfa.penerbit'), $pengguna->nama_pengguna);
        $qr = (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($uri);

        return $this->tanpaCache(response()->json(['uri_otpauth' => $uri, 'qr_svg' => $qr]));
    }

    /** 2c: kode dari rahasia baru; berhasil = masuk + 10 kode cadangan (sekali tampil). */
    public function konfirmasiDaftar(MfaRequest $request): JsonResponse
    {
        [$tantangan, $pengguna] = $this->tantanganBerlaku($request, 'daftar');
        $rahasia = $this->tantangan->rahasiaBaru($tantangan);
        if ($rahasia === null) {
            throw $this->tidakBerlaku($request);
        }

        $kodeCadangan = $this->jalankan($request, fn () => $this->mfa->daftarkan($pengguna, $rahasia, $request->validated('kode')));

        $this->tantangan->hapus($request->session());

        return $this->tanpaCache(response()->json(
            $this->penyelesai->selesaikan($request, $pengguna) + ['kode_cadangan' => $kodeCadangan],
        ));
    }

    /**
     * @return array{0: array<string, mixed>, 1: Pengguna}
     */
    private function tantanganBerlaku(MfaRequest $request, string $jenis): array
    {
        PenyelesaiMasuk::pastikanDariSpa($request);

        $tantangan = $this->tantangan->ambil($request->session(), (string) $request->validated('tantangan_id'), $jenis);
        $pengguna = $tantangan === null ? null : Pengguna::query()->find($tantangan['pengguna_id']);

        // Akun yang dinonaktifkan di tengah langkah: kembali ke langkah sandi, yang memberi tahu statusnya.
        if ($pengguna === null || ! $pengguna->aktif()) {
            throw $this->tidakBerlaku($request);
        }

        return [$tantangan, $pengguna];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $langkah
     * @return T
     */
    private function jalankan(MfaRequest $request, callable $langkah): mixed
    {
        try {
            return $langkah();
        } catch (GagalMfa $e) {
            if ($e->alasan === AlasanGagalMfa::AkunTerkunci) {
                // Setelah kunci habis, mulai lagi dari sandi.
                $this->tantangan->hapus($request->session());
                throw PenyelesaiMasuk::galatTerkunci($e->terkunciSampai);
            }

            throw new GalatApi(401, $e->alasan->value, 'Kode verifikasi salah.');
        }
    }

    private function tidakBerlaku(MfaRequest $request): GalatApi
    {
        $this->tantangan->hapus($request->session());

        return new GalatApi(401, 'tantangan_tidak_berlaku', 'Waktu verifikasi habis atau tidak berlaku. Silakan masuk lagi.');
    }

    /** Respons memuat rahasia TOTP atau kode cadangan: jangan disimpan cache mana pun. */
    private function tanpaCache(JsonResponse $respons): JsonResponse
    {
        $respons->headers->set('Cache-Control', 'no-store, private');

        return $respons;
    }
}
