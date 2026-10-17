<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Core\Http\ApiError;
use App\Modules\Akses\Application\AlasanGagalMfa;
use App\Modules\Akses\Application\GagalMfa;
use App\Modules\Akses\Application\KebijakanMfa;
use App\Modules\Akses\Application\KelolaPerangkat;
use App\Modules\Akses\Application\MfaChallenge;
use App\Modules\Akses\Application\VerifikasiMfa;
use App\Modules\Akses\Domain\Totp;
use App\Modules\Akses\Http\Requests\MfaRequest;
use App\Modules\Akses\Models\Pengguna;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Langkah 2a–2c kontrak PRD 10.3.2. Selama challenge berjalan sesi belum masuk; semua endpoint di
 * sini hanya bekerja dengan challenge yang dibuat langkah sandi di sesi yang sama.
 */
final class MfaController
{
    public function __construct(
        private readonly MfaChallenge $challenge,
        private readonly VerifikasiMfa $mfa,
        private readonly PenyelesaiMasuk $penyelesai,
        private readonly KebijakanMfa $kebijakan,
        private readonly KelolaPerangkat $perangkat,
    ) {}

    /** 2a: kode TOTP atau kode cadangan. */
    public function verifikasi(MfaRequest $request): JsonResponse
    {
        [, $pengguna] = $this->challengeBerlaku($request, 'mfa');
        if (! $pengguna->mfaAktif()) {
            throw $this->tidakBerlaku($request);
        }

        $this->jalankan($request, fn () => $this->mfa->verifikasi($pengguna, $request->validated('metode'), $request->validated('kode')));

        $this->challenge->hapus($request->session());
        $cookie = $this->percayaiBila($request, $pengguna);

        return $this->denganCookie(response()->json($this->penyelesai->selesaikan($request, $pengguna, denganMfa: true)), $cookie);
    }

    /** 2b: rahasia baru untuk dipindai. Boleh diulang; rahasia terakhir yang dikonfirmasi. */
    public function daftar(MfaRequest $request): JsonResponse
    {
        [, $pengguna] = $this->challengeBerlaku($request, 'daftar');

        $rahasia = Totp::rahasiaBaru();
        $this->challenge->simpanRahasiaBaru($request->session(), $rahasia);
        $uri = Totp::uriOtpauth($rahasia, (string) config('autentikasi.mfa.penerbit'), $pengguna->nama_pengguna);
        $qr = (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($uri);

        return $this->tanpaCache(response()->json(['uri_otpauth' => $uri, 'qr_svg' => $qr]));
    }

    /** 2c: kode dari rahasia baru; berhasil = masuk + 10 kode cadangan (sekali tampil). */
    public function konfirmasiDaftar(MfaRequest $request): JsonResponse
    {
        [$challenge, $pengguna] = $this->challengeBerlaku($request, 'daftar');
        $rahasia = $this->challenge->rahasiaBaru($challenge);
        if ($rahasia === null) {
            throw $this->tidakBerlaku($request);
        }

        $kodeCadangan = $this->jalankan($request, fn () => $this->mfa->daftarkan($pengguna, $rahasia, $request->validated('kode')));

        $this->challenge->hapus($request->session());
        $cookie = $this->percayaiBila($request, $pengguna);

        return $this->denganCookie($this->tanpaCache(response()->json(
            $this->penyelesai->selesaikan($request, $pengguna, denganMfa: true) + ['kode_cadangan' => $kodeCadangan],
        )), $cookie);
    }

    /** Centang "Percayai perangkat ini" (tiket 1.13); diabaikan bila tidak tersedia (luar jaringan RS, masa percaya 0). */
    private function percayaiBila(MfaRequest $request, Pengguna $pengguna): ?Cookie
    {
        return $request->boolean('percayai_perangkat') ? ($this->perangkat->percayai($request, $pengguna)[1] ?? null) : null;
    }

    private function denganCookie(JsonResponse $respons, ?Cookie $cookie): JsonResponse
    {
        return $cookie === null ? $respons : $respons->withCookie($cookie);
    }

    /**
     * @return array{0: array<string, mixed>, 1: Pengguna}
     */
    private function challengeBerlaku(MfaRequest $request, string $jenis): array
    {
        PenyelesaiMasuk::pastikanDariSpa($request);

        $challenge = $this->challenge->ambil($request->session(), (string) $request->validated('challenge_id'), $jenis);
        $pengguna = $challenge === null ? null : Pengguna::query()->find($challenge['pengguna_id']);

        // Akun yang dinonaktifkan di tengah langkah, atau pendaftaran yang pindah ke luar jaringan RS:
        // kembali ke langkah sandi, yang memberi tahu alasannya.
        $pindahKeLuar = $jenis === 'daftar' && ! $this->kebijakan->dariJaringanRs($request->ip());
        if ($pengguna === null || ! $pengguna->aktif() || $pindahKeLuar) {
            throw $this->tidakBerlaku($request);
        }

        return [$challenge, $pengguna];
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
                $this->challenge->hapus($request->session());
                throw PenyelesaiMasuk::errorTerkunci($e->terkunciSampai);
            }

            throw new ApiError(401, $e->alasan->value, 'Kode verifikasi salah.');
        }
    }

    private function tidakBerlaku(MfaRequest $request): ApiError
    {
        $this->challenge->hapus($request->session());

        return new ApiError(401, 'challenge_tidak_berlaku', 'Waktu verifikasi habis atau tidak berlaku. Silakan masuk lagi.');
    }

    /** Respons memuat rahasia TOTP atau kode cadangan: jangan disimpan cache mana pun. */
    private function tanpaCache(JsonResponse $respons): JsonResponse
    {
        $respons->headers->set('Cache-Control', 'no-store, private');

        return $respons;
    }
}
