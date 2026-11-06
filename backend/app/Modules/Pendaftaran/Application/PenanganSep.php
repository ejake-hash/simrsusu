<?php

namespace App\Modules\Pendaftaran\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\ProfilRs\ProfilRs;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\Peran;
use App\Modules\Integrasi\Application\LayananVclaim;
use App\Modules\Integrasi\Domain\HasilKirim;
use App\Modules\Integrasi\Domain\Kiriman;
use App\Modules\Integrasi\Domain\PenanganKiriman;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\MasterData\Models\Poliklinik;
use App\Modules\Notifikasi\Application\KotakKerja;
use App\Modules\Pendaftaran\Models\Kunjungan;
use App\Modules\Pendaftaran\Models\Sep;
use Illuminate\Support\Facades\DB;

/**
 * Kiriman outbox `sep_insert` (tiket 2.7; catatan lapangan §5.1–§5.2, perlu verifikasi ke katalog VClaim). SEP yang
 * ditunda membaca dan memeriksa rujukannya di sini dengan aturan yang sama (KelolaSep::periksaRujukan). Terbit/ditolak
 * mengubah baris SEP, tugas SEP_TERTUNDA, dan audit dalam satu transaksi.
 */
final class PenanganSep implements PenanganKiriman
{
    public function __construct(
        private readonly LayananVclaim $vclaim,
        private readonly KotakKerja $kotakKerja,
        private readonly CatatAktivitas $audit,
    ) {}

    public function tujuan(): SistemIntegrasi
    {
        return SistemIntegrasi::BpjsVclaim;
    }

    public function jenisResource(): string
    {
        return KelolaSep::JENIS_KIRIMAN;
    }

    public function periksa(Kiriman $kiriman): ?array
    {
        return self::kodePpk() === null
            ? ['kode' => 'kode_ppk_kosong', 'pesan' => 'Kode PPK BPJS di Profil RS belum diisi atau tidak valid.', 'pemilik_unit_id' => null]
            : null;
    }

    public function kirim(Kiriman $kiriman, string $lingkungan, array $kredensial): HasilKirim
    {
        $sep = Sep::query()->find($kiriman->agregatId);
        if ($sep === null || $sep->status !== 'menunggu') {
            return HasilKirim::berhasil();
        }
        $kunjungan = Kunjungan::query()->with('pasien.identitas')->findOrFail($sep->kunjungan_id);
        $noKartu = (string) $kunjungan->pasien->nilaiIdentitas('no_kartu_bpjs');
        $kelasHak = $kiriman->muatan['kls_rawat_hak'] ?? null;
        if ($sep->no_rujukan !== null && $sep->tanggal_rujukan === null) {
            // shortcut: baca rujukan lewat LayananVclaim mengambil jatah circuit breaker sendiri; saat setengah terbuka bisa
            // dianggap gangguan dan dicoba lagi nanti. Ganti dengan baca berkredensial pekerja bila itu sering terjadi.
            $hasil = $this->vclaim->rujukan($sep->no_rujukan, KelolaSep::ASAL[$sep->asal_rujukan ?? ''] ?? null);
            if ($hasil['penyebab'] !== null) {
                return HasilKirim::gangguan((string) $hasil['pesan']);
            }
            if (! $hasil['berhasil']) {
                return $this->tolak($sep, $kunjungan, 'bpjs', (string) $hasil['kode'], (string) $hasil['pesan']);
            }
            $rujukan = KelolaSep::bentukRujukan((array) $hasil['data'], (string) $hasil['asal'], $sep->tanggal_sep->toDateString());
            if (($galat = KelolaSep::periksaRujukan($rujukan, $noKartu, $kunjungan->jenis_kunjungan === 'igd')) !== null) {
                return $this->tolak($sep, $kunjungan, 'simrs', $galat[0], $galat[1]);
            }
            $sep->fill(KelolaSep::kolomRujukan($rujukan));
            if ($sep->diagnosis_kode === null) {
                $sep->fill(['diagnosis_kode' => $rujukan['diagnosis']['kode'] ?? null, 'diagnosis_nama' => $rujukan['diagnosis']['nama'] ?? null]);
            }
            $sep->save();
            $kelasHak = $hasil['data']['peserta']['hakKelas']['kode'] ?? null;
        }

        $hasil = $this->vclaim->insertSep($lingkungan, $kredensial, $this->tSep($sep, $kunjungan, $noKartu, $kelasHak), $kiriman->id);
        if ($hasil['gangguan']) {
            return HasilKirim::gangguan((string) $hasil['pesan']);
        }
        if (! $hasil['berhasil']) {
            return $this->tolak($sep, $kunjungan, 'bpjs', (string) $hasil['kode'], (string) $hasil['pesan']);
        }
        DB::transaction(function () use ($sep, $kunjungan, $hasil) {
            $sep->forceFill(['status' => 'terbit', 'no_sep' => $hasil['no_sep'], 'terbit_pada' => now(),
                'galat_sumber' => null, 'galat_kode' => null, 'galat_pesan' => null])->save();
            $this->kotakKerja->selesaikan('SEP_TERTUNDA', $sep->id, 'SEP terbit');
            $this->audit->catat('sep.diterbitkan', $sep->dibuat_oleh, 'integrasi.sep', $sep->id, ['kunjungan' => $kunjungan->nomor, 'status' => 'terbit']);
        });

        return HasilKirim::berhasil();
    }

