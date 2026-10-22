<?php

namespace App\Modules\Pendaftaran\Http\Controllers;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\IfMatch;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Pendaftaran\Application\KelolaPasien;
use App\Modules\Pendaftaran\Domain\KataCariPasien;
use App\Modules\Pendaftaran\Http\Resources\PasienResource;
use App\Modules\Pendaftaran\Models\IdentitasPasien;
use App\Modules\Pendaftaran\Models\Pasien;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * /api/v1/pasien (kontrak v0.19.0, tiket 2.5). Izin pendaftaran.pasien.* cakupan rs. Kata cari lewat header X-Cari,
 * bukan query string: data pasien tidak boleh ada di URL atau log akses.
 */
final class PasienController
{
    public function __construct(private readonly PasienResource $resource) {}

    public function index(Request $request, CatatAktivitas $audit): JsonResponse
    {
        self::izin('pendaftaran.pasien.lihat');
        $cari = KataCariPasien::tafsir($request->header('X-Cari'));
        $query = Pasien::query();
        $jenisIdentitas = $cari->jenis === 'nik' ? 'nik' : 'kartu_bpjs';
        match ($cari->jenis) {
            'nik', 'no_kartu' => $query->whereIn('id', IdentitasPasien::query()->select('pasien_id')
                ->where('jenis', $jenisIdentitas)->where('sidik', IdentitasPasien::sidik($jenisIdentitas, $cari->nilai))),
            'no_rm' => $query->where('no_rm', $cari->nilai),
            // Kemiripan kata (pg_trgm, tanpa beda huruf besar/kecil): sebagian nama atau salah ketik kecil tetap cocok.
            default => $query->where('tanggal_lahir', $cari->tanggalLahir)->whereRaw('? <% nama', [$cari->nilai]),
        };

        $respons = Paginasi::respons($query, $request, 'dibuat_pada',
            bentuk: fn ($baris) => $this->resource->banyak($baris, $request->user()));
        $isi = $respons->getData(true);
        $isi['meta']['jenis_cari'] = $cari->jenis;
        $audit->catat('pasien.dicari', $request->user()->id, null, null,
            ['jenis_cari' => $cari->jenis, 'jumlah' => count($isi['data']), 'identitas_utuh' => PasienResource::bolehIdentitas($request->user())]);

        return $respons->setData($isi)->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, KelolaPasien $kelola): JsonResponse
    {
        self::izin('pendaftaran.pasien.input');
        $isi = $request->validate(self::aturan(false));

        return $this->satu($request, $kelola->buat($request->user(), $isi), 201);
    }

    public function update(Request $request, string $pasien, KelolaPasien $kelola): JsonResponse
    {
        // Izin diperiksa sebelum mencari pasien: tanpa izin, ada atau tidaknya id tidak terbaca dari 403/404.
        self::izin('pendaftaran.pasien.perbaiki');
        $pasien = Pasien::query()->findOrFail($pasien);
        $isi = $request->validate([...self::aturan(true), 'alasan_perubahan' => ['required', 'string', 'min:5', 'max:200']]);
        if (count($isi) < 2) {
            throw ValidationException::withMessages(['alasan_perubahan' => 'Isi minimal satu perubahan.']);
        }

        return $this->satu($request, $kelola->ubah($request->user(), $pasien, IfMatch::versi($request), $isi, PasienResource::bolehIdentitas($request->user())));
    }

    /** @return array<string, list<mixed>> */
    private static function aturan(bool $ubah): array
    {
        $opsional = $ubah ? ['sometimes', 'nullable'] : ['nullable'];
        $telepon = 'regex:/^\+?[0-9]{8,15}$/';

        return [
            'nama' => [...$opsional, 'string', 'min:1', 'max:150'],
            'nik' => [...$opsional, 'string', 'regex:/^[0-9]{16}$/'],
            'no_paspor' => [...$opsional, 'string', 'regex:/^[A-Z0-9]{5,20}$/'],
            'no_kartu_bpjs' => [...$opsional, 'string', 'regex:/^[0-9]{13}$/'],
            'tanggal_lahir' => [...$opsional, 'date_format:Y-m-d'],
            'tempat_lahir' => [...$opsional, 'string', 'max:100'],
            'jenis_kelamin' => [$ubah ? 'sometimes' : 'required', Rule::in(Pasien::JENIS_KELAMIN)],
            'agama' => [...$opsional, 'string', 'max:50'],
            'pendidikan' => [...$opsional, 'string', 'max:50'],
            'pekerjaan' => [...$opsional, 'string', 'max:50'],
            'status_kawin' => [...$opsional, 'string', 'max:50'],
            // Kontrak: kosong saat daftar = wni; saat perbaikan tidak bisa dikosongkan.
            'kewarganegaraan' => [...($ubah ? ['sometimes'] : ['nullable']), Rule::in(['wni', 'wna'])],
            'bahasa' => [...$opsional, 'string', 'max:50'],
            'kebutuhan_khusus' => [...$opsional, 'string', 'max:200'],
            'alamat' => [...$opsional, 'array'],
            'alamat.jalan' => ['required_with:alamat', 'string', 'min:1', 'max:200'],
            'alamat.rt' => ['nullable', 'string', 'regex:/^[0-9]{1,3}$/'],
            'alamat.rw' => ['nullable', 'string', 'regex:/^[0-9]{1,3}$/'],
            'alamat.kode_pos' => ['nullable', 'string', 'regex:/^[0-9]{5}$/'],
            'alamat.wilayah_kode' => ['required_with:alamat', 'string', 'regex:/^[0-9]{2}\.[0-9]{2}\.[0-9]{2}\.[0-9]{4}$/'],
            'telepon' => [...$opsional, 'string', $telepon],
            'kontak_darurat' => [$ubah ? 'sometimes' : 'nullable', 'array', 'list', 'max:3'],
            'kontak_darurat.*.nama' => ['required', 'string', 'min:1', 'max:150'],
            'kontak_darurat.*.hubungan' => ['required', 'string', 'min:1', 'max:50'],
            'kontak_darurat.*.telepon' => ['required', 'string', $telepon],
            'tanpa_identitas' => [$ubah ? 'sometimes' : 'nullable', 'boolean'],
            'ciri_pengenal' => [...$opsional, 'string', 'max:500'],
        ];
    }

    private static function izin(string $kode): void
    {
        abort_unless(Gate::allows($kode, Cakupan::rs()), 403);
    }

    private function satu(Request $request, Pasien $pasien, int $status = 200): JsonResponse
    {
        $pasien->refresh();
        $respons = new JsonResponse(['data' => $this->resource->satu($pasien, $request->user())], $status);

        return $respons->setEtag((string) $pasien->revisi)->header('Cache-Control', 'no-store');
    }
}
