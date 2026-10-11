<?php

namespace App\Core\ProfilRs\Http;

use App\Core\Http\ApiError;
use App\Core\Http\IfMatch;
use App\Core\Http\PenggunaSesi;
use App\Core\ProfilRs\KelolaProfilRs;
use App\Core\ProfilRs\ProfilRs;
use App\Modules\Akses\Domain\Cakupan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** GET/PUT /api/v1/profil-rs dan berkas paket identitas (kontrak v0.3.0, tiket 1.8a). */
final class ProfilRsController
{
    private const IZIN_LIHAT = 'pengaturan.profil_rs.lihat';

    public function __construct(private readonly KelolaProfilRs $kelola) {}

    /** Publik: halaman masuk dan pita DEMO. `rinci` hanya untuk sesi sah yang punya izin lihat. */
    public function show(Request $request, PenggunaSesi $sesi): JsonResponse
    {
        $pengguna = $sesi->dari($request);
        // Lapis 3: profil RS milik seluruh RS, jadi butuh cakupan `rs`.
        $rinci = $pengguna !== null && Gate::forUser($pengguna)->allows(self::IZIN_LIHAT, Cakupan::rs());

        return $this->respons($request, ProfilRs::satu(), $rinci);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('pengaturan.profil_rs.perbaiki', Cakupan::rs()), 403);
        $isi = $request->validate([
            'nama' => ['required', 'string', 'min:3', 'max:200'],
            'kode_kemenkes' => ['present', 'nullable', 'string', 'max:20'],
            'kelas' => ['present', 'nullable', 'string', 'max:20'],
            'status_blu' => ['present', 'nullable', 'boolean'],
            'kode_ppk_bpjs' => ['present', 'nullable', 'string', 'max:20'],
            'alamat' => ['present', 'nullable', 'string', 'max:500'],
            'warna' => ['required', 'array:brand,accent'],
            'warna.brand' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'warna.accent' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'kontak_admin' => ['required', 'array:unit,kontak'],
            'kontak_admin.unit' => ['required', 'string', 'min:2', 'max:100'],
            'kontak_admin.kontak' => ['present', 'nullable', 'string', 'max:100'],
            'dasar_perubahan' => ['required', 'string', 'min:3', 'max:200'],
        ]);
        $versi = self::versiDiminta($request);

        return $this->respons($request, $this->kelola->ubah($request->user(), $versi, $isi), true);
    }

    public function gantiBerkas(Request $request, string $jenis): JsonResponse
    {
        abort_unless(Gate::allows('pengaturan.profil_rs.perbaiki', Cakupan::rs()), 403);
        $request->validate(['berkas' => ['required', 'file']]);
        $versi = self::versiDiminta($request);

        return $this->respons($request, $this->kelola->gantiBerkas($request->user(), $versi, $jenis, $request->file('berkas')), true);
    }

    /** Publik. Tipe dari pemeriksaan saat unggah; SVG diberi CSP yang mematikan skrip. */
    public function berkas(Request $request, string $jenis): Response
    {
        $data = ProfilRs::satu()?->paket_identitas[$jenis] ?? null;
        $disk = Storage::disk(config('instalasi.identitas.disk'));
        if ($data === null || ! $disk->exists($data['path'])) {
            throw new ApiError(404, 'tidak_ditemukan', 'Berkas belum diunggah.');
        }

        return response((string) $disk->get($data['path']), 200, array_filter([
            'Content-Type' => $data['tipe'],
            'X-Content-Type-Options' => 'nosniff',
            // Cache lama hanya untuk URL berversi yang cocok dengan berkas saat ini.
            'Cache-Control' => $request->query('v') === substr((string) $data['sha256'], 0, 12) ? 'public, max-age=31536000, immutable' : 'no-cache',
            'Content-Security-Policy' => $data['tipe'] === 'image/svg+xml' ? "default-src 'none'; style-src 'unsafe-inline'" : null,
        ]));
    }

    private function respons(Request $request, ?ProfilRs $profil, bool $rinci): JsonResponse
    {
        $data = ProfilRsResource::bentuk($profil, $rinci);
        $respons = new JsonResponse(['data' => $data]);
        // ETag berbeda untuk bentuk dengan/ tanpa rinci, agar cache tidak tertukar.
        $respons->setEtag($data['versi'].($rinci ? '-r' : ''))->setPrivate();
        $respons->headers->addCacheControlDirective('no-cache');
        $respons->setVary('Cookie');
        $respons->isNotModified($request);

        return $respons;
    }

    /** ETag bentuk publik dan bentuk ber-rinci menunjuk versi yang sama. */
    private static function versiDiminta(Request $request): string
    {
        return IfMatch::versi($request);
    }
}