    /** Tugas SEP_TERTUNDA untuk peran pemegang pendaftaran.sep.input; tanpa peran itu, untuk pengaju SEP. */
    public function tugasTertunda(Sep $sep, string $judul): void
    {
        $peran = Peran::query()->where('status', 'aktif')->whereIn('id', DB::table('akses.peran_izin')->where('status', 'aktif')
            ->whereIn('izin_id', DB::table('akses.izin')->where('kode', 'pendaftaran.sep.input')->select('id'))->select('peran_id'))
            ->orderBy('kode')->pluck('id')->map(fn ($id) => ['peran', (string) $id])->all();
        foreach ($peran === [] && $sep->dibuat_oleh !== null ? [['pengguna', $sep->dibuat_oleh]] : $peran as $pelaksana) {
            $this->kotakKerja->buat('SEP_TERTUNDA', $sep->id, $judul, $pelaksana);
        }
    }

    /** Kode PPK masuk ke isi SEP; hanya huruf dan angka. */
    public static function kodePpk(): ?string
    {
        $kode = trim((string) ProfilRs::satu()?->kode_ppk_bpjs);

        return preg_match('/^[A-Za-z0-9]{1,20}$/', $kode) === 1 ? $kode : null;
    }

    public static function kodePoli(string $poliId): ?string
    {
        $kode = trim((string) Poliklinik::query()->whereKey($poliId)->value('kode_poli_bpjs'));

        return $kode === '' ? null : $kode;
    }

    /**
     * SEP ditolak BPJS atau pemeriksaan rujukan gagal: baris disimpan sebagai jejak; tugas SEP yang ditunda diganti
     * dengan alasannya (kode saja, pesan BPJS bisa memuat data pasien). Pesan kiriman tanpa pesan BPJS.
     */
    private function tolak(Sep $sep, Kunjungan $kunjungan, string $sumber, string $kode, string $pesan): HasilKirim
    {
        DB::transaction(function () use ($sep, $kunjungan, $sumber, $kode, $pesan) {
            $sep->forceFill(['status' => 'ditolak', 'galat_sumber' => $sumber, 'galat_kode' => $kode, 'galat_pesan' => $pesan])->save();
            if ($this->kotakKerja->selesaikan('SEP_TERTUNDA', $sep->id, 'SEP ditolak ('.$kode.')') > 0) {
                $this->tugasTertunda($sep, "SEP kunjungan {$kunjungan->nomor} ditolak ($kode)");
            }
            $this->audit->catat('sep.ditolak', $sep->dibuat_oleh, 'integrasi.sep', $sep->id,
                ['kunjungan' => $kunjungan->nomor, 'status' => 'ditolak', 'sumber' => $sumber, 'kode' => $kode]);
        });

        return HasilKirim::ditolak('SEP ditolak '.$sumber.' (kode '.$kode.').');
    }

    /**
     * Isi `request.t_sep` insert rawat jalan (catatan §5.1). Insert selalu kunjungan normal: `tujuanKunj` string kosong
     * (bukan '0', §13 butir 2) dan pasangannya (flagProcedure, kdPenunjang, assesmentPel, dpjpLayan) kosong. Isi
     * `jaminan.penjamin.suplesi` tidak tercatat di catatan lapangan, jadi tidak dikirim (**perlu verifikasi**).
     *
     * @return array<string, mixed>
     */
    private function tSep(Sep $sep, Kunjungan $kunjungan, string $noKartu, ?string $kelasHak): array
    {
        return [
            'noKartu' => $noKartu, 'tglSep' => $sep->tanggal_sep->toDateString(), 'ppkPelayanan' => (string) self::kodePpk(),
            'jnsPelayanan' => $sep->jenis_pelayanan === 'rawat_inap' ? '1' : '2',
            'klsRawat' => ['klsRawatHak' => (string) $kelasHak, 'klsRawatNaik' => '', 'pembiayaan' => '', 'penanggungJawab' => ''],
            'noMR' => $kunjungan->pasien->no_rm,
            'rujukan' => ['asalRujukan' => KelolaSep::ASAL[$sep->asal_rujukan ?? ''] ?? '', 'tglRujukan' => (string) $sep->tanggal_rujukan?->toDateString(),
                'noRujukan' => (string) $sep->no_rujukan, 'ppkRujukan' => (string) $sep->ppk_rujukan],
            'catatan' => (string) $sep->catatan, 'diagAwal' => (string) $sep->diagnosis_kode,
            'poli' => ['tujuan' => (string) self::kodePoli($kunjungan->poli_id), 'eksekutif' => '0'],
            'cob' => ['cob' => '0'], 'katarak' => ['katarak' => '0'],
            'jaminan' => ['lakaLantas' => '0', 'noLP' => '', 'penjamin' => ['tglKejadian' => '', 'keterangan' => '']],
            'tujuanKunj' => '', 'flagProcedure' => '', 'kdPenunjang' => '', 'assesmentPel' => '',
            'skdp' => ['noSurat' => '', 'kodeDPJP' => ''], 'dpjpLayan' => '',
            'noTelp' => (string) $kunjungan->pasien->telepon_enkripsi,
            'user' => (string) (Pengguna::query()->whereKey($sep->dibuat_oleh)->value('nama_pengguna') ?? 'SIMRS'),
        ];
    }
}
