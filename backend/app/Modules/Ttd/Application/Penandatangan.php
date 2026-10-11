<?php

namespace App\Modules\Ttd\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Ttd\Domain\IsiKanonik;
use App\Modules\Ttd\Domain\KunciTtd;
use App\Modules\Ttd\Models\Kredensial;
use App\Modules\Ttd\Models\TandaTangan;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Penandatanganan dan verifikasi T1 (tiket 1.6; PRD 7.57.2 huruf c, f). Penanda tangan selalu akun yang masuk; isi
 * dihitung server dari objeknya. Semua objek diperiksa dulu (semua-atau-tidak); objek yang sudah ditandatangani akun ini
 * tidak digandakan.
 */
final class Penandatangan
{
    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly DaftarPenyediaIsi $penyedia,
        private readonly PenjagaPin $penjaga,
        private readonly SesiTtd $sesi,
    ) {}

    /**
     * @param  list<array{jenis: string, id: string}>  $objek
     * @return array{0: list<array{tanda_tangan: TandaTangan, baru: bool, status: string}>, 1: Carbon} hasil seurut permintaan dan akhir sesi
     */
    public function tandatangani(Request $request, Pengguna $pengguna, array $objek, ?string $pin): array
    {
        $kredensial = $this->penjaga->kredensialAktif($pengguna);
        if ($kredensial->terkunci()) {
            throw $this->penjaga->terkunci($kredensial);
        }
        $this->periksaObjek($pengguna, $objek);

        $rahasia = $pin === null ? $this->sesi->buka($request, $kredensial) : null;
        if ($pin === null && $rahasia === null) {
            throw new ApiError(422, 'pin_wajib', 'Masukkan PIN untuk menandatangani.', [['kolom' => 'pin', 'kode' => 'pin_wajib', 'pesan' => 'Masukkan PIN.']]);
        }
        if ($rahasia === null) {
            [$kredensial, $rahasia] = $this->penjaga->buka($pengguna, (string) $pin);
            $this->sesi->mulai($request, $kredensial, $rahasia);
        }

        try {
            $hasil = DB::transaction(function () use ($pengguna, $objek, $kredensial, $rahasia, $pin) {
                // Kredensial yang sama masih aktif (tidak dicabut/terkunci di antara buka kunci dan simpan).
                $k = Kredensial::query()->whereKey($kredensial->id)->where('status', 'aktif')->lockForUpdate()->first();
                if ($k === null || $k->terkunci()) {
                    throw new ApiError(409, 'ttd_belum_aktif', 'Tanda tangan elektronik sudah tidak aktif.');
                }
                $this->periksaObjek($pengguna, $objek);
                $hasil = [];
                $baru = [];
                foreach ($objek as $o) {
                    $lama = TandaTangan::query()->where('objek_jenis', $o['jenis'])->where('objek_id', $o['id'])->where('pengguna_id', $pengguna->id)->first();
                    if ($lama !== null) {
                        // Tanda tangan lama diverifikasi ulang (isi objek bisa sudah berubah sejak ditandatangani).
                        $hasil[] = ['tanda_tangan' => $lama, 'baru' => false, 'status' => $this->status($lama, $this->hashSekarang($o['jenis'], $o['id']))];

                        continue;
                    }
                    $hash = IsiKanonik::hash((array) $this->penyedia->untuk($o['jenis'])?->isi($o['id']));
                    $cap = now();
                    $t = new TandaTangan([
                        'objek_jenis' => $o['jenis'], 'objek_id' => $o['id'], 'penanda_tangan_jenis' => 'pengguna', 'pengguna_id' => $pengguna->id,
                        'kredensial_id' => $k->id, 'tingkat' => 'T1', 'metode' => 'pin', 'hash_isi' => $hash,
                        'nilai_tanda_tangan' => KunciTtd::tandatangani($rahasia, IsiKanonik::pesan($o['jenis'], $o['id'], $hash, $cap, $pengguna->id)),
                        'cap_waktu' => $cap, 'bukti' => ['buka_kunci' => $pin === null ? 'sesi' : 'pin'], 'ditandatangani_pada' => $cap,
                    ]);
                    $t->save();
                    $hasil[] = ['tanda_tangan' => $t, 'baru' => true, 'status' => 'sah'];
                    $baru[] = $t;
                }
                // Audit di akhir transaksi (kunci rantai dipegang sampai commit).
                foreach ($baru as $t) {
                    $this->audit->catat('ttd.ditandatangani', $pengguna->id, $t->objek_jenis, $t->objek_id, [
                        'tanda_tangan_id' => $t->id, 'hash_isi' => $t->hash_isi, 'sidik_kunci' => KunciTtd::sidik((string) $k->kunci_publik),
                    ]);
                }

                return $hasil;
            });
        } finally {
            sodium_memzero($rahasia);
        }

        return [$hasil, $this->sesi->berlakuSampai($request, $kredensial) ?? now()];
    }

    /**
     * Verifikasi ulang semua tanda tangan objek (urut ditandatangani_pada); 404 bila akun tidak boleh melihat objeknya.
     *
     * @return Collection<int, array{tanda_tangan: TandaTangan, status: string}>
     */
    public function verifikasi(Pengguna $pengguna, string $jenis, string $id): Collection
    {
        $penyedia = $this->penyedia->untuk($jenis);
        if ($penyedia === null || ! $penyedia->terlihat($pengguna, $id)) {
            throw new ApiError(404, 'tidak_ditemukan', 'Data tidak ditemukan.');
        }
        $hashSekarang = $this->hashSekarang($jenis, $id);
        // Fase 1 hanya T1 yang diverifikasi di sini; T2 (PSrE) diverifikasi lewat sertifikat penyedia saat PSrE dipilih.
        $daftar = TandaTangan::query()->where('objek_jenis', $jenis)->where('objek_id', $id)->where('tingkat', 'T1')->orderBy('ditandatangani_pada')->orderBy('id')->limit(100)->get();

        return $daftar->toBase()->map(fn (TandaTangan $t) => ['tanda_tangan' => $t, 'status' => $this->status($t, $hashSekarang)])->values();
    }

    /** Keaslian dulu (baris palsu → tanda_tangan_tidak_sah), lalu isi (kontrak v0.8.0). */
    private function status(TandaTangan $t, ?string $hashSekarang): string
    {
        $publik = (string) Kredensial::query()->whereKey($t->kredensial_id)->value('kunci_publik');

        return match (true) {
            ! KunciTtd::cocok($publik, IsiKanonik::pesan($t->objek_jenis, $t->objek_id, $t->hash_isi, $t->cap_waktu, (string) $t->pengguna_id), $t->nilai_tanda_tangan) => 'tanda_tangan_tidak_sah',
            $hashSekarang === null || ! hash_equals($t->hash_isi, $hashSekarang) => 'isi_berubah',
            default => 'sah',
        };
    }

    private function hashSekarang(string $jenis, string $id): ?string
    {
        $isi = $this->penyedia->untuk($jenis)?->isi($id);

        return $isi === null ? null : IsiKanonik::hash($isi);
    }

    /** @param  list<array{jenis: string, id: string}>  $objek */
    private function periksaObjek(Pengguna $pengguna, array $objek): void
    {
        $rincian = [];
        foreach ($objek as $i => $o) {
            $penyedia = $this->penyedia->untuk($o['jenis']);
            $alasan = $penyedia === null ? ['tidak_ditemukan', 'Objek tidak ditemukan.'] : $penyedia->alasanTidakBoleh($pengguna, $o['id']);
            if ($alasan !== null) {
                $rincian[] = ['kolom' => "objek.{$i}", 'kode' => $alasan[0], 'pesan' => $alasan[1]];
            }
        }
        if ($rincian !== []) {
            throw new ApiError(422, 'objek_tidak_bisa_ditandatangani', 'Sebagian objek tidak bisa Anda tandatangani; tidak ada yang ditandatangani.', $rincian);
        }
    }
}
