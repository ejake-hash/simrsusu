<?php

namespace App\Modules\Antrean\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Antrean\Events\AntreanBerubah;
use App\Modules\Antrean\Models\Antrean;
use App\Modules\MasterData\Models\Lokasi;
use App\Modules\MasterData\Models\Poliklinik;
use App\Modules\Pendaftaran\Events\KunjunganDibatalkan;
use App\Modules\Pendaftaran\Events\KunjunganDibuat;
use App\Modules\RekamMedis\Events\AsesmenPerawatSelesai;
use App\Modules\Pendaftaran\Models\Kunjungan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Antrean poli (tiket 2.8; PRD 7.02.1, FR-02-01/03/08/09/11/16). Baris antrean dikunci selama transisi; setiap transisi
 * menyimpan jam server dan pelaku di antrean_jejak. Batas panggilan, jeda, dan jumlah nomor yang didahulukan saat sisip
 * dibaca dari kunjungan.aturan_antrean (data induk), bukan konstanta.
 */
final class KelolaAntrean
{
    /** Kode area bawaan = kode poli huruf kecil; titik diganti tanda hubung agar cocok pola area di URL. */
    public const SQL_AREA_POLI = "coalesce(antrean_area, replace(lower(kode), '.', '-'))";

    public function __construct(private readonly CatatAktivitas $audit) {}

    /** @return object{batas_panggilan: int, jeda_panggilan_detik: int, sisip_setelah: int} */
    public static function aturan(): object
    {
        /** @var object{batas_panggilan: int, jeda_panggilan_detik: int, sisip_setelah: int} */
        return DB::table('kunjungan.aturan_antrean')->sole(['batas_panggilan', 'jeda_panggilan_detik', 'sisip_setelah']);
    }

    public static function areaPoli(Poliklinik $poli): string
    {
        return $poli->antrean_area ?? str_replace('.', '-', strtolower($poli->kode));
    }

