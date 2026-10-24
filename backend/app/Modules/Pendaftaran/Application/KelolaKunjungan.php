<?php

namespace App\Modules\Pendaftaran\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Application\KelolaRelasiPerawatan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Application\SuntinganBerversi;
use App\Modules\MasterData\Models\JadwalPengecualian;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\MasterData\Models\Penjamin;
use App\Modules\Pendaftaran\Events\KunjunganDibuat;
use App\Modules\Pendaftaran\Models\Episode;
use App\Modules\Pendaftaran\Models\Kunjungan;
use App\Modules\Pendaftaran\Models\Pasien;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kunjungan poli (tiket 2.6, kontrak v0.20.0). Baris jadwal dikunci selama pendaftaran agar kuota tidak terlampaui oleh
 * pendaftaran serentak; baris pasien dikunci agar episode harian dan kunjungan ganda dicek bergantian (indeks unik
 * parsial di basis data sebagai penjaga terakhir). Dokter jadwal mendapat relasi perawatan untuk hari kunjungan itu.
 */
final class KelolaKunjungan
{
    public const ALASAN_BATAL_OTOMATIS = 'Tidak dilayani sampai jam tutup poli (batal otomatis)';

    /** Asumsi: pendaftaran paling jauh 90 hari ke depan (kontrak: "rentang yang diizinkan ke depan"). */
    public const MAKS_HARI_KE_DEPAN = 90;

    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly KelolaRelasiPerawatan $relasi,
        private readonly KeterisianKunjungan $keterisian,
    ) {}

    /** @param  array<string, mixed>  $isi  sudah divalidasi bentuknya */
    public function daftar(Pengguna $petugas, array $isi): Kunjungan
    {
        if (($isi['jenis_kunjungan'] ?? 'poliklinik') !== 'poliklinik') {
            throw new ApiError(422, 'jenis_belum_didukung', 'Saat ini hanya kunjungan poliklinik yang dapat didaftarkan.',
                [['kolom' => 'jenis_kunjungan', 'pesan' => 'Pilih poliklinik.']]);
        }

        return DB::transaction(function () use ($petugas, $isi) {
            $pasien = Pasien::query()->whereKey($isi['pasien_id'])->lockForUpdate()->first()
                ?? throw new ApiError(404, 'tidak_ditemukan', 'Pasien tidak ditemukan.');
            if ($pasien->digabung_ke_id !== null) {
                throw new ApiError(409, 'pasien_digabung', 'No. RM ini sudah digabung ke RM lain. Daftarkan kunjungan di RM tujuan.');
            }
            $jadwal = JadwalPraktik::query()->whereKey($isi['jadwal_praktik_id'])->lockForUpdate()->first()
                ?? throw new ApiError(404, 'tidak_ditemukan', 'Jadwal praktik tidak ditemukan.');
            $tanggal = (string) $isi['tanggal'];
            $caraBayar = (string) $isi['cara_bayar'];

            $this->periksaJadwal($jadwal, $tanggal);
            $penjaminId = $this->periksaPenjamin($caraBayar, $isi['penjamin_id'] ?? null);
            $ganda = Kunjungan::query()->where('pasien_id', $pasien->id)->where('jadwal_praktik_id', $jadwal->id)
                ->where('tanggal', $tanggal)->where('status', '<>', 'batal')->exists();
            if ($ganda) {
                throw new ApiError(409, 'kunjungan_ganda', 'Pasien sudah terdaftar di jadwal ini pada tanggal tersebut.');
            }
            $this->periksaKuota($jadwal, $tanggal, $caraBayar);

            [$episode, $episodeBaru] = $this->episodeHarian($pasien, $tanggal, $petugas->id);
            $kunjungan = new Kunjungan;
            $kunjungan->forceFill([
                'nomor' => $this->nomorBaru($tanggal), 'pasien_id' => $pasien->id, 'episode_id' => $episode->id,
                'jenis_kunjungan' => 'poliklinik', 'jadwal_praktik_id' => $jadwal->id, 'poli_id' => $jadwal->poli_id,
                'pegawai_id' => $jadwal->pegawai_id, 'tanggal' => $tanggal, 'cara_bayar' => $caraBayar, 'penjamin_id' => $penjaminId,
                'keluhan' => $isi['keluhan'] ?? null, 'dibuat_oleh' => $petugas->id, 'diubah_oleh' => $petugas->id,
            ])->save();

            // Relasi perawatan lapis 4 hanya untuk hari kunjungan (zona RS), bukan sejak didaftarkan.
            $zona = (string) config('instalasi.zona_waktu');
            $relasiId = $this->relasi->berikan($pasien->id, $jadwal->pegawai_id, KelolaRelasiPerawatan::PERAN_DOKTER_JADWAL,
                'kunjungan', $kunjungan->id, Carbon::parse($tanggal, $zona)->startOfDay(), Carbon::parse($tanggal, $zona)->addDay()->startOfDay(), $petugas->id);

            KunjunganDibuat::dispatch($kunjungan->id, $pasien->id, $episode->id, $jadwal->poli_id, $jadwal->pegawai_id, $tanggal, $caraBayar);
            $this->audit->catat('kunjungan.dibuat', $petugas->id, $kunjungan->getTable(), $kunjungan->id, [
                'nomor' => $kunjungan->nomor, 'poli_id' => $jadwal->poli_id, 'pegawai_id' => $jadwal->pegawai_id,
                'jadwal_praktik_id' => $jadwal->id, 'tanggal' => $tanggal, 'cara_bayar' => $caraBayar,
                'episode_id' => $episode->id, 'episode_baru' => $episodeBaru, 'relasi_perawatan_id' => $relasiId,
            ]);

            return $kunjungan;
        });
    }

    /** Pembatalan manual petugas (FR-01-21). */
    public function batal(Pengguna $petugas, string $kunjunganId, string $alasan): Kunjungan
    {
        return DB::transaction(function () use ($petugas, $kunjunganId, $alasan) {
            $kunjungan = $this->kunciUntukBatal($kunjunganId) ?? throw new ApiError(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.');
            if ($kunjungan->status === 'batal') {
                throw new ApiError(409, 'sudah_batal', 'Kunjungan sudah dibatalkan.');
            }
            if ($kunjungan->status !== 'terdaftar') {
                throw new ApiError(409, 'sudah_dilayani', 'Pelayanan sudah dimulai; kunjungan tidak dapat dibatalkan.');
            }
            $this->tandaiBatal($kunjungan, $alasan, $petugas->id);

            return $kunjungan;
        });
    }

    /**
     * Batal otomatis kunjungan yang masih `terdaftar` setelah jam tutup jadwalnya (FR-01-21). Aman diulang; pelaku sistem.
     *
     * @return int jumlah kunjungan yang dibatalkan
     */
    public function batalkanTakDilayani(int $batas = 200): int
    {
        $zona = (string) config('instalasi.zona_waktu');
        $sekarang = now($zona);
        $hariIni = $sekarang->toDateString();
        $jumlah = 0;
        do {
            $ids = DB::table('kunjungan.kunjungan AS k')
                ->join('kunjungan.jadwal_praktik AS j', 'j.id', '=', 'k.jadwal_praktik_id')
                ->where('k.status', 'terdaftar')
                ->where(fn ($q) => $q->where('k.tanggal', '<', $hariIni)
                    ->orWhere(fn ($w) => $w->where('k.tanggal', $hariIni)->where('j.jam_selesai', '<=', $sekarang->format('H:i:s'))))
                ->orderBy('k.tanggal')->orderBy('k.id')->limit($batas)->pluck('k.id');
            foreach ($ids as $id) {
                DB::transaction(function () use ($id, &$jumlah) {
                    $kunjungan = $this->kunciUntukBatal($id);
                    if ($kunjungan?->status === 'terdaftar') {
                        $this->tandaiBatal($kunjungan, self::ALASAN_BATAL_OTOMATIS, null);
                        $jumlah++;
                    }
                });
            }
        } while ($ids->count() === $batas);

        return $jumlah;
    }

    /**
     * Kunci pasien lalu kunjungan, urutan yang sama dengan daftar(): pendaftaran serentak pasien yang sama menunggu
     * sampai pembatalan selesai, jadi tidak menempel ke episode yang baru saja ikut batal.
     */
    private function kunciUntukBatal(string $kunjunganId): ?Kunjungan
    {
        $pasienId = Kunjungan::query()->whereKey($kunjunganId)->value('pasien_id');
        if ($pasienId === null) {
            return null;
        }
        Pasien::query()->whereKey($pasienId)->lockForUpdate()->first();

        return Kunjungan::query()->whereKey($kunjunganId)->lockForUpdate()->first();
    }

    /** $olehId null = sistem. Dipanggil di dalam transaksi dengan baris pasien dan kunjungan terkunci (kunciUntukBatal). */
    private function tandaiBatal(Kunjungan $kunjungan, string $alasan, ?string $olehId): void
    {
        $kunjungan->forceFill([
            'status' => 'batal', 'alasan_batal' => $alasan, 'dibatalkan_oleh' => $olehId, 'dibatalkan_pada' => now(),
            'diubah_oleh' => $olehId, 'revisi' => $kunjungan->revisi + 1,
        ])->save();
        $this->relasi->akhiri('kunjungan', $kunjungan->id, 'Kunjungan dibatalkan', $olehId);

        // Episode tanpa kunjungan aktif ikut batal; kunjungan berikutnya di hari itu membentuk episode baru.
        $sisa = Kunjungan::query()->where('episode_id', $kunjungan->episode_id)->where('status', '<>', 'batal')->exists();
        if (! $sisa) {
            Episode::query()->whereKey($kunjungan->episode_id)->where('status', 'aktif')->update([
                'status' => 'batal', 'alasan_batal' => 'Semua kunjungan episode dibatalkan', 'dibatalkan_oleh' => $olehId,
                'dibatalkan_pada' => now(), 'diubah_oleh' => $olehId, 'diubah_pada' => now(), 'revisi' => DB::raw('revisi + 1'),
            ]);
        }

        $this->audit->catat('kunjungan.dibatalkan', $olehId, $kunjungan->getTable(), $kunjungan->id,
            ['nomor' => $kunjungan->nomor, 'alasan_batal' => $alasan, 'otomatis' => $olehId === null], ['status' => 'terdaftar']);
    }

    private function periksaJadwal(JadwalPraktik $jadwal, string $tanggal): void
    {
        if ($jadwal->status !== 'aktif') {
            throw self::tidakTersedia('nonaktif', 'Jadwal praktik ini tidak aktif.');
        }
        $zona = (string) config('instalasi.zona_waktu');
        $hariIni = SuntinganBerversi::hariIni();
        $alasan = match (true) {
            $tanggal < $hariIni => 'Tanggal kunjungan sudah lewat.',
            $tanggal > Carbon::parse($hariIni)->addDays(self::MAKS_HARI_KE_DEPAN)->toDateString() => 'Pendaftaran paling jauh '.self::MAKS_HARI_KE_DEPAN.' hari ke depan.',
            Carbon::parse($tanggal)->isoWeekday() !== $jadwal->hari => 'Tanggal itu bukan hari praktik jadwal ini.',
            $tanggal < $jadwal->berlaku_dari->toDateString(), $jadwal->berlaku_sampai !== null && $tanggal > $jadwal->berlaku_sampai->toDateString() => 'Tanggal di luar masa berlaku jadwal.',
            $tanggal === $hariIni && now($zona)->format('H:i:s') >= $jadwal->jam_selesai => 'Praktik jadwal ini hari ini sudah tutup.',
            default => null,
        };
        if ($alasan !== null) {
            throw new ApiError(422, 'tanggal_di_luar_jadwal', $alasan, [['kolom' => 'tanggal', 'pesan' => $alasan]]);
        }

        $tutup = JadwalPengecualian::query()->where('status', 'aktif')
            ->where('dari_tanggal', '<=', $tanggal)->where('sampai_tanggal', '>=', $tanggal)
            ->where(fn ($q) => $q->whereNull('pegawai_id')->orWhere('pegawai_id', $jadwal->pegawai_id))
            ->orderByRaw("jenis = 'libur' DESC")->value('jenis');
        if ($tutup !== null) {
            throw self::tidakTersedia($tutup, $tutup === 'libur' ? 'RS libur pada tanggal itu.' : 'Dokter cuti pada tanggal itu.');
        }
    }

    private static function tidakTersedia(string $alasan, string $pesan): ApiError
    {
        return new ApiError(422, 'jadwal_tidak_tersedia', $pesan, [['kolom' => 'jadwal_praktik_id', 'alasan' => $alasan, 'pesan' => $pesan]]);
    }

    private function periksaPenjamin(string $caraBayar, ?string $penjaminId): ?string
    {
        if ($penjaminId === null) {
            if ($caraBayar === 'jkn') {
                throw new ApiError(422, 'penjamin_wajib', 'Kunjungan JKN wajib memilih penjamin BPJS.', [['kolom' => 'penjamin_id', 'pesan' => 'Pilih penjamin BPJS.']]);
            }

            return null;
        }
        $penjamin = Penjamin::query()->whereKey($penjaminId)->where('status', 'aktif')->first();
        if ($penjamin === null) {
            throw ValidationException::withMessages(['penjamin_id' => 'Penjamin tidak ditemukan atau tidak aktif.']);
        }
        if (($caraBayar === 'jkn') !== ($penjamin->jenis === 'bpjs')) {
            $pesan = $caraBayar === 'jkn' ? 'Kunjungan JKN memakai penjamin BPJS.' : 'Penjamin BPJS hanya untuk cara bayar JKN.';
            throw new ApiError(422, 'penjamin_tidak_sesuai', $pesan, [['kolom' => 'penjamin_id', 'pesan' => $pesan]]);
        }

        return $penjamin->id;
    }

    /** Kuota per cara bayar (FR-01-15). Penuh → 409 dengan jadwal dokter lain di poli yang sama yang masih bersisa. */
    private function periksaKuota(JadwalPraktik $jadwal, string $tanggal, string $caraBayar): void
    {
        $terisi = $this->keterisian->terisi([$jadwal->id], $tanggal)[$jadwal->id] ?? ['jkn' => 0, 'non_jkn' => 0];
        $kuota = $caraBayar === 'jkn' ? $jadwal->kuota_jkn : $jadwal->kuota_non_jkn;
        if ($terisi[$caraBayar] < $kuota) {
            return;
        }

        throw new ApiError(409, 'kuota_habis', 'Kuota '.($caraBayar === 'jkn' ? 'JKN' : 'non-JKN').' jadwal ini sudah penuh.',
            $this->slotLain($jadwal, $tanggal, $caraBayar));
    }

    /** @return list<array<string, mixed>> */
    private function slotLain(JadwalPraktik $jadwal, string $tanggal, string $caraBayar): array
    {
        $tutup = JadwalPengecualian::query()->where('status', 'aktif')
            ->where('dari_tanggal', '<=', $tanggal)->where('sampai_tanggal', '>=', $tanggal)->get(['jenis', 'pegawai_id']);
        if ($tutup->contains('jenis', 'libur')) {
            return [];
        }
        $lain = JadwalPraktik::query()->where('poli_id', $jadwal->poli_id)->whereKeyNot($jadwal->id)->where('status', 'aktif')
            ->where('hari', Carbon::parse($tanggal)->isoWeekday())->where('berlaku_dari', '<=', $tanggal)
            ->where(fn ($q) => $q->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', $tanggal))
            ->whereNotIn('pegawai_id', $tutup->pluck('pegawai_id')->filter()->values())
            ->orderBy('jam_mulai')->orderBy('id')->limit(20)->get();
        $terisi = $this->keterisian->terisi($lain->pluck('id')->all(), $tanggal);
        $pegawai = Pegawai::query()->whereIn('id', $lain->pluck('pegawai_id')->unique())->get(['id', 'nama', 'status'])->keyBy('id');

        return $lain->map(function (JadwalPraktik $j) use ($terisi, $pegawai) {
            $t = $terisi[$j->id] ?? ['jkn' => 0, 'non_jkn' => 0];
            $p = $pegawai[$j->pegawai_id];

            return [
                'jadwal_praktik_id' => $j->id, 'pegawai' => ['id' => $p->id, 'nama' => $p->nama, 'status' => $p->status],
                'sisa_jkn' => max(0, $j->kuota_jkn - $t['jkn']), 'sisa_non_jkn' => max(0, $j->kuota_non_jkn - $t['non_jkn']),
                'jam_mulai' => substr($j->jam_mulai, 0, 5),
            ];
        })->filter(fn (array $r) => $r[$caraBayar === 'jkn' ? 'sisa_jkn' : 'sisa_non_jkn'] > 0)->values()->all();
    }

    /** @return array{0: Episode, 1: bool} episode rawat jalan harian pasien pada tanggal itu, dan apakah baru dibentuk */
    private function episodeHarian(Pasien $pasien, string $tanggal, string $olehId): array
    {
        $ada = Episode::query()->where('pasien_id', $pasien->id)->where('jenis', Episode::RAWAT_JALAN_HARIAN)
            ->where('mulai_tanggal', $tanggal)->where('status', '<>', 'batal')->first();
        if ($ada !== null) {
            return [$ada, false];
        }
        $baru = new Episode;
        $baru->forceFill(['pasien_id' => $pasien->id, 'jenis' => Episode::RAWAT_JALAN_HARIAN, 'mulai_tanggal' => $tanggal,
            'dibuat_oleh' => $olehId, 'diubah_oleh' => $olehId])->save();

        return [$baru, true];
    }

    private function nomorBaru(string $tanggal): string
    {
        $urut = DB::selectOne('INSERT INTO kunjungan.nomor_kunjungan (tanggal, terakhir) VALUES (?, 1)
            ON CONFLICT (tanggal) DO UPDATE SET terakhir = kunjungan.nomor_kunjungan.terakhir + 1 RETURNING terakhir', [$tanggal])->terakhir;

        return sprintf('RJ-%s-%04d', str_replace('-', '', $tanggal), $urut);
    }
}
