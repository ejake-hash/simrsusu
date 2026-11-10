<?php

namespace App\Modules\Pendaftaran\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Pendaftaran\Models\Episode;
use App\Modules\Pendaftaran\Models\IdentitasPasien;
use App\Modules\Pendaftaran\Models\Pasien;
use App\Modules\Pendaftaran\Models\UsulanGabung;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gabung RM ganda lewat usulan berjenjang (tiket 2.9; FR-01-02), pola mesin usulan 1.8c: pengusul ≠ pemverifikasi,
 * tahap dibaca ulang dengan kunci baris. Verifikasi "setuju" memindahkan kunjungan, episode, dan relasi perawatan ke RM
 * tujuan dalam satu transaksi (episode harian bertanggal sama disatukan, nomor identitas ikut pindah); RM sumber ditandai
 * `digabung_ke_id`, tidak dihapus. Audit hanya memuat id (tanpa nama, No. RM, atau identitas).
 */
final class GabungPasien
{
    public function __construct(private readonly CatatAktivitas $audit) {}

    public function usulkan(Pengguna $pengusul, string $sumberId, string $tujuanId, string $alasan): UsulanGabung
    {
        if ($sumberId === $tujuanId) {
            throw self::validasi('pasien_tujuan_id', 'RM tujuan harus berbeda dari RM yang digabung.');
        }

        try {
            return DB::transaction(function () use ($pengusul, $sumberId, $tujuanId, $alasan) {
                [$sumber, $tujuan] = $this->kunciPasien($sumberId, $tujuanId);
                $this->pastikanBisaGabung($sumber, $tujuan);
                $terbuka = UsulanGabung::query()->where('tahap', 'diusulkan')
                    ->where(fn ($q) => $q->whereIn('sumber_id', [$sumber->id, $tujuan->id])->orWhereIn('tujuan_id', [$sumber->id, $tujuan->id]))->exists();
                if ($terbuka) {
                    throw new ApiError(409, 'usulan_gabung_terbuka', 'Salah satu RM ini sudah ada di usulan gabung yang belum diputuskan.');
                }
                $u = new UsulanGabung(['sumber_id' => $sumber->id, 'tujuan_id' => $tujuan->id, 'alasan' => $alasan]);
                $u->forceFill(['diusulkan_oleh' => $pengusul->id, 'diusulkan_pada' => now(), 'dibuat_oleh' => $pengusul->id, 'diubah_oleh' => $pengusul->id])->save();
                $this->audit->catat('pasien.gabung_diusulkan', $pengusul->id, 'pasien.usulan_gabung', $u->id, ['sumber_id' => $sumber->id, 'tujuan_id' => $tujuan->id]);

                return $u;
            });
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }
            throw new ApiError(409, 'usulan_gabung_terbuka', 'Salah satu RM ini sudah ada di usulan gabung yang belum diputuskan.');
        }
    }

    public function verifikasi(Pengguna $pemverifikasi, string $usulanId, bool $setuju, ?string $catatan): UsulanGabung
    {
        return DB::transaction(function () use ($pemverifikasi, $usulanId, $setuju, $catatan) {
            $u = UsulanGabung::query()->whereKey($usulanId)->lockForUpdate()->first()
                ?? throw new ApiError(404, 'tidak_ditemukan', 'Usulan gabung tidak ditemukan.');
            if ($u->diusulkan_oleh === $pemverifikasi->id) {
                throw new ApiError(403, 'bukan_pemverifikasi', 'Pengusul tidak bisa memverifikasi usulannya sendiri.');
            }
            if ($u->tahap !== 'diusulkan') {
                throw new ApiError(409, 'tahap_tidak_sesuai', 'Usulan ini sudah diputuskan.');
            }
            $rincian = null;
            $dataAudit = ['alasan_tolak_diisi' => $catatan !== null];
            if ($setuju) {
                [$sumber, $tujuan] = $this->kunciPasien($u->sumber_id, $u->tujuan_id);
                $this->pastikanBisaGabung($sumber, $tujuan);
                $rincian = $this->pindahkan($sumber, $tujuan, $pemverifikasi->id);
                $dataAudit = ['sumber_id' => $sumber->id, 'tujuan_id' => $tujuan->id, 'dipindah' => array_map('count', $rincian)];
            }
            $u->forceFill(['tahap' => $setuju ? 'digabung' : 'ditolak', 'diverifikasi_oleh' => $pemverifikasi->id, 'diverifikasi_pada' => now(),
                'catatan_verifikasi' => $catatan, 'rincian_pindah' => $rincian, 'diubah_oleh' => $pemverifikasi->id, 'revisi' => $u->revisi + 1])->save();
            $this->audit->catat($setuju ? 'pasien.gabung_diterapkan' : 'pasien.gabung_ditolak', $pemverifikasi->id, 'pasien.usulan_gabung', $u->id, $dataAudit);

            return $u;
        });
    }

    /** @return array{0: Pasien, 1: Pasien} sumber dan tujuan, dikunci berurutan id agar dua verifikasi bersilang tidak buntu */
    private function kunciPasien(string $sumberId, string $tujuanId): array
    {
        $baris = Pasien::query()->whereIn('id', [$sumberId, $tujuanId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $sumber = $baris->get($sumberId);
        $tujuan = $baris->get($tujuanId);
        if ($sumber === null || $tujuan === null) {
            throw new ApiError(404, 'tidak_ditemukan', 'Pasien tidak ditemukan.');
        }

        return [$sumber, $tujuan];
    }

    private function pastikanBisaGabung(Pasien $sumber, Pasien $tujuan): void
    {
        if ($sumber->digabung_ke_id !== null || $tujuan->digabung_ke_id !== null) {
            throw new ApiError(409, 'pasien_sudah_digabung', 'Salah satu RM sudah digabung ke RM lain. Pilih RM yang masih berlaku.');
        }
    }

    /** @return array{kunjungan: list<string>, episode: list<string>, relasi_perawatan: list<string>} */
    private function pindahkan(Pasien $sumber, Pasien $tujuan, string $olehId): array
    {
        $tabel = ['kunjungan' => 'kunjungan.kunjungan', 'episode' => 'kunjungan.episode', 'relasi_perawatan' => 'akses.relasi_perawatan'];
        $rincian = [];
        try {
            $this->satukanEpisodeHarian($sumber, $tujuan, $olehId);
            foreach ($tabel as $kunci => $nama) {
                $rincian[$kunci] = DB::table($nama)->where('pasien_id', $sumber->id)->pluck('id')->all();
                DB::table($nama)->where('pasien_id', $sumber->id)->update(['pasien_id' => $tujuan->id, 'diubah_pada' => now(), 'diubah_oleh' => $olehId]);
            }
            // Salinan pasien_id untuk keunikan per pasien (2.29) ikut kunjungannya.
            foreach (['kunjungan.permintaan_tempat_tidur', 'kunjungan.penempatan_tempat_tidur'] as $nama) {
                DB::table($nama)->where('pasien_id', $sumber->id)->update(['pasien_id' => $tujuan->id, 'diubah_pada' => now(), 'diubah_oleh' => $olehId]);
            }
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }
            if (str_contains($e->getMessage(), 'tempat_tidur_pasien_aktif_unik')) {
                throw new ApiError(409, 'gabung_bentrok', 'Kedua RM punya permintaan kamar atau penempatan TT aktif; selesaikan atau batalkan salah satu dulu.');
            }
            if (str_contains($e->getMessage(), 'kunjungan_igd_aktif_unik')) {
                throw new ApiError(409, 'gabung_bentrok', 'Kedua RM masih tercatat di IGD; selesaikan atau batalkan salah satu dulu.');
            }
            throw new ApiError(409, 'gabung_bentrok', 'Kunjungan kedua RM bertumpuk (jadwal dan tanggal sama). Batalkan salah satu kunjungan yang ganda, lalu verifikasi ulang.');
        }
        $this->pindahkanIdentitas($sumber, $tujuan, $olehId);
        $sumber->forceFill(['digabung_ke_id' => $tujuan->id, 'digabung_pada' => now(), 'revisi' => $sumber->revisi + 1, 'diubah_oleh' => $olehId])->save();

        return $rincian;
    }

    /**
     * Episode rawat jalan harian kedua RM di tanggal yang sama (indeks episode_rawat_jalan_harian_unik): kunjungan sumber
     * diarahkan ke episode tujuan, episode sumber batal (tidak dihapus). Kunjungan yang sudah dilayani tidak perlu dibatalkan.
     */
    private function satukanEpisodeHarian(Pasien $sumber, Pasien $tujuan, string $olehId): void
    {
        $pasangan = DB::table('kunjungan.episode AS s')
            ->join('kunjungan.episode AS t', fn ($j) => $j->on('t.mulai_tanggal', '=', 's.mulai_tanggal')->on('t.jenis', '=', 's.jenis'))
            ->where('s.pasien_id', $sumber->id)->where('t.pasien_id', $tujuan->id)->where('s.jenis', Episode::RAWAT_JALAN_HARIAN)
            ->where('s.status', '<>', 'batal')->where('t.status', '<>', 'batal')->select('s.id AS sumber', 't.id AS tujuan')->pluck('tujuan', 'sumber');
        foreach ($pasangan as $episodeSumber => $episodeTujuan) {
            DB::table('kunjungan.kunjungan')->where('episode_id', $episodeSumber)
                ->update(['episode_id' => $episodeTujuan, 'diubah_pada' => now(), 'diubah_oleh' => $olehId]);
            DB::table('kunjungan.episode')->where('id', $episodeSumber)->update([
                'status' => 'batal', 'alasan_batal' => 'Digabung ke RM '.$tujuan->no_rm, 'dibatalkan_oleh' => $olehId, 'dibatalkan_pada' => now(),
                'diubah_oleh' => $olehId, 'diubah_pada' => now(), 'revisi' => DB::raw('revisi + 1'),
            ]);
        }
    }

    /**
     * NIK/paspor/kartu BPJS RM sumber: nilai jenis yang belum dimiliki tujuan pindah ke tujuan; semua nilai di sumber
     * dikosongkan (pola tabel identitas) dan isi lamanya disimpan terenkripsi di pasien_versi sumber.
     */
    private function pindahkanIdentitas(Pasien $sumber, Pasien $tujuan, string $olehId): void
    {
        $identitas = IdentitasPasien::query()->where('pasien_id', $sumber->id)->whereNotNull('sidik')->orderBy('jenis')->lockForUpdate()->get();
        if ($identitas->isEmpty()) {
            return;
        }
        $milikTujuan = IdentitasPasien::query()->where('pasien_id', $tujuan->id)->whereNotNull('sidik')->orderBy('jenis')->get();
        $dimiliki = $milikTujuan->pluck('jenis')->all();
        $this->simpanVersiIdentitas($sumber, $identitas, 'Digabung ke RM '.$tujuan->no_rm.': nomor identitas dipindah atau dikosongkan', $olehId);
        $dipindah = false;
        foreach ($identitas as $baris) {
            $nilai = $baris->nilai_enkripsi;
            // Dikosongkan dulu: indeks identitas_sidik_unik menolak nilai yang sama di dua RM.
            $baris->aturNilai(null);
            $baris->forceFill(['revisi' => $baris->revisi + 1, 'diubah_oleh' => $olehId])->save();
            if (in_array($baris->jenis, $dimiliki, true)) {
                continue;
            }
            $baru = IdentitasPasien::query()->firstOrNew(['pasien_id' => $tujuan->id, 'jenis' => $baris->jenis]);
            $baru->aturNilai($nilai);
            $baru->forceFill(['revisi' => $baru->exists ? $baru->revisi + 1 : 0, 'diubah_oleh' => $olehId, 'dibuat_oleh' => $baru->exists ? $baru->dibuat_oleh : $olehId])->save();
            $dipindah = true;
        }
        if ($dipindah) {
            $this->simpanVersiIdentitas($tujuan, $milikTujuan, 'Menerima nomor identitas dari RM '.$sumber->no_rm.' (gabung RM)', $olehId);
            $tujuan->forceFill(['revisi' => $tujuan->revisi + 1, 'diubah_oleh' => $olehId])->save();
        }
    }

    /**
     * Jejak pasien_versi untuk revisi yang digantikan: nomor identitas (nama field kontrak) sebelum gabung, terenkripsi.
     *
     * @param  Collection<int, IdentitasPasien>  $identitas
     */
    private function simpanVersiIdentitas(Pasien $pasien, Collection $identitas, string $alasan, string $olehId): void
    {
        $field = array_flip(IdentitasPasien::JENIS);
        DB::table('pasien.pasien_versi')->insert([
            'id' => (string) Str::uuid7(), 'pasien_id' => $pasien->id, 'revisi' => $pasien->revisi,
            'isi_enkripsi' => Crypt::encryptString((string) json_encode($identitas->mapWithKeys(fn (IdentitasPasien $i) => [$field[$i->jenis] => $i->nilai_enkripsi])->all())),
            'alasan_perubahan' => $alasan, 'dibuat_pada' => now(), 'dibuat_oleh' => $olehId,
        ]);
    }

    private static function validasi(string $kolom, string $pesan): ApiError
    {
        return new ApiError(422, 'validasi_gagal', 'Isian belum benar.', [['kolom' => $kolom, 'pesan' => $pesan]]);
    }
}
