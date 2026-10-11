<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Database\Like;
use App\Core\Http\IfMatch;
use App\Core\Http\Paginasi;
use App\Modules\MasterData\Application\KelolaRawatInap;
use App\Modules\MasterData\Application\SuntinganBerversi;
use App\Modules\MasterData\Http\Resources\LokasiResource;
use App\Modules\MasterData\Models\Kamar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** /api/v1/kamar (kontrak v0.5.0, tiket 1.8b). Urut kode_lengkap ruangnya. */
final class KamarController
{
    use IzinOrganisasi;

    private const ATRIBUT = ['nullable', 'array', 'max:50'];

    public function __construct(private readonly LokasiResource $resource) {}

    public function index(Request $request): JsonResponse
    {
        $this->izin('lihat');
        $filter = $request->validate([
            'bangsal_id' => ['nullable', 'uuid'],
            'cari' => ['nullable', 'string', 'max:100'],
            'melebihi_kapasitas' => ['nullable', Rule::in(['true', 'false', '1', '0'])],
        ]);
        $hariIni = SuntinganBerversi::hariIni();
        $query = Kamar::query()->join('inti.lokasi AS r', 'r.id', '=', 'inti.kamar.lokasi_id')
            ->select('inti.kamar.*', 'r.kode_lengkap AS kode_ruang')
            ->when($filter['bangsal_id'] ?? null, fn ($q, $id) => $q->where('inti.kamar.bangsal_id', $id))
            ->when($filter['cari'] ?? null, fn ($q, $cari) => $q->where(fn ($q) => $q
                ->whereRaw('r.kode_lengkap ILIKE ?', [Like::memuat($cari)])->orWhereRaw('r.nama ILIKE ?', [Like::memuat($cari)])))
            ->when(self::benar($request, 'melebihi_kapasitas'), fn ($q) => $q->whereNotNull('inti.kamar.kapasitas_maksimum')
                ->whereRaw('(SELECT count(*) FROM inti.tempat_tidur tt WHERE tt.kamar_id = inti.kamar.id AND tt.berlaku_dari <= ?::date
                    AND (tt.berlaku_sampai IS NULL OR tt.berlaku_sampai >= ?::date)) > inti.kamar.kapasitas_maksimum', [$hariIni, $hariIni]));

        return Paginasi::respons($query, $request, 'r.kode_lengkap', 'inti.kamar.id', arah: 'asc', bentuk: fn ($baris) => $this->resource->kamar($baris));
    }

    public function show(Kamar $kamar): JsonResponse
    {
        $this->izin('lihat');

        return $this->satu($kamar);
    }

    public function store(Request $request, KelolaRawatInap $kelola): JsonResponse
    {
        $this->izin('input');
        $isi = $request->validate([
            'bangsal_id' => ['required', 'uuid'],
            'lokasi_id' => ['required', 'uuid'],
            'kapasitas_maksimum' => ['nullable', 'integer', 'min:1', 'max:99'],
            'atribut_standar' => self::ATRIBUT,
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);
        $this->pastikanAtribut($isi);

        return $this->satu($kelola->buatKamar($request->user(), $isi), 201);
    }

    public function update(Request $request, Kamar $kamar, KelolaRawatInap $kelola): JsonResponse
    {
        $this->izin('perbaiki');
        $isi = $request->validate([
            'bangsal_id' => ['sometimes', 'uuid'],
            'kapasitas_maksimum' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:99'],
            'atribut_standar' => ['sometimes', ...self::ATRIBUT],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);
        $this->minimalSatuPerubahan($isi);
        $this->pastikanAtribut($isi);

        return $this->satu($kelola->ubahKamar($request->user(), $kamar, IfMatch::versi($request), $isi));
    }

    /**
     * AtributKamar: kunci snake_case, nilai boolean/angka/teks ≤ 100 (kontrak).
     *
     * @param  array<string, mixed>  $isi
     */
    private function pastikanAtribut(array &$isi): void
    {
        $atribut = $isi['atribut_standar'] ?? null;
        if ($atribut === null) {
            unset($isi['atribut_standar']);

            return;
        }
        foreach ($atribut as $kunci => $nilai) {
            $sah = is_string($kunci) && preg_match('/^[a-z][a-z0-9_]{1,49}$/', $kunci) === 1
                && (is_bool($nilai) || is_int($nilai) || is_float($nilai) || (is_string($nilai) && mb_strlen($nilai) <= 100));
            if (! $sah) {
                throw ValidationException::withMessages(['atribut_standar' => 'Kunci snake_case dengan nilai ya/tidak, angka, atau teks pendek.']);
            }
        }
    }

    private function satu(Kamar $kamar, int $status = 200): JsonResponse
    {
        $kamar->refresh();

        return $this->respons($this->resource->kamar(collect([$kamar]))[0], $kamar->revisi, $status);
    }
}
