<?php

namespace App\Modules\Notifikasi\Application;

use App\Modules\Akses\Events\AkunTerkunci;
use App\Modules\Akses\Events\PermintaanAksesDibuat;
use App\Modules\Akses\Events\PermintaanAksesDitutup;
use App\Modules\Akses\Events\PersetujuanDiajukan;
use App\Modules\Akses\Events\PersetujuanDitutup;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\Peran;
use App\Modules\Sdm\Events\IzinPraktikDibatalkan;
use App\Modules\Sdm\Events\IzinPraktikDicatat;
use App\Modules\Sdm\Models\IzinPraktik;
use App\Modules\Ttd\Events\TtdDicabut;
use App\Modules\Ttd\Events\TtdTerkunci;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Kejadian domain → tugas dan notifikasi (tiket 1.7). Sinkron di dalam transaksi pemanggil: tugas lahir dan selesai
 * bersama kejadiannya. Judul dan isi tanpa data pasien, sandi, atau alamat IP.
 */
final class PendengarKejadian
{
    public const AKUN_TERKUNCI = 'AKUN_TERKUNCI';

    public const TTD_TERKUNCI = 'TTD_TERKUNCI';

    public const TTD_DICABUT = 'TTD_DICABUT';

    public function __construct(
        private readonly KotakKerja $kotakKerja,
        private readonly Pemberitahu $pemberitahu,
    ) {}

    public function persetujuanDiajukan(PersetujuanDiajukan $e): void
    {
        $this->kotakKerja->buat('PERSETUJUAN_AKSES_MENUNGGU', $e->persetujuanId, "Persetujuan akses {$e->nomor} menunggu keputusan",
            $e->penyetujuPenggunaId !== null ? ['pengguna', $e->penyetujuPenggunaId] : ['peran', (string) $e->penyetujuPeranId]);
    }

    public function persetujuanDitutup(PersetujuanDitutup $e): void
    {
        foreach ($e->persetujuanId as $id) {
            $this->kotakKerja->selesaikan('PERSETUJUAN_AKSES_MENUNGGU', $id, 'Persetujuan '.$e->status, $e->status === 'dibatalkan' ? 'batal' : 'selesai');
        }
    }

    public function permintaanDibuat(PermintaanAksesDibuat $e): void
    {
        // Pemberi kewenangan akses: setiap peran aktif yang memegang pengaturan.akun.perbaiki (satu tugas per peran).
        $peran = Peran::query()->where('status', 'aktif')->whereIn('id', DB::table('akses.peran_izin')->where('status', 'aktif')
            ->whereIn('izin_id', DB::table('akses.izin')->where('kode', 'pengaturan.akun.perbaiki')->select('id'))->select('peran_id'))
            ->orderBy('kode')->pluck('id');
        foreach ($peran as $id) {
            $this->kotakKerja->buat('PERMINTAAN_AKSES', $e->permintaanId, "Permintaan akses {$e->nomor} menunggu tindak lanjut", ['peran', (string) $id]);
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

    /** TTE terkunci karena PIN salah berulang (1.6): kritis ke pemilik dan atasan langsung. */
    public function ttdTerkunci(TtdTerkunci $e): void
    {
        $sampai = Carbon::parse($e->terkunciSampai)->setTimezone((string) config('instalasi.zona_waktu'))->format('H:i');
        $this->pemberitahu->kirim([$e->penggunaId], self::TTD_TERKUNCI, 'Tanda tangan elektronik Anda terkunci',
            "Tanda tangan elektronik Anda terkunci sampai pukul {$sampai} karena PIN salah beberapa kali. Bila itu bukan Anda, segera hubungi atasan dan SDM.",
            null, 'kritis', null, true);
        if ($e->atasanId !== null) {
            $nama = Pengguna::query()->whereKey($e->penggunaId)->value('nama_pengguna');
            $this->pemberitahu->kirim([$e->atasanId], self::TTD_TERKUNCI, 'Tanda tangan elektronik staf terkunci',
                "Tanda tangan elektronik akun {$nama} terkunci sampai pukul {$sampai} karena PIN salah beberapa kali.", null, 'kritis', null, true);
        }
    }

    /** Reset TTE diterapkan (1.6): pemilik diberi tahu agar mengaktifkan ulang. */
    public function ttdDicabut(TtdDicabut $e): void
    {
        $this->pemberitahu->kirim([$e->penggunaId], self::TTD_DICABUT, 'Tanda tangan elektronik Anda direset',
            "Tanda tangan elektronik Anda direset ({$e->nomorPersetujuan}). Aktifkan ulang dengan PIN baru sebelum menandatangani.",
            null, 'penting', null, true);
    }

    /**
     * Pemberitahuan ke pemilik akun (issue #11): kritis, paling banyak satu per jam per akun (in-app dan email), agar
     * penguncian sengaja berulang tidak membanjiri pemilik dengan notifikasi kritis yang tidak bisa dibaca-semua.
     */
    public function akunTerkunci(AkunTerkunci $e): void
    {
        // Dari tabel (bukan cache LRU): batas tidak jebol bila kunci cache tergusur, dan ikut transaksi pemanggil.
        $baruSaja = DB::table('notifikasi.notifikasi')->where('pengguna_id', $e->penggunaId)->where('jenis', self::AKUN_TERKUNCI)
            ->where('dibuat_pada', '>', now()->subHour()->format('Y-m-d H:i:s.uP'))->exists();
        if ($baruSaja) {
            return;
        }
        $sampai = Carbon::parse($e->terkunciSampai)->setTimezone((string) config('instalasi.zona_waktu'))->format('H:i');
        // Penghitung yang sama menghitung sandi dan kode MFA yang salah: pesan tidak menyebut sandi saja.
        $this->pemberitahu->kirim([$e->penggunaId], self::AKUN_TERKUNCI, 'Akun Anda terkunci sementara',
            "Akun Anda terkunci sampai pukul {$sampai} karena sandi atau kode verifikasi salah beberapa kali. Bila itu bukan Anda, segera ganti sandi dan hubungi admin.",
            null, 'kritis', null, true);
    }
}
