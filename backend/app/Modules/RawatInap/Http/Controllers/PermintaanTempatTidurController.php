<?php

namespace App\Modules\RawatInap\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\RawatInap\Application\AlokasiTempatTidur;
use App\Modules\RawatInap\Application\AntreanPermintaanKamar;
use App\Modules\RawatInap\Application\BacaRawatInap;
use App\Modules\RawatInap\Application\PermintaanKamar;
use App\Modules\RawatInap\Application\TempatkanPasien;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * SPRI, antrean permintaan kamar, dan alokasi TT (kontrak v0.36.0, tiket 2.28). Antrean dan alokasi memakai sub-menu
 * pendaftaran.admisi (Admisi rawat inap): `lihat` = antrean (cakupan mana pun, satu antrean RS), `input` = alokasi dengan
 * cakupan yang memuat unit bangsal TT (pola POST status TT 2.27). SPRI memakai izin sel matriks poliklinik → rawat_inap
 * (diperiksa PermintaanKamar sebelum isian dibaca). Penempatan (2.29) memakai rawat_inap.penempatan.input; batal memakai
 * izin alokasi. Semua respons `no-store`.
 */
final class PermintaanTempatTidurController
{
    private const IZIN = 'pendaftaran.admisi';

    public function __construct(private readonly AntreanPermintaanKamar $antrean) {}

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate(['status' => ['nullable', Rule::in(PermintaanKamar::AKTIF)]]);

        return Paginasi::respons($this->antrean->kueri($filter['status'] ?? null), $request, 'ptt.menunggu_sejak', 'ptt.id', 'asc',
            fn ($baris) => $this->antrean->bentuk($baris))->header('Cache-Control', 'no-store');
    }

    public function alokasi(Request $request, string $permintaan, AlokasiTempatTidur $alokasi): JsonResponse
    {
        if (! DB::table('kunjungan.permintaan_tempat_tidur')->where('id', $permintaan)->exists()) {
            throw new ApiError(404, 'tidak_ditemukan', 'Permintaan kamar tidak ditemukan.');
        }
        $isi = $request->validate(['tempat_tidur_id' => ['required', 'string']]);
        // Id dari isian: bukan UUID → 422, bukan error basis data.
        if (! Str::isUuid($isi['tempat_tidur_id'])) {
            throw new ApiError(422, 'validasi_gagal', 'Isian belum benar.', [['kolom' => 'tempat_tidur_id', 'pesan' => 'Pilih tempat tidur dari peta.']]);
        }
        $unit = DB::table('inti.tempat_tidur as tt')->join('inti.kamar as km', 'km.id', '=', 'tt.kamar_id')
            ->join('inti.bangsal as b', 'b.id', '=', 'km.bangsal_id')->where('tt.id', $isi['tempat_tidur_id'])->value('b.unit_organisasi_id')
            ?? throw new ApiError(422, 'validasi_gagal', 'Isian belum benar.', [['kolom' => 'tempat_tidur_id', 'pesan' => 'Tempat tidur tidak ditemukan.']]);
        Gate::authorize(self::IZIN.'.input', new Cakupan('unit', (string) $unit));

        /** @var Pengguna $petugas */
        $petugas = $request->user();
        $alokasi->alokasikan($petugas, $permintaan, $isi['tempat_tidur_id']);

        return (new JsonResponse(['data' => $this->antrean->satu($permintaan)]))->header('Cache-Control', 'no-store');
    }

    /**
     * Penempatan pasien di TT yang dialokasikan (2.29): izin rute rawat_inap.penempatan.input di cakupan mana pun, lalu
     * permintaan ada (404), lalu cakupan unit bangsal TT pesanan (403); permintaan tanpa TT → 409 di layanan.
     */
    public function tempatkan(Request $request, string $permintaan, TempatkanPasien $layanan, BacaRawatInap $baca): JsonResponse
    {
        $tt = DB::table('kunjungan.permintaan_tempat_tidur')->where('id', $permintaan)->first(['tempat_tidur_id'])
            ?? throw new ApiError(404, 'tidak_ditemukan', 'Permintaan kamar tidak ditemukan.');
        if ($tt->tempat_tidur_id !== null) {
            Gate::authorize('rawat_inap.penempatan.input', new Cakupan('unit', self::unitTt((string) $tt->tempat_tidur_id)));
        }
        $isi = $request->validate(['serah_terima' => ['nullable', 'string', 'min:5', 'max:4000']]);

        /** @var Pengguna $petugas */
        $petugas = $request->user();
        $kunjungan = $layanan->tempatkan($petugas, $permintaan, $isi['serah_terima'] ?? null);

        return (new JsonResponse(['data' => $baca->satu($kunjungan)], 201))->header('Cache-Control', 'no-store');
    }

    /**
     * Pembatalan permintaan kamar oleh admisi (2.29): izin rute pendaftaran.admisi.input (sama dengan alokasi); permintaan
     * yang sudah memesan TT juga harus dalam cakupan unit bangsal TT itu.
     */
    public function batal(Request $request, string $permintaan, PermintaanKamar $layanan): JsonResponse
    {
        $tt = DB::table('kunjungan.permintaan_tempat_tidur')->where('id', $permintaan)->first(['tempat_tidur_id'])
            ?? throw new ApiError(404, 'tidak_ditemukan', 'Permintaan kamar tidak ditemukan.');
        if ($tt->tempat_tidur_id !== null) {
            Gate::authorize(self::IZIN.'.input', new Cakupan('unit', self::unitTt((string) $tt->tempat_tidur_id)));
        }
        $isi = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:1000']]);

        /** @var Pengguna $petugas */
        $petugas = $request->user();
        $layanan->batalOlehPetugas($petugas, $permintaan, $isi['alasan']);

        return (new JsonResponse(['data' => $this->antrean->satu($permintaan)]))->header('Cache-Control', 'no-store');
    }

    public function spri(Request $request, string $kunjungan, PermintaanKamar $layanan): JsonResponse
    {
        /** @var Pengguna $dokter */
        $dokter = $request->user();
        $layanan->izinkanSpri($dokter, $kunjungan);
        $isi = $request->validate([
            'alasan' => ['required', 'string', 'min:5', 'max:1000'],
            'kelas_rawat_id' => ['required', 'uuid'],
            'bangsal_id' => ['nullable', 'uuid'],
            'tanggal_rencana' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $id = $layanan->spri($dokter, $kunjungan, $isi);

        return (new JsonResponse(['data' => $this->antrean->satu($id)], 201))->header('Cache-Control', 'no-store');
    }

    private static function unitTt(string $tempatTidurId): string
    {
        return (string) DB::table('inti.tempat_tidur as tt')->join('inti.kamar as km', 'km.id', '=', 'tt.kamar_id')
            ->join('inti.bangsal as b', 'b.id', '=', 'km.bangsal_id')->where('tt.id', $tempatTidurId)->value('b.unit_organisasi_id');
    }
}
