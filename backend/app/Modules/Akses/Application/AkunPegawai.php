<?php

namespace App\Modules\Akses\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Sdm\Events\PegawaiKeluar;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Akun milik pegawai (tiket 1.8d; K5, PRD 9.2.6): membuat akun dari pegawai dengan sandi awal sekali tampil, dan
 * menonaktifkan akun saat pegawainya keluar. Akun pemegang superadmin tidak pernah diubah lewat jalur ini (K4).
 */
final class AkunPegawai
{
    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly PenjagaPenyunting $penjaga,
    ) {}

    /** Akun pegawai ini memegang superadmin (penugasan aktif, berlaku atau akan berlaku). $kunci: akun dikunci FOR UPDATE. */
    public function memegangSuperadmin(string $pegawaiId, bool $kunci = false): bool
    {
        return Pengguna::query()->where('pegawai_id', $pegawaiId)->when($kunci, fn ($q) => $q->lockForUpdate())->get()
            ->contains(fn (Pengguna $p) => $this->penjaga->superadmin($p, termasukMasaDepan: true));
    }

    /**
     * Buat akun untuk pegawai aktif; sandi awal acak dikembalikan sekali (tidak disimpan terbaca, tidak dicatat).
     *
     * @return array{0: Pengguna, 1: string} akun dan sandi awal
     */
    public function buat(Pengguna $penyunting, string $pegawaiId, string $namaPengguna, ?string $email, string $dasar): array
    {
        try {
            return DB::transaction(function () use ($penyunting, $pegawaiId, $namaPengguna, $email, $dasar) {
                $pegawai = Pegawai::query()->whereKey($pegawaiId)->lockForUpdate()->firstOrFail();
                if ($pegawai->status !== 'aktif' || $pegawai->statusKepegawaian() === 'keluar') {
                    throw new ApiError(409, 'pegawai_keluar', 'Pegawai ini sudah keluar; akun tidak dibuat.');
                }
                if (Pengguna::query()->where('pegawai_id', $pegawai->id)->exists()) {
                    throw new ApiError(409, 'pegawai_sudah_punya_akun', 'Pegawai ini sudah punya akun (satu akun untuk satu pegawai).');
                }

                $sandi = self::sandiAwal();
                $akun = new Pengguna(['pegawai_id' => $pegawai->id, 'nama_pengguna' => $namaPengguna, 'email' => $email]);
                $akun->forceFill([
                    'sandi_hash' => Hash::make($sandi), 'status' => 'aktif', 'wajib_ganti_sandi' => true,
                    'dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id,
                ])->save();
                $this->audit->catat('akses.akun_dibuat', $penyunting->id, 'akses.pengguna', $akun->id, [
                    'pegawai_id' => $pegawai->id, 'nama_pengguna' => $namaPengguna, 'dasar_pemberian' => $dasar,
                ]);

                return [$akun, $sandi];
            });
        } catch (QueryException $e) {
            throw $e->getCode() === '23505'
                ? new ApiError(409, 'nama_pengguna_dipakai', 'Nama pengguna sudah dipakai.', [['kolom' => 'nama_pengguna', 'pesan' => 'Pilih nama pengguna lain.']])
                : $e;
        }
    }

    /** Pendengar PegawaiKeluar (sinkron, di dalam transaksi pemanggil): akun nonaktif dan sesinya diputus. */
    public function nonaktifkanKarenaKeluar(PegawaiKeluar $kejadian): void
    {
        foreach (Pengguna::query()->where('pegawai_id', $kejadian->pegawaiId)->where('status', 'aktif')->lockForUpdate()->get() as $akun) {
            // Pagar kedua K4: superadmin tidak pernah dinonaktifkan lewat jalur ini.
            if ($this->penjaga->superadmin($akun, termasukMasaDepan: true)) {
                continue;
            }
            $akun->forceFill(['status' => 'nonaktif', 'diubah_oleh' => $kejadian->olehId])->save();
            $akun->cabutSesi();
            $this->audit->catat('akses.akun_dinonaktifkan', $kejadian->olehId, 'akses.pengguna', $akun->id, ['alasan' => 'pegawai_keluar']);
        }
    }

    /** Sama dengan sandi sementara reset (ResetSandi): tidak lebih pendek dari panjang minimal instalasi (PRD 14.2). */
    private static function sandiAwal(): string
    {
        return ResetSandi::sandiSementara();
    }
}
