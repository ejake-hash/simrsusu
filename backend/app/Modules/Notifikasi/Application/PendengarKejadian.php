<?php

namespace App\Modules\Notifikasi\Application;

use App\Modules\Akses\Database\MenuBawaan;
use App\Modules\Akses\Events\AkunTerkunci;
use App\Modules\Akses\Events\PermintaanAksesDibuat;
use App\Modules\Akses\Events\PermintaanAksesDitutup;
use App\Modules\Akses\Events\PersetujuanDiajukan;
use App\Modules\Akses\Events\PersetujuanDitutup;
use App\Modules\Akses\Models\Peran;
use App\Modules\Sdm\Events\IzinPraktikDibatalkan;
use App\Modules\Sdm\Events\IzinPraktikDicatat;
use App\Modules\Sdm\Models\IzinPraktik;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Kejadian domain → tugas dan notifikasi (tiket 1.7). Sinkron di dalam transaksi pemanggil: tugas lahir dan selesai
 * bersama kejadiannya. Judul dan isi tanpa data pasien, sandi, atau alamat IP.
 */
final class PendengarKejadian
{
    public const AKUN_TERKUNCI = 'AKUN_TERKUNCI';

    public function __construct(
        private readonly KotakKerja $kotakKerja,
        private readonly Pemberitahu $pemberitahu,
    ) {}

    public function persetujuanDiajukan(PersetujuanDiajukan $e): void
    {
        $this->kotakKerja->buat('PERSETUJUAN_AKSES_MENUNGGU', $e->persetujuanId, "Persetujuan akses {$e->nomor} menunggu keputusan",
            ['peran', $e->penyetujuPeranId]);
    }

    public function persetujuanDitutup(PersetujuanDitutup $e): void
    {
        foreach ($e->persetujuanId as $id) {
            $this->kotakKerja->selesaikan('PERSETUJUAN_AKSES_MENUNGGU', $id, 'Persetujuan '.$e->status, $e->status === 'dibatalkan' ? 'batal' : 'selesai');
        }
    }

    public function permintaanDibuat(PermintaanAksesDibuat $e): void
    {
        // Pemberi kewenangan akses: pemegang peran superadmin (bawaan pemegang pengaturan.akun.perbaiki).
        $superadmin = Peran::query()->where('kode', MenuBawaan::PERAN_SUPERADMIN)->value('id');
        if ($superadmin !== null) {
            $this->kotakKerja->buat('PERMINTAAN_AKSES', $e->permintaanId, "Permintaan akses {$e->nomor} menunggu tindak lanjut", ['peran', (string) $superadmin]);
        }
    }

    public function permintaanDitutup(PermintaanAksesDitutup $e): void
    {
        $this->kotakKerja->selesaikan('PERMINTAAN_AKSES', $e->permintaanId, 'Permintaan '.$e->status);
    }

    /** Izin pengganti dicatat: tugas "akan berakhir" untuk izin lama jenis yang sama selesai. */
    public function izinPraktikDicatat(IzinPraktikDicatat $e): void
    {
        $baru = IzinPraktik::query()->find($e->izinPraktikId);
        $lama = IzinPraktik::query()->where('pegawai_id', $e->pegawaiId)->where('jenis', $e->jenis)->where('id', '!=', $e->izinPraktikId)
            ->when($baru?->berlaku_sampai !== null, fn ($q) => $q->where('berlaku_sampai', '<', $baru->berlaku_sampai->toDateString()))->pluck('id');
        foreach ($lama as $id) {
            $this->kotakKerja->selesaikan('IZIN_PRAKTIK_BERAKHIR', (string) $id, 'Izin pengganti dicatat');
        }
    }

    public function izinPraktikDibatalkan(IzinPraktikDibatalkan $e): void
    {
        $this->kotakKerja->selesaikan('IZIN_PRAKTIK_BERAKHIR', $e->izinPraktikId, 'Catatan izin dibatalkan', 'batal');
    }

    /** Pemberitahuan ke pemilik akun (issue #11): kritis; email paling banyak satu per jam per akun. */
    public function akunTerkunci(AkunTerkunci $e): void
    {
        $sampai = Carbon::parse($e->terkunciSampai)->setTimezone((string) config('instalasi.zona_waktu'))->format('H:i');
        $email = Cache::add('notifikasi:akun_terkunci:'.$e->penggunaId, 1, 3600);
        $this->pemberitahu->kirim([$e->penggunaId], self::AKUN_TERKUNCI, 'Akun Anda terkunci sementara',
            "Akun Anda terkunci sampai pukul {$sampai} karena sandi salah beberapa kali. Bila itu bukan Anda, segera hubungi admin.",
            null, 'kritis', null, $email);
    }
}