    /** Pelanggan KunjunganDibuat (di dalam transaksi pendaftaran): nomor antrean poli `<prefiks>-<urut per hari>`. Aman diulang. */
    public function buatPoli(KunjunganDibuat $kejadian): void
    {
        DB::transaction(function () use ($kejadian) {
            $kunjungan = Kunjungan::query()->whereKey($kejadian->kunjunganId)->lockForUpdate()->first();
            if ($kunjungan === null || $kunjungan->status === 'batal'
                || Antrean::query()->where('kunjungan_id', $kunjungan->id)->where('titik', 'poli')->exists()) {
                return;
            }
            $poli = Poliklinik::query()->findOrFail($kunjungan->poli_id);
            $tanggal = $kunjungan->tanggal->toDateString();
            $prefiks = $poli->antrean_prefiks ?? $poli->kode;
            $nomor = (int) DB::selectOne('INSERT INTO kunjungan.nomor_antrean (tanggal, prefiks, terakhir) VALUES (?, ?, 1)
                ON CONFLICT (tanggal, prefiks) DO UPDATE SET terakhir = kunjungan.nomor_antrean.terakhir + 1 RETURNING terakhir', [$tanggal, $prefiks])->terakhir;

            $antrean = new Antrean;
            $antrean->forceFill([
                'tanggal' => $tanggal, 'kunjungan_id' => $kunjungan->id, 'titik' => 'poli', 'area' => self::areaPoli($poli),
                'prefiks' => $prefiks, 'nomor' => $nomor, 'nomor_tampil' => sprintf('%s-%03d', $prefiks, $nomor),
                'poli_id' => $poli->id, 'pegawai_id' => $kunjungan->pegawai_id, 'urutan' => (string) $nomor,
                'dibuat_oleh' => $kunjungan->dibuat_oleh, 'diubah_oleh' => $kunjungan->dibuat_oleh,
            ])->save();
            $this->jejak($antrean, $kunjungan->dibuat_oleh, now());
        });
    }

    /** Pelanggan KunjunganDibatalkan (di dalam transaksi pembatalan): antrean kunjungan itu ikut batal. */
    public function batalkanKunjungan(KunjunganDibatalkan $kejadian): void
    {
        $antrean = Antrean::query()->where('kunjungan_id', $kejadian->kunjunganId)
            ->whereIn('status', ['belum_check_in', 'menunggu', 'dipanggil', 'dilewati'])->lockForUpdate()->get();
        foreach ($antrean as $a) {
            $this->ubah($a, $kejadian->olehId, ['status' => 'batal', 'alasan_batal' => 'Kunjungan dibatalkan',
                'dibatalkan_oleh' => $kejadian->olehId, 'dibatalkan_pada' => now()]);
        }
    }

    /** Panggil (menunggu → dipanggil) atau panggil ulang (dipanggil → dipanggil) sampai batas data induk. */
    /** Asesmen awal perawat selesai (tiket 2.13): nomor poli yang sama kembali menunggu, kini untuk dokter. */
    public function keTahapDokter(AsesmenPerawatSelesai $kejadian): void
    {
        $antrean = Antrean::query()->where('kunjungan_id', $kejadian->kunjunganId)->where('titik', 'poli')->lockForUpdate()->first();
        if ($antrean === null || in_array($antrean->status, ['batal', 'selesai'], true) || $antrean->tahap === 'dokter') {
            return;
        }
        $this->ubah($antrean, $kejadian->olehId, ['tahap' => 'dokter', 'status' => 'menunggu', 'panggilan_ke' => 0]);
    }

    public function panggil(Pengguna $petugas, string $id, ?string $lokasiId): Antrean
    {
        return DB::transaction(function () use ($petugas, $id, $lokasiId) {
            $antrean = $this->kunci($id);
            self::pastikanStatus($antrean, ['menunggu', 'dipanggil']);
            if ($antrean->tanggal->toDateString() < now((string) config('instalasi.zona_waktu'))->toDateString()) {
                throw new ApiError(409, 'sesi_berakhir', 'Nomor antrean ini dari hari yang sudah lewat dan tidak bisa dipanggil lagi. Batalkan bila pasien tidak dilayani.');
            }
            $aturan = self::aturan();
            if ($antrean->status === 'dipanggil') {
                if ($antrean->panggilan_ke >= $aturan->batas_panggilan) {
                    throw new ApiError(409, 'batas_panggilan_tercapai',
                        "Nomor ini sudah dipanggil {$antrean->panggilan_ke} kali. Tandai dilewati bila pasien tidak hadir.");
                }
                $boleh = self::bolehPanggilPada($antrean, $aturan->jeda_panggilan_detik);
                if ($boleh !== null) {
                    throw new ApiError(409, 'jeda_panggilan_belum_lewat', 'Tunggu sebentar sebelum memanggil ulang nomor ini.',
                        [['boleh_panggil_pada' => $boleh->toIso8601String()]]);
                }
            }
            $ruangId = $lokasiId ?? $this->ruangBawaan($antrean);
            if ($lokasiId !== null && ! Lokasi::query()->whereKey($lokasiId)->where('jenis', 'ruang')->where('status', 'aktif')->exists()) {
                throw ValidationException::withMessages(['lokasi_id' => 'Pilih ruang yang aktif.']);
            }

            $ke = $antrean->panggilan_ke + 1;
            $this->ubah($antrean, $petugas->id, ['status' => 'dipanggil', 'panggilan_ke' => $ke, 'dipanggil_pada' => now(), 'ruang_id' => $ruangId]);
            $this->audit->catat('antrean.dipanggil', $petugas->id, $antrean->getTable(), $antrean->id,
                ['nomor_tampil' => $antrean->nomor_tampil, 'panggilan_ke' => $ke, 'ruang_id' => $ruangId]);

            return $antrean;
        });
    }

    /** dipanggil → dilewati (pasien tidak hadir; keputusan petugas, boleh sebelum batas panggilan). */
    public function lewati(Pengguna $petugas, string $id): Antrean
    {
        return DB::transaction(function () use ($petugas, $id) {
            $antrean = $this->kunci($id);
            self::pastikanStatus($antrean, ['dipanggil']);
            $this->ubah($antrean, $petugas->id, ['status' => 'dilewati']);
            $this->audit->catat('antrean.dilewati', $petugas->id, $antrean->getTable(), $antrean->id,
                ['nomor_tampil' => $antrean->nomor_tampil, 'panggilan_ke' => $antrean->panggilan_ke]);

            return $antrean;
        });
    }

    /** dilewati → menunggu, sesudah N nomor menunggu berikutnya dalam antrean dokter yang sama (N dari data induk). */
    public function sisip(Pengguna $petugas, string $id): Antrean
    {
        return DB::transaction(function () use ($petugas, $id) {
            $antrean = $this->kunci($id);
            self::pastikanStatus($antrean, ['dilewati']);
            if ($this->sesiBerakhir($antrean)) {
                throw new ApiError(409, 'sesi_berakhir', 'Jam praktik sudah berakhir; nomor ini tidak bisa disisipkan lagi. Batalkan bila pasien tidak dilayani.');
            }

            $n = self::aturan()->sisip_setelah;
            $grup = Antrean::query()->where('tanggal', $antrean->tanggal->toDateString())->where('titik', $antrean->titik)
                ->where('poli_id', $antrean->poli_id)->where('pegawai_id', $antrean->pegawai_id)->whereKeyNot($antrean->id);
            $depan = (clone $grup)->where('status', 'menunggu')->orderBy('urutan')->orderBy('nomor')->limit($n + 1)->pluck('urutan')
                ->map(fn ($u) => (float) $u)->all();
            // Kurang dari N+1 yang menunggu → di akhir; nilai sama dengan nomor baru berikutnya dipisah oleh urutan nomor.
            $urutan = match (true) {
                count($depan) <= $n => (float) ((clone $grup)->max('urutan') ?? 0) + 1,
                $n === 0 => $depan[0] - 1,
                default => ($depan[$n - 1] + $depan[$n]) / 2,
            };

            $this->ubah($antrean, $petugas->id, ['status' => 'menunggu', 'panggilan_ke' => 0, 'urutan' => sprintf('%.10F', $urutan)]);
            $this->audit->catat('antrean.disisipkan', $petugas->id, $antrean->getTable(), $antrean->id,
                ['nomor_tampil' => $antrean->nomor_tampil, 'sisip_setelah' => $n]);

            return $antrean;
        });
    }

    /** menunggu/dipanggil/dilewati → batal; kunjungan tidak ikut batal. */
    public function batal(Pengguna $petugas, string $id, string $alasan): Antrean
    {
        return DB::transaction(function () use ($petugas, $id, $alasan) {
            $antrean = $this->kunci($id);
            if ($antrean->status === 'batal') {
                throw new ApiError(409, 'sudah_batal', 'Nomor antrean ini sudah dibatalkan.');
            }
            self::pastikanStatus($antrean, ['menunggu', 'dipanggil', 'dilewati']);
            $sebelum = $antrean->status;
            $this->ubah($antrean, $petugas->id, ['status' => 'batal', 'alasan_batal' => $alasan, 'dibatalkan_oleh' => $petugas->id, 'dibatalkan_pada' => now()]);
            $this->audit->catat('antrean.dibatalkan', $petugas->id, $antrean->getTable(), $antrean->id,
                ['nomor_tampil' => $antrean->nomor_tampil, 'alasan_batal' => $alasan], ['status' => $sebelum]);

            return $antrean;
        });
    }

    /** Waktu jeda panggilan berakhir, atau null bila tidak ada jeda yang berjalan. */
    public static function bolehPanggilPada(Antrean $antrean, int $jedaDetik): ?Carbon
    {
        if ($antrean->status !== 'dipanggil' || $antrean->dipanggil_pada === null) {
            return null;
        }
        $boleh = $antrean->dipanggil_pada->copy()->addSeconds($jedaDetik);

        return $boleh->isFuture() ? $boleh : null;
    }

    private function kunci(string $id): Antrean
    {
        return Antrean::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw new ApiError(404, 'tidak_ditemukan', 'Nomor antrean tidak ditemukan.');
    }

    /** @param  list<string>  $boleh */
    private static function pastikanStatus(Antrean $antrean, array $boleh): void
    {
        if (! in_array($antrean->status, $boleh, true)) {
            throw new ApiError(409, 'status_tidak_sesuai', 'Status nomor antrean ini tidak memungkinkan tindakan tersebut.',
                [['status' => $antrean->status]]);
        }
    }

    /** @param  array<string, mixed>  $isi */
    private function ubah(Antrean $antrean, ?string $olehId, array $isi): void
    {
        $sekarang = now();
        $antrean->forceFill([...$isi, 'diubah_oleh' => $olehId, 'revisi' => $antrean->revisi + 1])->save();
        $this->jejak($antrean, $olehId, $sekarang);
    }

    private function jejak(Antrean $antrean, ?string $olehId, Carbon $pada): void
    {
        DB::table('kunjungan.antrean_jejak')->insert([
            'id' => (string) Str::uuid7(), 'antrean_id' => $antrean->id, 'status' => $antrean->status,
            'panggilan_ke' => $antrean->status === 'dipanggil' ? $antrean->panggilan_ke : null,
            'ruang_id' => $antrean->status === 'dipanggil' ? $antrean->ruang_id : null, 'terjadi_pada' => $pada, 'oleh' => $olehId,
        ]);
        AntreanBerubah::dispatch($antrean->id, $antrean->status, $pada->toIso8601String());
    }

    /** Ruang jadwal praktik, atau ruang poliklinik bila jadwal tidak menentukan ruang. */
    private function ruangBawaan(Antrean $antrean): ?string
    {
        if ($antrean->kunjungan_id === null) {
            return null;
        }
        $ruang = DB::table('kunjungan.kunjungan AS k')->join('kunjungan.jadwal_praktik AS j', 'j.id', '=', 'k.jadwal_praktik_id')
            ->join('inti.poliklinik AS p', 'p.id', '=', 'k.poli_id')->where('k.id', $antrean->kunjungan_id)
            ->first(['j.ruang_id', 'p.lokasi_id']);

        return $ruang->ruang_id ?? $ruang->lokasi_id ?? null;
    }

    /** Hari antrean sudah lewat, atau hari ini sesudah jam tutup jadwal praktik kunjungannya (zona RS). */
    private function sesiBerakhir(Antrean $antrean): bool
    {
        $sekarang = now((string) config('instalasi.zona_waktu'));
        $tanggal = $antrean->tanggal->toDateString();
        if ($tanggal !== $sekarang->toDateString()) {
            return $tanggal < $sekarang->toDateString();
        }
        $tutup = $antrean->kunjungan_id === null ? null : DB::table('kunjungan.kunjungan AS k')
            ->join('kunjungan.jadwal_praktik AS j', 'j.id', '=', 'k.jadwal_praktik_id')->where('k.id', $antrean->kunjungan_id)->value('j.jam_selesai');

        return $tutup !== null && $sekarang->format('H:i:s') >= (string) $tutup;
    }
}
