<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Application\KelolaAksesDarurat;
use App\Modules\Akses\Http\Resources\AksesDaruratPresenter;
use App\Modules\Akses\Models\AksesDarurat;
use App\Modules\Akses\Models\Pengguna;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Akses darurat RM (tiket 2.21, kontrak v0.29.0). Respons `no-store`: memuat identitas pasien dan alasan. */
final class AksesDaruratController
{
    public const IZIN_BUKA = 'rawat_jalan.cppt.darurat';

    public function __construct(
        private readonly KelolaAksesDarurat $kelola,
        private readonly AksesDaruratPresenter $presenter,
    ) {}

    public function buka(Request $request, string $pasien): JsonResponse
    {
        /** @var Pengguna $saya */
        $saya = $request->user();
        // Bukan tenaga klinis (profesi pegawai kosong/non-kesehatan) ditolak sebelum lapis 5, agar kodenya bukan izin_praktik_tidak_berlaku.
        $profesi = DB::table('sdm.pegawai')->where('id', $saya->pegawai_id)->value('profesi');
        if ($profesi === null || $profesi === 'non_kesehatan') {
            throw new ApiError(403, 'akses_ditolak', 'Akses darurat hanya untuk tenaga klinis.');
        }
        Gate::authorize(self::IZIN_BUKA);
        abort_unless(DB::table('pasien.pasien')->where('id', $pasien)->exists(), 404);
        $isi = $request->validate([
            'kategori_alasan' => ['required', Rule::in(['audit', 'klaim', 'hukum', 'pelayanan_lanjutan'])],
            'alasan' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        [$akses, $baru] = $this->kelola->buka($saya, $pasien, $isi['kategori_alasan'], $isi['alasan']);

        return $this->respons(['data' => $this->presenter->satu($akses, $saya)], $baru ? 201 : 200);
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['menunggu', 'ditinjau'])]])['status'] ?? 'menunggu';
        $query = AksesDarurat::query()->when($status === 'menunggu', fn ($q) => $q->whereNull('hasil_tinjauan'), fn ($q) => $q->whereNotNull('hasil_tinjauan'));

        return Paginasi::respons($query, $request, 'mulai_pada', bentuk: fn (Collection $baris) => $this->presenter->daftar($baris, $request->user()))
            ->setPrivate()->header('Cache-Control', 'no-store, private');
    }

    public function tinjau(Request $request, string $akses): JsonResponse
    {
        $isi = $request->validate([
            'hasil' => ['required', Rule::in(['wajar', 'tidak_wajar'])],
            'catatan' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        return $this->respons(['data' => $this->presenter->satu($this->kelola->tinjau($request->user(), $akses, $isi['hasil'], $isi['catatan']), $request->user())]);
    }

    /** @param  array<string, mixed>  $isi */
    private function respons(array $isi, int $status = 200): JsonResponse
    {
        return (new JsonResponse($isi, $status))->setPrivate()->header('Cache-Control', 'no-store, private');
    }
}
