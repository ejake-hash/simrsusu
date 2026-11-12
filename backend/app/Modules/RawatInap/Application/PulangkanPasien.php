<?php

namespace App\Modules\RawatInap\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Instalasi\StatusInstalasi;
use App\Modules\Akses\Application\KelolaRelasiPerawatan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\AturanPerpindahan;
use App\Modules\MasterData\Models\DaftarPeriksaPulang;
use App\Modules\Notifikasi\Application\KotakKerja;
use App\Modules\Pendaftaran\Application\KelolaPasien;
use App\Modules\Pendaftaran\Application\KunciKunjungan;
use App\Modules\Pendaftaran\Models\Episode;
use App\Modules\RawatInap\Domain\StatusTempatTidur;
use App\Modules\RawatInap\Events\PasienPulang;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Pasien rawat inap keluar lewat matriks perpindahan (tiket 2.40, kontrak v0.39.0; FR-05-03, FR-05-04, FR-05-05, PRD
 * 7.00.3 baris "Rawat inap", keputusan pemilik 13 Nov 2026). Tujuan pulang | aps | rujuk_keluar | meninggal, penutupannya
 * sama (rincian B-05/B-06 FR-05-07 di luar tiket). Sebelum isian lain dibaca: sel matriks rawat_inap → tujuan nonaktif →
 * 422; izin sel di cakupan mana pun (lapis 5 SIP/SPK) → 403; kunjungan tidak ada → 404; cakupan unit bangsal → 403.
 * Satu transaksi, urutan kunci: pasien → kunjungan (KunciKunjungan) → penempatan aktif → rencana pulang → TT → episode.
 * Butir wajib manual yang belum selesai harus diberi alasan per butir (422 daftar_periksa_belum_lengkap); butir sumber
 * kejadian (tagihan/jaminan) menunggu kasir dan tidak menahan. Alasan klinis dan alasan butir: tidak ke log/audit.
 */
final class PulangkanPasien
{
    public const POS = 'rawat_inap';

    public const TUJUAN = ['pulang', 'aps', 'rujuk_keluar', 'meninggal'];

