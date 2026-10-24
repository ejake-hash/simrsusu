<?php

namespace App\Modules\Pendaftaran\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Pendaftaran\Models\Pasien;
use App\Modules\Pendaftaran\Models\UsulanGabung;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Gabung RM ganda lewat usulan berjenjang (tiket 2.9; FR-01-02), pola mesin usulan 1.8c: pengusul ≠ pemverifikasi,
 * tahap dibaca ulang dengan kunci baris. Verifikasi "setuju" memindahkan kunjungan, episode, dan relasi perawatan ke RM
 * tujuan dalam satu transaksi; RM sumber ditandai `digabung_ke_id`, tidak dihapus. Audit tanpa nama atau identitas.
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
                $this->audit->catat('pasien.gabung_diusulkan', $pengusul->id, 'pasien.usulan_gabung', $u->id,
                    ['no_rm_sumber' => $sumber->no_rm, 'no_rm_tujuan' => $tujuan->no_rm]);

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
                $dataAudit = ['no_rm_sumber' => $sumber->no_rm, 'no_rm_tujuan' => $tujuan->no_rm, 'dipindah' => array_map('count', $rincian)];
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
            foreach ($tabel as $kunci => $nama) {
                $rincian[$kunci] = DB::table($nama)->where('pasien_id', $sumber->id)->pluck('id')->all();
                DB::table($nama)->where('pasien_id', $sumber->id)->update(['pasien_id' => $tujuan->id, 'diubah_pada' => now(), 'diubah_oleh' => $olehId]);
            }
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }
            throw new ApiError(409, 'gabung_bentrok', 'Kunjungan kedua RM bertumpuk (poli dan tanggal sama, atau episode harian sama). Batalkan salah satu kunjungan yang ganda, lalu verifikasi ulang.');
        }
        $sumber->forceFill(['digabung_ke_id' => $tujuan->id, 'digabung_pada' => now(), 'revisi' => $sumber->revisi + 1, 'diubah_oleh' => $olehId])->save();

        return $rincian;
    }

    private static function validasi(string $kolom, string $pesan): ApiError
    {
        return new ApiError(422, 'validasi_gagal', 'Isian belum benar.', [['kolom' => $kolom, 'pesan' => $pesan]]);
    }
}
