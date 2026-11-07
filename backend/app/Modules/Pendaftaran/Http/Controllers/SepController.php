<?php

namespace App\Modules\Pendaftaran\Http\Controllers;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Http\SuntinganBerversi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Integrasi\Application\KelolaKredensial;
use App\Modules\Integrasi\Application\LayananVclaim;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\Pendaftaran\Application\KelolaSep;
use App\Modules\Pendaftaran\Http\Resources\PasienResource;
use App\Modules\Pendaftaran\Models\IdentitasPasien;
use App\Modules\Pendaftaran\Models\Sep;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Kepesertaan, rujukan, dan SEP (kontrak v0.21.0, tiket 2.7). Izin pendaftaran.sep.{lihat,input} cakupan rs, diperiksa
 * sebelum mencari objek. No. kartu, NIK, dan nomor rujukan datang di header X-Nomor (persen-encode), tidak di URL, dan
 * tidak diulang di pesan error atau audit. Respons `no-store`.
 */
final class SepController
{
    public function __construct(
        private readonly LayananVclaim $vclaim,
        private readonly KelolaSep $kelola,
        private readonly KelolaKredensial $kredensial,
        private readonly CatatAktivitas $audit,
    ) {}

    public function peserta(Request $request): JsonResponse
    {
        self::izin('pendaftaran.sep.lihat');
        $tanggal = self::tanggal($request);
        $nomor = (string) preg_replace('/[\s.\-]/', '', self::nomor($request));
        $jenis = match (1) {
            preg_match('/^\d{13}$/', $nomor) => 'nokartu',
            preg_match('/^\d{16}$/', $nomor) => 'nik',
            default => throw new ApiError(422, 'nomor_tidak_dikenali', 'Isi No. kartu BPJS (13 digit) atau NIK (16 digit).'),
        };
        $hasil = $this->vclaim->peserta($jenis, $nomor, $tanggal);
        $p = (array) $hasil['data'];
        $pasienId = $hasil['berhasil'] ? IdentitasPasien::query()->where(fn ($q) => $q
            ->where(fn ($q) => $q->where('jenis', 'kartu_bpjs')->where('sidik', IdentitasPasien::sidik('kartu_bpjs', (string) ($p['noKartu'] ?? ''))))
            ->orWhere(fn ($q) => $q->where('jenis', 'nik')->where('sidik', IdentitasPasien::sidik('nik', (string) ($p['nik'] ?? '')))))
            ->value('pasien_id') : null;
        $this->audit->catat('bpjs.peserta.dibaca', $request->user()->id, null, null,
            ['jenis_nomor' => $jenis, 'hasil' => $hasil['berhasil'] ? 'berhasil' : ($hasil['penyebab'] ?? 'ditolak'), 'pasien_id' => $pasienId]);
        if (! $hasil['berhasil']) {
            throw $this->kelola->galatBaca($hasil);
        }
        $nik = isset($p['nik']) && $p['nik'] !== '' ? (string) $p['nik'] : null;
        $data = [
            'no_kartu' => (string) ($p['noKartu'] ?? ''),
            'nama' => (string) ($p['nama'] ?? ''),
            ...(PasienResource::bolehIdentitas($request->user()) ? ['nik' => $nik] : []),
            'nik_samaran' => $nik === null ? null : substr($nik, 0, 4).' •••• •••• '.substr($nik, -4),
            'tanggal_lahir' => $p['tglLahir'] ?? null,
            'jenis_kelamin' => match ($p['sex'] ?? null) {
                'L' => 'laki_laki', 'P' => 'perempuan', default => 'tidak_diketahui'
            },
            'status' => ['aktif' => (string) ($p['statusPeserta']['kode'] ?? '') === '0', 'kode' => (string) ($p['statusPeserta']['kode'] ?? ''),
                'keterangan' => (string) ($p['statusPeserta']['keterangan'] ?? '')],
            'jenis_peserta' => KelolaSep::kodeNama($p['jenisPeserta'] ?? null),
            'kelas_hak' => KelolaSep::kodeNama($p['hakKelas'] ?? null),
            'faskes_tingkat_pertama' => isset($p['provUmum']['kdProvider'])
                ? ['kode' => (string) $p['provUmum']['kdProvider'], 'nama' => (string) ($p['provUmum']['nmProvider'] ?? '')] : null,
            'prolanis_prb' => $p['informasi']['prolanisPRB'] ?? null,
            ...(Gate::allows('pendaftaran.pasien.lihat', Cakupan::rs())
                ? ['pasien' => $pasienId === null ? null : ['id' => $pasienId, 'no_rm' => (string) DB::table('pasien.pasien')->where('id', $pasienId)->value('no_rm')]] : []),
            ...$this->dibaca(),
        ];

        return (new JsonResponse(['data' => $data]))->header('Cache-Control', 'no-store');
    }