    public const TUGAS_RESUME = 'RESUME_BELUM_TTE_48J';

    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly StatusInstalasi $instalasi,
        private readonly UbahStatusTempatTidur $statusTt,
        private readonly KelolaRelasiPerawatan $relasi,
        private readonly KotakKerja $kotakKerja,
    ) {}

    public function izinkan(Pengguna $pengguna, string $tujuan, string $kunjunganId): void
    {
        $aturan = self::aturan($tujuan);
        Gate::forUser($pengguna)->authorize($aturan->izin_perintah);
        $k = KelolaRencanaPulang::kunjungan($kunjunganId);
        Gate::forUser($pengguna)->authorize($aturan->izin_perintah, KelolaRencanaPulang::cakupan($k->unit_id));
    }

    /**
     * @param  array{tujuan: string, alasan: string, tanggapan?: list<array{kode: string, alasan: string}>}  $isi  sudah divalidasi bentuknya
     * @return string id perintah (kunjungan.perpindahan_pos)
     */
    public function pulangkan(Pengguna $pengguna, string $kunjunganId, array $isi): string
    {
        KelolaPasien::pastikanSiapMelayani($this->instalasi);
        $alasanButir = array_column($isi['tanggapan'] ?? [], 'alasan', 'kode');

        return DB::transaction(function () use ($pengguna, $kunjunganId, $isi, $alasanButir) {
            $k = KunciKunjungan::pasienLaluKunjungan($kunjunganId) ?? throw new ApiError(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.');
            $aturan = self::aturan($isi['tujuan']);
            Gate::forUser($pengguna)->authorize($aturan->izin_perintah, KelolaRencanaPulang::cakupan($k->unit_id));
            KelolaRencanaPulang::pastikanRawatInapAktif($k);
            $penempatan = DB::table('kunjungan.penempatan_tempat_tidur')->where('kunjungan_id', $k->id)->whereNull('selesai_pada')->lockForUpdate()
                ->first(['id', 'tempat_tidur_id'])
                ?? throw new ApiError(409, 'status_tidak_sesuai', 'Pasien ini tidak menempati TT.', [['jenis_kunjungan' => $k->jenis_kunjungan, 'status' => $k->status]]);

            $rencana = DB::table('kunjungan.rencana_pulang')->where('kunjungan_id', $k->id)->lockForUpdate()->first(['id', 'daftar_periksa']);
            $butir = $rencana === null ? KelolaRencanaPulang::salinDataInduk() : KelolaRencanaPulang::butirTersimpan($rencana->daftar_periksa);
            $butir = self::periksaDaftar($butir, $alasanButir);

            $tt = DB::table('inti.tempat_tidur')->where('id', $penempatan->tempat_tidur_id)->lockForUpdate()->first(['id', 'kode']);
            $episode = Episode::query()->whereKey($k->episode_id)->lockForUpdate()->firstOrFail();
            $jam = now()->utc()->format('Y-m-d H:i:s.uP');
            if ($episode->status === 'aktif') {
                $episode->forceFill(['status' => 'selesai', 'selesai_pada' => $jam, 'diubah_oleh' => $pengguna->id, 'revisi' => $episode->revisi + 1])->save();
            }
            $perpindahan = (string) Str::uuid7();
            DB::table('kunjungan.perpindahan_pos')->insert([
                'id' => $perpindahan, 'episode_id' => $episode->id, 'dari_kunjungan_id' => $k->id, 'aturan_perpindahan_id' => $aturan->id,
                'pos_asal' => $aturan->pos_asal, 'pos_tujuan' => $aturan->pos_tujuan, 'izin_perintah' => $aturan->izin_perintah,
                'alasan' => trim($isi['alasan']), 'serah_terima' => null, 'status' => 'selesai',
                'episode_jenis' => $episode->jenis, 'episode_status' => $episode->status, 'diputuskan_oleh' => $pengguna->id,
                'terjadi_pada' => $jam, 'dibuat_pada' => $jam,
            ]);
            DB::table('kunjungan.penempatan_tempat_tidur')->where('id', $penempatan->id)->update([
                'selesai_pada' => $jam, 'alasan_selesai' => 'pulang', 'diakhiri_oleh' => $pengguna->id,
                'diubah_oleh' => $pengguna->id, 'diubah_pada' => $jam, 'revisi' => DB::raw('revisi + 1'),
            ]);
            // FR-05-05: TT otomatis "dibersihkan" sesudah pasien keluar.
            $this->statusTt->ubah($penempatan->tempat_tidur_id, StatusTempatTidur::DIBERSIHKAN, StatusTempatTidur::SUMBER_PULANG, $pengguna->id, StatusTempatTidur::DITEMPATI);
            $statusLama = $k->status;
            $k->forceFill(['status' => 'selesai', 'diubah_oleh' => $pengguna->id, 'revisi' => $k->revisi + 1])->save();

            $nilaiRencana = ['daftar_periksa' => json_encode($butir), 'status' => 'pulang', 'diubah_oleh' => $pengguna->id, 'diubah_pada' => $jam];
            if ($rencana === null) {
                // Pulang tanpa rencana: rencana dibuat otomatis (tanggal = hari pulang) agar daftar periksa tetap tercatat.
                DB::table('kunjungan.rencana_pulang')->insert([...$nilaiRencana, 'id' => (string) Str::uuid7(), 'kunjungan_id' => $k->id,
                    'rencana_pulang_pada' => now(config('instalasi.zona_waktu'))->toDateString(), 'dibuat_oleh' => $pengguna->id, 'dibuat_pada' => $jam]);
            } else {
                DB::table('kunjungan.rencana_pulang')->where('id', $rencana->id)->update([...$nilaiRencana, 'revisi' => DB::raw('revisi + 1')]);
            }
            // Catatan wajib 2.29: relasi dpjp_rawat_inap tanpa akhir diakhiri saat pulang.
            $this->relasi->akhiri('kunjungan', $k->id, 'Pasien keluar rawat inap', $pengguna->id);

            // FR-05-04: resume belum sah → tugas DPJP (tenggat dari jenis tugas, bawaan 2 × 24 jam). Judul tanpa data pasien.
            $tugas = null;
            if (DB::table('klinis.resume_medis')->where('kunjungan_id', $k->id)->value('status') !== 'sah') {
                $akunDpjp = $k->pegawai_id === null ? null
                    : Pengguna::query()->where('pegawai_id', $k->pegawai_id)->where('status', 'aktif')->orderBy('id')->value('id');
                $tugas = $this->kotakKerja->buat(self::TUGAS_RESUME, $k->id, "Sahkan resume medis kunjungan {$k->nomor}",
                    $akunDpjp === null ? ['unit', (string) $k->unit_id] : ['pengguna', (string) $akunDpjp], $k->unit_id);
            }

            PasienPulang::dispatch($perpindahan, $k->id, $k->pasien_id, $episode->id, $isi['tujuan'], $penempatan->tempat_tidur_id, $jam);
            $this->audit->catat('rawat_inap.pasien_pulang', $pengguna->id, 'kunjungan.perpindahan_pos', $perpindahan, [
                'kunjungan_id' => $k->id, 'nomor' => $k->nomor, 'pos_tujuan' => $aturan->pos_tujuan, 'aturan_perpindahan_id' => $aturan->id,
                'penempatan_id' => $penempatan->id, 'tempat_tidur_id' => $penempatan->tempat_tidur_id, 'kode' => $tt?->kode,
                'episode_id' => $episode->id, 'status_kunjungan' => 'selesai', 'tugas_resume_id' => $tugas?->id,
                'butir_dengan_alasan' => array_values(array_map(fn (array $b) => $b['kode'], array_filter($butir, fn (array $b) => $b['alasan_tidak_selesai'] !== null))),
            ], ['status_kunjungan' => $statusLama]);

            return $perpindahan;
        });
    }

    private static function aturan(string $tujuan): AturanPerpindahan
    {
        return AturanPerpindahan::berlaku(self::POS, $tujuan)
            ?? throw new ApiError(422, 'perpindahan_di_luar_matriks', 'Tujuan ini tidak ada di matriks perpindahan aktif untuk rawat inap.',
                [['kolom' => 'tujuan', 'pos_asal' => self::POS, 'pos_tujuan' => $tujuan, 'pesan' => 'Pilih tujuan yang diizinkan matriks perpindahan.']]);
    }

    /**
     * Butir wajib manual yang belum selesai butuh alasan; alasannya disimpan di butir. Alasan untuk butir lain diabaikan.
     *
     * @param  list<array{kode: string, nama: string, sumber: string, wajib: bool, selesai_pada: string|null, selesai_oleh: string|null, alasan_tidak_selesai: string|null}>  $butir
     * @param  array<string, string>  $alasan  kode => alasan
     * @return list<array{kode: string, nama: string, sumber: string, wajib: bool, selesai_pada: string|null, selesai_oleh: string|null, alasan_tidak_selesai: string|null}>
     */
    private static function periksaDaftar(array $butir, array $alasan): array
    {
        $kurang = [];
        foreach ($butir as $i => $b) {
            if ($b['sumber'] === DaftarPeriksaPulang::SUMBER_KEJADIAN || ! $b['wajib'] || $b['selesai_pada'] !== null) {
                continue;
            }
            $teks = trim((string) ($alasan[$b['kode']] ?? ''));
            if ($teks === '') {
                $kurang[] = ['kolom' => 'tanggapan', 'kode' => $b['kode'], 'nama' => $b['nama'], 'pesan' => 'Selesaikan butir ini atau tulis alasan belum selesai.'];

                continue;
            }
            $butir[$i]['alasan_tidak_selesai'] = $teks;
        }
        if ($kurang !== []) {
            throw new ApiError(422, 'daftar_periksa_belum_lengkap', 'Ada butir wajib daftar periksa pulang yang belum selesai dan belum diberi alasan.', $kurang);
        }

        return $butir;
    }
}
