<?php

namespace App\Modules\MasterData\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\MasterData\Events\UsulanTahapBerubah;
use App\Modules\MasterData\Models\Kamar;
use App\Modules\MasterData\Models\TempatTidur;
use App\Modules\MasterData\Models\UsulanDataInduk;
use App\Modules\Ttd\Domain\IsiKanonik;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Mesin usulan perubahan data induk berjenjang (tiket 1.8c, kontrak v0.9.0; FR-54-01, FR-54-02). Tahap diusulkan →
 * diverifikasi → disetujui → berlaku; dikembalikan → kirim ulang (putaran +1); gagal_diterapkan final. Pengusul ≠
 * pemverifikasi ≠ penyetuju. Butir divalidasi dengan aturan 1.8b lewat uji kering (transaksi dibatalkan) dan diterapkan
 * pada tanggal efektif = max(berlaku_dari, hari ini) dalam satu transaksi.
 */
final class MesinUsulan
{
    /** Objek yang boleh ada di satu usulan per jenis data (dua jenis tidak boleh bercampur). */
    private const OBJEK_PER_JENIS = ['ruang_tt' => ['ruang', 'kamar', 'tempat_tidur'], 'tarif' => ['tarif']];

    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly ButirRuangTt $butir,
        private readonly ButirTarif $butirTarif,
        private readonly CatatVersi $versi,
    ) {}

    /**
     * @param  array{jenis_data: string, perubahan: list<array<string, mixed>>, berlaku_dari: string, alasan: string, dasar: string}  $isi
     */
    public function ajukan(Pengguna $pengusul, array $isi): UsulanDataInduk
    {
        $alur = $this->alur($isi['jenis_data']);
        $this->pastikanPeran($pengusul, $alur->peran_pengusul_id, 'bukan_pengusul_alur', 'Usulan jenis data ini diajukan oleh peran pemilik datanya.');
        $this->pastikanTanggal($isi['berlaku_dari']);
        [$perubahan, $ringkasan] = $this->periksa($pengusul, $isi['jenis_data'], $isi['perubahan'], $isi['berlaku_dari']);

        return DB::transaction(function () use ($pengusul, $isi, $perubahan, $ringkasan, $alur) {
            $this->kunciObjek();
            $this->pastikanTidakBentrok($perubahan, null);
            $u = new UsulanDataInduk([
                'nomor' => $this->nomor(), 'jenis_data' => $isi['jenis_data'], 'perubahan' => $perubahan, 'ringkasan' => $ringkasan,
                'berlaku_dari' => $isi['berlaku_dari'], 'alasan' => $isi['alasan'], 'dasar' => $isi['dasar'], 'diusulkan_oleh' => $pengusul->id,
                'diusulkan_pada' => now(), 'dibuat_oleh' => $pengusul->id, 'diubah_oleh' => $pengusul->id,
            ]);
            $u->save();
            $this->simpanPutaran($u);
            $this->kejadian($u, 'diajukan', $pengusul->id, null);
            $this->beritahu($u, $alur);
            $this->audit->catat('master_data.usulan_diajukan', $pengusul->id, 'inti.usulan_data_induk', $u->id, $this->ringkasAudit($u));

            return $u;
        });
    }

    /** @param  array{perubahan: list<array<string, mixed>>, berlaku_dari: string, alasan: string, dasar: string}  $isi */
    public function kirimUlang(Pengguna $pengusul, UsulanDataInduk $u, array $isi): UsulanDataInduk
    {
        if ($u->diusulkan_oleh !== $pengusul->id) {
            throw new ApiError(403, 'bukan_pengusul', 'Hanya pengusul yang bisa memperbaiki dan mengirim ulang usulan ini.');
        }
        $this->pastikanTahap($u, ['dikembalikan']);
        $this->pastikanTanggal($isi['berlaku_dari']);
        [$perubahan, $ringkasan] = $this->periksa($pengusul, $u->jenis_data, $isi['perubahan'], $isi['berlaku_dari']);

        return DB::transaction(function () use ($pengusul, $u, $isi, $perubahan, $ringkasan) {
            $this->kunciObjek();
            $u = $this->kunci($u);
            $this->pastikanTahap($u, ['dikembalikan']);
            $this->pastikanTidakBentrok($perubahan, $u->id);
            $u->forceFill([
                'perubahan' => $perubahan, 'ringkasan' => $ringkasan, 'berlaku_dari' => $isi['berlaku_dari'], 'alasan' => $isi['alasan'],
                'dasar' => $isi['dasar'], 'tahap' => 'diusulkan', 'putaran' => $u->putaran + 1, 'diusulkan_pada' => now(),
                'diverifikasi_oleh' => null, 'diverifikasi_pada' => null, 'disetujui_oleh' => null, 'disetujui_pada' => null,
                'diubah_oleh' => $pengusul->id, 'revisi' => $u->revisi + 1,
            ])->save();
            $this->simpanPutaran($u);
            $this->kejadian($u, 'dikirim_ulang', $pengusul->id, null);
            $this->beritahu($u, $this->alur($u->jenis_data));
            $this->audit->catat('master_data.usulan_dikirim_ulang', $pengusul->id, 'inti.usulan_data_induk', $u->id, $this->ringkasAudit($u));

            return $u;
        });
    }

    public function verifikasi(Pengguna $pemverifikasi, UsulanDataInduk $u, ?string $catatan): UsulanDataInduk
    {
        $alur = $this->alur($u->jenis_data);
        $this->pastikanIzin('verifikasi');
        $this->pastikanPeran($pemverifikasi, $alur->peran_pemverifikasi_id, 'bukan_pemverifikasi', 'Usulan jenis data ini diverifikasi oleh peran pemverifikasi alurnya.');
        $this->pastikanBukanPengusul($pemverifikasi, $u);

        return DB::transaction(function () use ($pemverifikasi, $u, $catatan, $alur) {
            $u = $this->kunci($u);
            $this->pastikanBukanPengusul($pemverifikasi, $u);
            $this->pastikanTahap($u, ['diusulkan']);
            $u->forceFill(['tahap' => 'diverifikasi', 'diverifikasi_oleh' => $pemverifikasi->id, 'diverifikasi_pada' => now(),
                'diubah_oleh' => $pemverifikasi->id, 'revisi' => $u->revisi + 1])->save();
            $this->kejadian($u, 'diverifikasi', $pemverifikasi->id, $catatan);
            $this->beritahu($u, $alur);
            $this->audit->catat('master_data.usulan_diverifikasi', $pemverifikasi->id, 'inti.usulan_data_induk', $u->id, ['nomor' => $u->nomor, 'catatan' => $catatan]);

            return $u;
        });
    }

    /** Disetujui; bila tanggal efektifnya sudah tiba, langsung diterapkan (hasilnya bisa berlaku atau gagal_diterapkan). */
    public function setujui(Pengguna $penyetuju, UsulanDataInduk $u, ?string $catatan): UsulanDataInduk
    {
        $alur = $this->alur($u->jenis_data);
        $this->pastikanIzin('setujui');
        $this->pastikanPeran($penyetuju, $alur->peran_penyetuju_id, 'bukan_penyetuju', 'Usulan jenis data ini disetujui oleh peran penyetuju alurnya.');
        $this->pastikanBukanPengusul($penyetuju, $u);
        if ($u->diverifikasi_oleh === $penyetuju->id) {
            throw new ApiError(403, 'sudah_memverifikasi', 'Pemverifikasi tidak bisa menyetujui usulan yang ia verifikasi.');
        }

        $u = DB::transaction(function () use ($penyetuju, $u, $catatan, $alur) {
            $u = $this->kunci($u);
            // Dicek ulang sesudah kunci: verifikasi dan setujui yang bersamaan tidak boleh menembus pemisahan tugas.
            $this->pastikanBukanPengusul($penyetuju, $u);
            if ($u->diverifikasi_oleh === $penyetuju->id) {
                throw new ApiError(403, 'sudah_memverifikasi', 'Pemverifikasi tidak bisa menyetujui usulan yang ia verifikasi.');
            }
            $this->pastikanTahap($u, ['diverifikasi']);
            $u->forceFill(['tahap' => 'disetujui', 'disetujui_oleh' => $penyetuju->id, 'disetujui_pada' => now(),
                'diubah_oleh' => $penyetuju->id, 'revisi' => $u->revisi + 1])->save();
            $this->kejadian($u, 'disetujui', $penyetuju->id, $catatan);
            $this->beritahu($u, $alur);
            $this->audit->catat('master_data.usulan_disetujui', $penyetuju->id, 'inti.usulan_data_induk', $u->id, ['nomor' => $u->nomor, 'catatan' => $catatan]);

            return $u;
        });
        if ($u->berlaku_dari->toDateString() <= SuntinganBerversi::hariIni()) {
            $this->terapkan($u->id);
            $u->refresh();
        }

        return $u;
    }

    public function kembalikan(Pengguna $peninjau, UsulanDataInduk $u, string $catatan): UsulanDataInduk
    {
        $alur = $this->alur($u->jenis_data);
        // Izin/peran mengikuti tahap saat ini; tahap dibaca ulang dengan kunci di bawah (409 bila sudah berubah).
        if ($u->tahap === 'diverifikasi') {
            $this->pastikanIzin('setujui');
            $this->pastikanPeran($peninjau, $alur->peran_penyetuju_id, 'bukan_penyetuju', 'Usulan tahap ini dikembalikan oleh peran penyetuju alurnya.');
            if ($u->diverifikasi_oleh === $peninjau->id) {
                throw new ApiError(403, 'sudah_memverifikasi', 'Pemverifikasi tidak bisa mengembalikan usulan yang ia verifikasi.');
            }
        } else {
            $this->pastikanIzin('verifikasi');
            $this->pastikanPeran($peninjau, $alur->peran_pemverifikasi_id, 'bukan_pemverifikasi', 'Usulan tahap ini dikembalikan oleh peran pemverifikasi alurnya.');
        }
        $this->pastikanBukanPengusul($peninjau, $u);
        $tahapSemula = $u->tahap;

        return DB::transaction(function () use ($peninjau, $u, $catatan, $alur, $tahapSemula) {
            $u = $this->kunci($u);
            $this->pastikanBukanPengusul($peninjau, $u);
            if ($tahapSemula === 'diverifikasi' && $u->diverifikasi_oleh === $peninjau->id) {
                throw new ApiError(403, 'sudah_memverifikasi', 'Pemverifikasi tidak bisa mengembalikan usulan yang ia verifikasi.');
            }
            $this->pastikanTahap($u, [$tahapSemula === 'diverifikasi' ? 'diverifikasi' : 'diusulkan']);
            $u->forceFill(['tahap' => 'dikembalikan', 'diubah_oleh' => $peninjau->id, 'revisi' => $u->revisi + 1])->save();
            $this->kejadian($u, 'dikembalikan', $peninjau->id, $catatan);
            $this->beritahu($u, $alur, $catatan);
            $this->audit->catat('master_data.usulan_dikembalikan', $peninjau->id, 'inti.usulan_data_induk', $u->id, ['nomor' => $u->nomor, 'catatan' => $catatan]);

            return $u;
        });
    }

    /**
     * Terapkan satu usulan disetujui yang tanggal efektifnya sudah tiba. Aman diulang: hanya tahap disetujui yang
     * diproses (baris dikunci). Gagal → gagal_diterapkan dengan alasan; perubahan data induk tidak ada yang tersimpan.
     */
    public function terapkan(string $usulanId): void
    {
        DB::transaction(function () use ($usulanId) {
            $u = UsulanDataInduk::query()->whereKey($usulanId)->lockForUpdate()->first();
            if ($u === null || $u->tahap !== 'disetujui' || $u->berlaku_dari->toDateString() > SuntinganBerversi::hariIni()) {
                return;
            }
            $penyetuju = Pengguna::query()->findOrFail($u->disetujui_oleh);
            $efektif = max($u->berlaku_dari->toDateString(), SuntinganBerversi::hariIni());
            $dasar = $u->nomor.': '.$u->dasar;
            $alasanGagal = null;
            $perubahan = $u->perubahan;
            try {
                DB::transaction(function () use ($u, $penyetuju, $efektif, $dasar, &$perubahan) {
                    $this->versi->dariUsulan($u->id, function () use ($penyetuju, $efektif, $dasar, &$perubahan) {
                        foreach ($perubahan as $i => $b) {
                            $perubahan[$i]['objek_id_hasil'] = $this->pilih($b)->terapkan($penyetuju, $b, $efektif, $dasar);
                        }
                    });
                });
            } catch (ApiError $e) {
                $alasanGagal = $e->getStatusCode() === 412 ? 'Objek berubah sejak diusulkan.' : $e->getMessage();
            } catch (ModelNotFoundException) {
                $alasanGagal = 'Objek tidak ditemukan lagi.';
            } catch (\Throwable $e) {
                // Kesalahan tak terduga tidak membuat usulan macet di tahap disetujui (perubahan dibatalkan savepoint).
                report($e);
                $alasanGagal = 'Kesalahan sistem saat menerapkan. Hubungi admin, lalu ajukan ulang bila masih perlu.';
            }

            $u->forceFill($alasanGagal === null
                ? ['tahap' => 'berlaku', 'perubahan' => $perubahan, 'diterapkan_pada' => now(), 'revisi' => $u->revisi + 1]
                : ['tahap' => 'gagal_diterapkan', 'gagal_alasan' => $alasanGagal, 'revisi' => $u->revisi + 1])->save();
            $this->kejadian($u, $alasanGagal === null ? 'diterapkan' : 'gagal_diterapkan', null, $alasanGagal);
            $this->beritahu($u, $this->alur($u->jenis_data), $alasanGagal);
            $this->audit->catat($alasanGagal === null ? 'master_data.usulan_diterapkan' : 'master_data.usulan_gagal_diterapkan', null,
                'inti.usulan_data_induk', $u->id, ['nomor' => $u->nomor, 'tanggal_efektif' => $efektif, 'alasan' => $alasanGagal]);
        });
    }

    /**
     * Tugas terjadwal: semua usulan disetujui yang tanggalnya ≤ hari ini (mengejar bila server mati), satu transaksi per
     * usulan, per kelompok 500 sampai habis. Usulan yang tetap disetujui karena galat tidak diambil ulang di jalan ini.
     */
    public function terapkanJatuhTempo(): int
    {
        $jumlah = 0;
        $sudah = [];
        do {
            $id = UsulanDataInduk::query()->where('tahap', 'disetujui')->where('berlaku_dari', '<=', SuntinganBerversi::hariIni())
                ->whereNotIn('id', $sudah)->orderBy('berlaku_dari')->orderBy('id')->limit(500)->pluck('id')->all();
            foreach ($id as $usulanId) {
                $sudah[] = $usulanId;
                try {
                    $this->terapkan($usulanId);
                } catch (\Throwable $e) {
                    report($e);
                }
                $jumlah++;
            }
        } while (count($id) === 500);

        return $jumlah;
    }

    /**
     * Tindakan yang boleh dilakukan akun ini sekarang (UsulanDataInduk.dapat).
     *
     * @return array{verifikasi: bool, setujui: bool, kembalikan: bool, kirim_ulang: bool, tandatangani: bool}
     */
    public function dapat(Pengguna $pengguna, UsulanDataInduk $u): array
    {
        $alur = $this->alur($u->jenis_data);
        $bukanPengusul = $u->diusulkan_oleh !== $pengguna->id;
        $verifikasi = $u->tahap === 'diusulkan' && $bukanPengusul && Gate::forUser($pengguna)->allows('master_data.usulan.verifikasi', Cakupan::rs())
            && $this->memegang($pengguna, $alur->peran_pemverifikasi_id);
        $setujui = $u->tahap === 'diverifikasi' && $bukanPengusul && $u->diverifikasi_oleh !== $pengguna->id
            && Gate::forUser($pengguna)->allows('master_data.usulan.setujui', Cakupan::rs()) && $this->memegang($pengguna, $alur->peran_penyetuju_id);
        $pelaku = in_array($pengguna->id, array_filter([$u->diusulkan_oleh, $u->diverifikasi_oleh, $u->disetujui_oleh]), true);

        return [
            'verifikasi' => $verifikasi,
            'setujui' => $setujui,
            'kembalikan' => $verifikasi || $setujui,
            'kirim_ulang' => $u->tahap === 'dikembalikan' && ! $bukanPengusul && Gate::forUser($pengguna)->allows('master_data.usulan.input', Cakupan::rs()),
            'tandatangani' => $pelaku && ! DB::table('ttd.tanda_tangan')->where('objek_jenis', 'usulan_data_induk')->where('objek_id', $u->id)
                ->where('pengguna_id', $pengguna->id)->where('hash_isi', IsiKanonik::hash(self::isiKanonik($u)))->exists(),
        ];
    }

    /**
     * Isi kanonik TTE (kontrak v0.9.0): isi putaran ini tanpa hasil penerapan dan tanpa label tampilan.
     *
     * @return array<string, mixed>
     */
    public static function isiKanonik(UsulanDataInduk $u): array
    {
        return [
            'id' => $u->id, 'nomor' => $u->nomor, 'jenis_data' => $u->jenis_data, 'putaran' => $u->putaran,
            'perubahan' => array_map(fn (array $b) => array_intersect_key($b, array_flip(['objek_jenis', 'aksi', 'objek_id', 'nilai_lama', 'nilai_baru'])), $u->perubahan),
            'berlaku_dari' => $u->berlaku_dari->toDateString(), 'alasan' => $u->alasan, 'dasar' => $u->dasar, 'diusulkan_oleh' => $u->diusulkan_oleh,
        ];
    }

    /**
     * Bentuk, potret, dan uji kering semua butir. Error dikumpulkan per butir (422 perubahan_tidak_valid).
     *
     * @param  list<array<string, mixed>>  $butir
     * @return array{0: list<array<string, mixed>>, 1: string} butir lengkap (objek_label, nilai_lama, revisi_objek) dan ringkasan
     */
    private function periksa(Pengguna $pengusul, string $jenisData, array $butir, string $berlakuDari): array
    {
        $rincian = [];
        $hasil = [];
        foreach ($butir as $i => $b) {
            $b = array_intersect_key($b, array_flip(['objek_jenis', 'aksi', 'objek_id', 'nilai_baru']));
            $salah = in_array($b['objek_jenis'] ?? null, self::OBJEK_PER_JENIS[$jenisData] ?? [], true) ? $this->pilih($b)->periksaBentuk($i, $b)
                : [['kolom' => "perubahan.$i.objek_jenis", 'kode' => 'jenis_campur', 'pesan' => 'Satu usulan hanya memuat objek dari satu jenis data.']];
            if ($salah === []) {
                $potret = $this->pilih($b)->potret($b);
                if ($potret === null) {
                    $salah = [['kolom' => "perubahan.$i.objek_id", 'kode' => 'objek_tidak_ditemukan', 'pesan' => 'Objek tidak ditemukan.']];
                } else {
                    $b = [...$b, 'objek_id' => $b['objek_id'] ?? null, 'objek_label' => $potret['label'], 'nilai_lama' => $potret['nilai_lama'], 'revisi_objek' => $potret['revisi']];
                }
            }
            array_push($rincian, ...$salah);
            $hasil[] = $b;
        }
        if ($rincian === []) {
            $rincian = $this->ujiKering($pengusul, $hasil, $berlakuDari);
        }
        if ($rincian !== []) {
            throw new ApiError(422, 'perubahan_tidak_valid', 'Sebagian perubahan tidak memenuhi aturan data induk.', $rincian);
        }

        return [$hasil, $this->ringkasan($hasil, $berlakuDari)];
    }

    /**
     * Terapkan semua butir di transaksi yang selalu dibatalkan, dengan `berlaku_dari` sebagai hari acuan.
     *
     * @param  list<array<string, mixed>>  $butir
     * @return list<array{kolom: string, kode: string, pesan: string}>
     */
    private function ujiKering(Pengguna $pengusul, array $butir, string $berlakuDari): array
    {
        $rincian = [];
        DB::beginTransaction();
        try {
            SuntinganBerversi::denganHariAcuan($berlakuDari, function () use ($pengusul, $butir, $berlakuDari, &$rincian) {
                foreach ($butir as $i => $b) {
                    try {
                        DB::transaction(fn () => $this->pilih($b)->terapkan($pengusul, $b, $berlakuDari, 'Uji kering usulan'));
                    } catch (ApiError $e) {
                        $isian = $e->rincian[0]['kolom'] ?? null;
                        $rincian[] = ['kolom' => "perubahan.$i".($isian !== null ? ".$isian" : ''), 'kode' => $e->kode, 'pesan' => $e->getMessage()];
                    } catch (ModelNotFoundException) {
                        $rincian[] = ['kolom' => "perubahan.$i", 'kode' => 'objek_tidak_ditemukan', 'pesan' => 'Data rujukan tidak ditemukan.'];
                    }
                }
            });
        } finally {
            DB::rollBack();
        }

        return $rincian;
    }

    /** Satu objek di satu usulan berjalan: pemeriksaan dan penyimpanan diserialkan (kunci transaksi). */
    private function kunciObjek(): void
    {
        DB::statement("SELECT pg_advisory_xact_lock(hashtext('master_data.usulan_objek'))");
    }

    /** @param  list<array<string, mixed>>  $perubahan */
    private function pastikanTidakBentrok(array $perubahan, ?string $kecuali): void
    {
        $rincian = [];
        foreach ($perubahan as $i => $b) {
            if (($b['objek_id'] ?? null) === null) {
                continue;
            }
            $lain = UsulanDataInduk::query()->whereIn('tahap', UsulanDataInduk::BERJALAN)->when($kecuali, fn ($q) => $q->whereKeyNot($kecuali))
                ->whereRaw('perubahan @> ?::jsonb', [json_encode([['objek_jenis' => $b['objek_jenis'], 'objek_id' => $b['objek_id']]])])
                ->first(['id', 'nomor']);
            if ($lain !== null) {
                $rincian[] = ['kolom' => "perubahan.$i", 'kode' => 'objek_sedang_diusulkan', 'pesan' => "Objek ini sedang diusulkan di {$lain->nomor}.",
                    'usulan' => ['id' => $lain->id, 'nomor' => $lain->nomor]];
            }
        }
        if ($rincian !== []) {
            throw new ApiError(409, 'objek_sedang_diusulkan', 'Sebagian objek sedang diusulkan di usulan lain.', $rincian);
        }
    }

    /** "ICU: 12 → 16 TT" bila semua butir TT tambah/tutup di satu bangsal; selain itu hitungan per aksi. */
    private function ringkasan(array $butir, string $berlakuDari): string
    {
        $bangsal = array_unique(array_map(fn (array $b) => $this->butir->bangsalId($b), $butir));
        $hanyaTtTambahTutup = array_filter($butir, fn (array $b) => $b['objek_jenis'] !== 'tempat_tidur' || $b['aksi'] === 'ubah') === [];
        if ($hanyaTtTambahTutup && count($bangsal) === 1 && $bangsal[array_key_first($bangsal)] !== null) {
            $id = (string) $bangsal[array_key_first($bangsal)];
            $sebelum = TempatTidur::query()->whereIn('kamar_id', Kamar::query()->where('bangsal_id', $id)->select('id'))->where('berlaku_dari', '<=', $berlakuDari)
                ->where(fn ($q) => $q->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', $berlakuDari))->count();
            $tambah = count(array_filter($butir, fn (array $b) => $b['aksi'] === 'tambah'));
            $nama = DB::table('inti.bangsal')->where('id', $id)->value('nama');

            return "{$nama}: {$sebelum} → ".($sebelum + $tambah - (count($butir) - $tambah)).' TT';
        }
        $label = ['tempat_tidur' => 'TT', 'kamar' => 'kamar', 'ruang' => 'ruang', 'tarif' => 'tarif'];
        $hitung = [];
        foreach ($butir as $b) {
            $kunci = ucfirst($b['aksi']).' '.$label[$b['objek_jenis']];
            $hitung[$kunci] = ($hitung[$kunci] ?? 0) + 1;
        }

        return implode(', ', array_map(fn ($k, $n) => preg_replace('/^(\w+) /', '$1 '.$n.' ', $k), array_keys($hitung), $hitung));
    }

    /** @param  array<string, mixed>  $butir */
    private function pilih(array $butir): ButirRuangTt|ButirTarif
    {
        return ($butir['objek_jenis'] ?? null) === 'tarif' ? $this->butirTarif : $this->butir;
    }

    /** Jenis data yang punya alur usulan (sumber tunggal untuk validasi controller). @return list<string> */
    public function jenisTerdaftar(): array
    {
        return DB::table('inti.alur_usulan')->orderBy('jenis_data')->pluck('jenis_data')->all();
    }

    private function alur(string $jenis): object
    {
        return DB::table('inti.alur_usulan')->where('jenis_data', $jenis)->first()
            ?? throw new ApiError(422, 'validasi_gagal', 'Jenis data tidak dikenal.', [['kolom' => 'jenis_data', 'pesan' => 'Jenis data tidak dikenal.']]);
    }

    private function pastikanIzin(string $aksi): void
    {
        abort_unless(Gate::allows("master_data.usulan.$aksi", Cakupan::rs()), 403);
    }

    private function pastikanPeran(Pengguna $pengguna, ?string $peranId, string $kode, string $pesan): void
    {
        if (! $this->memegang($pengguna, $peranId)) {
            throw new ApiError(403, $kode, $pesan);
        }
    }

    private function memegang(Pengguna $pengguna, ?string $peranId): bool
    {
        return $peranId === null || PenggunaPeran::query()->berlaku()->where('pengguna_id', $pengguna->id)->where('peran_id', $peranId)->exists();
    }

    private function pastikanBukanPengusul(Pengguna $pengguna, UsulanDataInduk $u): void
    {
        if ($u->diusulkan_oleh === $pengguna->id) {
            throw new ApiError(403, 'pengusul_sendiri', 'Pengusul tidak bisa memverifikasi, menyetujui, atau mengembalikan usulannya sendiri.');
        }
    }

    /** @param  list<string>  $tahap */
    private function pastikanTahap(UsulanDataInduk $u, array $tahap): void
    {
        if (! in_array($u->tahap, $tahap, true)) {
            throw new ApiError(409, 'tahap_tidak_sesuai', 'Usulan ini sudah berpindah tahap. Muat ulang untuk melihat keadaannya.');
        }
    }

    private function pastikanTanggal(string $tanggal): void
    {
        if ($tanggal < SuntinganBerversi::hariIni()) {
            throw new ApiError(422, 'tanggal_tidak_valid', 'Tanggal berlaku paling cepat hari ini.', [['kolom' => 'berlaku_dari', 'pesan' => 'Isi hari ini atau sesudahnya.']]);
        }
    }

    private function kunci(UsulanDataInduk $u): UsulanDataInduk
    {
        return UsulanDataInduk::query()->whereKey($u->id)->lockForUpdate()->firstOrFail();
    }

    private function simpanPutaran(UsulanDataInduk $u): void
    {
        DB::table('inti.usulan_data_induk_putaran')->insert([
            'id' => (string) Str::uuid7(), 'usulan_id' => $u->id, 'putaran' => $u->putaran, 'perubahan' => json_encode($u->perubahan),
            'berlaku_dari' => $u->berlaku_dari->toDateString(), 'alasan' => $u->alasan, 'dasar' => $u->dasar, 'ringkasan' => $u->ringkasan,
            'diajukan_pada' => now()->format('Y-m-d H:i:s.uP'),
        ]);
    }

    private function kejadian(UsulanDataInduk $u, string $aksi, ?string $oleh, ?string $catatan): void
    {
        DB::table('inti.usulan_data_induk_kejadian')->insert([
            'id' => (string) Str::uuid7(), 'usulan_id' => $u->id, 'aksi' => $aksi, 'oleh' => $oleh, 'pada' => now()->format('Y-m-d H:i:s.uP'),
            'catatan' => $catatan, 'putaran' => $u->putaran,
        ]);
    }

    private function beritahu(UsulanDataInduk $u, object $alur, ?string $catatan = null): void
    {
        UsulanTahapBerubah::dispatch($u->id, $u->nomor, $u->tahap, $u->diusulkan_oleh, $alur->peran_pemverifikasi_id, $alur->peran_penyetuju_id, $catatan);
    }

    /** @return array<string, mixed> */
    private function ringkasAudit(UsulanDataInduk $u): array
    {
        return ['nomor' => $u->nomor, 'jenis_data' => $u->jenis_data, 'putaran' => $u->putaran, 'ringkasan' => $u->ringkasan,
            'berlaku_dari' => $u->berlaku_dari->toDateString(), 'jumlah_butir' => count($u->perubahan), 'dasar' => $u->dasar];
    }

    private function nomor(): string
    {
        $urut = (int) DB::selectOne("SELECT nextval('inti.usulan_data_induk_nomor_seq') AS n")->n;

        return sprintf('UDI-%s-%06d', now(config('instalasi.zona_waktu'))->format('Y'), $urut);
    }
}