    public function rujukan(Request $request): JsonResponse
    {
        self::izin('pendaftaran.sep.lihat');
        $asal = $request->validate(['asal' => ['nullable', Rule::in(array_keys(KelolaSep::ASAL))]])['asal'] ?? null;
        $tanggal = self::tanggal($request);
        $nomor = trim(self::nomor($request));
        if (preg_match('/^[A-Za-z0-9]{1,40}$/', $nomor) !== 1) {
            throw new ApiError(422, 'nomor_tidak_dikenali', 'Isi nomor rujukan (huruf dan angka, paling banyak 40 karakter).');
        }
        $hasil = $this->vclaim->rujukan($nomor, $asal === null ? null : KelolaSep::ASAL[$asal]);
        $this->audit->catat('bpjs.rujukan.dibaca', $request->user()->id, null, null,
            ['asal' => $asal, 'hasil' => $hasil['berhasil'] ? 'berhasil' : ($hasil['penyebab'] ?? 'ditolak')]);
        if (! $hasil['berhasil']) {
            throw $this->kelola->galatBaca($hasil);
        }

        return (new JsonResponse(['data' => [...KelolaSep::bentukRujukan((array) $hasil['data'], (string) $hasil['asal'], $tanggal), ...$this->dibaca()]]))
            ->header('Cache-Control', 'no-store');
    }

    public function terbitkan(Request $request, string $kunjungan, RingkasanPegawai $pegawai): JsonResponse
    {
        self::izin('pendaftaran.sep.input');
        $isi = $request->validate([
            'no_rujukan' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9]+$/'],
            'asal_rujukan' => ['nullable', Rule::in(array_keys(KelolaSep::ASAL))],
            'diagnosis_awal' => ['nullable', 'string', 'max:10'],
            'catatan' => ['nullable', 'string', 'max:200'],
            'tunda' => ['sometimes', 'boolean'],
        ]);
        $kunci = $request->header('Idempotency-Key');
        if ($kunci !== null && mb_strlen($kunci) > 100) {
            throw new ApiError(422, 'validasi_gagal', 'Idempotency-Key paling banyak 100 karakter.');
        }
        [$sep, $status] = $this->kelola->ajukan($request->user()->id, $kunjungan, $isi, $kunci);
        $akun = Pengguna::query()->find($sep->dibuat_oleh);

        return (new JsonResponse(['data' => [
            'id' => $sep->id, 'kunjungan_id' => $sep->kunjungan_id, 'episode_id' => $sep->episode_id, 'pasien_id' => $sep->pasien_id,
            'no_sep' => $sep->no_sep, 'tanggal_sep' => $sep->tanggal_sep->toDateString(), 'jenis_pelayanan' => $sep->jenis_pelayanan, 'status' => $sep->status,
            'rujukan' => $sep->no_rujukan === null ? null : ['no_rujukan' => $sep->no_rujukan, 'asal' => $sep->asal_rujukan,
                'tanggal_rujukan' => $sep->tanggal_rujukan?->toDateString(), 'berlaku_sampai' => $sep->berlaku_sampai?->toDateString(),
                'status_masa_berlaku' => $sep->berlaku_sampai === null ? null : KelolaSep::statusMasa((int) $sep->tanggal_sep->diffInDays($sep->berlaku_sampai, false))],
            'diagnosis_awal' => $sep->diagnosis_kode === null ? null : ['kode' => $sep->diagnosis_kode, 'nama' => (string) $sep->diagnosis_nama],
            'catatan' => $sep->catatan, 'peringatan' => $sep->peringatan,
            'galat' => $sep->galat_kode === null ? null : ['sumber' => $sep->galat_sumber, 'kode' => $sep->galat_kode, 'pesan' => (string) $sep->galat_pesan],
            'kiriman_id' => $sep->kiriman_id, 'lingkungan' => $sep->lingkungan,
            'diajukan_oleh' => $akun === null ? null : ['id' => $akun->id, 'nama' => $pegawai->nama([$akun->pegawai_id])[$akun->pegawai_id] ?? $akun->nama_pengguna],
            'terbit_pada' => $sep->terbit_pada?->toIso8601String(), 'dibuat_pada' => $sep->dibuat_pada->toIso8601String(),
            'diubah_pada' => $sep->diubah_pada->toIso8601String(),
        ]], $status))->header('Cache-Control', 'no-store');
    }

    /** @return array{lingkungan: string|null, dibaca_pada: string} */
    private function dibaca(): array
    {
        return ['lingkungan' => $this->kredensial->lingkunganAktif(SistemIntegrasi::BpjsVclaim), 'dibaca_pada' => now()->toIso8601String()];
    }

    private static function nomor(Request $request): string
    {
        $nomor = rawurldecode((string) $request->header('X-Nomor'));

        return mb_check_encoding($nomor, 'UTF-8') ? $nomor : '';
    }

    private static function tanggal(Request $request): string
    {
        return $request->validate(['tanggal' => ['nullable', 'date_format:Y-m-d']])['tanggal'] ?? SuntinganBerversi::hariIni();
    }

    private static function izin(string $kode): void
    {
        Gate::authorize($kode, Cakupan::rs());
    }
}
