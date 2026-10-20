<?php

namespace App\Modules\MasterData\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\KelasRawat;
use App\Modules\MasterData\Models\Tarif;
use App\Modules\MasterData\Models\TarifVersi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Perubahan buku tarif (tiket 2.2, FR-28-13). Dipakai mesin usulan 1.8c (`ButirTarif`) dan pengisian awal saat penyiapan
 * (`buatLangsung`, ditolak 409 `harus_lewat_usulan` setelah siap melayani). Setiap perubahan = versi baru; tanpa DELETE.
 * Tidak ada jalur ubah harga untuk kasir/tagihan: modul itu hanya membaca lewat BukuTarif.
 */
final class KelolaTarif
{
    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly BukuTarif $buku,
        private readonly CatatVersi $catatVersi,
        private readonly PenjagaUsulan $penjaga,
    ) {}

    /**
     * Pengisian awal (penyiapan): hanya sebelum instalasi siap melayani.
     *
     * @param  array{kode: string, nama: string, kelompok: string, harga: list<array<string, mixed>>}  $isi
     */
    public function buatLangsung(Pengguna $penyunting, array $isi, string $berlakuDari, string $dasar): Tarif
    {
        $this->penjaga->pastikanBolehLangsung('buku tarif');

        return $this->buat($penyunting, $isi, $berlakuDari, $dasar);
    }

    /** @param  array{kode: string, nama: string, kelompok: string, harga: list<array<string, mixed>>}  $isi */
    public function buat(Pengguna $penyunting, array $isi, string $berlakuDari, string $dasar): Tarif
    {
        $this->pastikanKelas($isi['harga']);
        $tarif = new Tarif(['kode' => $isi['kode']]);

        return SuntinganBerversi::tangkapKodeGanda(fn () => DB::transaction(function () use ($penyunting, $tarif, $isi, $berlakuDari, $dasar) {
            $tarif->forceFill(['dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id])->save();
            $v = $this->tambahVersi($penyunting, $tarif, 1, ['nama' => $isi['nama'], 'kelompok' => $isi['kelompok'], 'status' => 'aktif', 'harga' => $isi['harga']], $berlakuDari, $dasar);
            $this->audit->catat('master_data.tarif_dibuat', $penyunting->id, $tarif->getTable(), $tarif->id,
                ['kode' => $tarif->kode, 'nama' => $v->nama, 'berlaku_dari' => $berlakuDari, 'dasar_perubahan' => $dasar]);

            return $tarif;
        }));
    }

    /**
     * Versi baru dari isi terbaru dengan isian yang diubah (nama, kelompok, status, harga).
     *
     * @param  array<string, mixed>  $isi
     */
    public function ubah(Pengguna $penyunting, Tarif $tarif, string $versi, array $isi, string $berlakuDari, string $dasar): Tarif
    {
        if (isset($isi['harga'])) {
            $this->pastikanKelas($isi['harga']);
        }

        return DB::transaction(function () use ($penyunting, $tarif, $versi, $isi, $berlakuDari, $dasar) {
            SuntinganBerversi::naikkan(Tarif::class, $tarif->id, $versi, $penyunting->id, 'Tarif');
            $terbaru = $this->buku->versiTerbaru($tarif->id) ?? throw new \LogicException('Tarif tanpa versi.');
            $lama = $this->buku->isi($terbaru);
            $baru = [...$lama, ...array_intersect_key($isi, array_flip(['nama', 'kelompok', 'status', 'harga']))];
            $v = $this->tambahVersi($penyunting, $tarif, $terbaru->versi + 1, $baru, $berlakuDari, $dasar);
            $this->audit->catat('master_data.tarif_diubah', $penyunting->id, $tarif->getTable(), $tarif->id,
                ['kode' => $tarif->kode, 'versi' => $v->versi, 'berlaku_dari' => $berlakuDari, 'dasar_perubahan' => $dasar]);

            return $tarif->refresh();
        });
    }

    /** Butir tidak berlaku mulai `berlakuDari` (versi nonaktif; riwayat harga tetap terbaca). */
    public function tutup(Pengguna $penyunting, Tarif $tarif, string $versi, string $berlakuDari, string $alasan, string $dasar): Tarif
    {
        return DB::transaction(function () use ($penyunting, $tarif, $versi, $berlakuDari, $alasan, $dasar) {
            SuntinganBerversi::naikkan(Tarif::class, $tarif->id, $versi, $penyunting->id, 'Tarif');
            $terbaru = $this->buku->versiTerbaru($tarif->id) ?? throw new \LogicException('Tarif tanpa versi.');
            $v = $this->tambahVersi($penyunting, $tarif, $terbaru->versi + 1, [...$this->buku->isi($terbaru), 'status' => 'nonaktif'], $berlakuDari, $dasar);
            $this->audit->catat('master_data.tarif_ditutup', $penyunting->id, $tarif->getTable(), $tarif->id,
                ['kode' => $tarif->kode, 'versi' => $v->versi, 'berlaku_dari' => $berlakuDari, 'alasan' => $alasan, 'dasar_perubahan' => $dasar]);

            return $tarif->refresh();
        });
    }

    /** @param  array{nama: string, kelompok: string, status: string, harga: list<array<string, mixed>>}  $isi */
    private function tambahVersi(Pengguna $penyunting, Tarif $tarif, int $nomor, array $isi, string $berlakuDari, string $dasar): TarifVersi
    {
        $v = new TarifVersi([
            'tarif_id' => $tarif->id, 'versi' => $nomor, 'nama' => $isi['nama'], 'kelompok' => $isi['kelompok'], 'status' => $isi['status'],
            'berlaku_dari' => $berlakuDari, 'usulan_id' => $this->catatVersi->usulanAktif(), 'dasar' => $dasar, 'dibuat_oleh' => $penyunting->id,
        ]);
        $v->save();
        DB::table('keuangan.tarif_versi_harga')->insert(array_map(fn (array $h) => [
            'id' => (string) Str::uuid7(), 'tarif_versi_id' => $v->id, 'kelas_rawat_id' => $h['kelas_rawat_id'],
            'jasa_sarana' => BukuTarif::rupiah((string) $h['jasa_sarana']), 'jasa_pelayanan' => BukuTarif::rupiah((string) $h['jasa_pelayanan']),
            'bhp' => BukuTarif::rupiah((string) $h['bhp']),
        ], $isi['harga']));

        return $v;
    }

    /** @param  list<array<string, mixed>>  $harga */
    private function pastikanKelas(array $harga): void
    {
        $id = array_column($harga, 'kelas_rawat_id');
        $aktif = KelasRawat::query()->whereIn('id', $id)->where('status', 'aktif')->pluck('id')->all();
        $salah = array_values(array_diff($id, $aktif));
        if ($salah !== [] || $id === []) {
            throw new ApiError(422, 'kelas_tidak_valid', 'Kelas rawat tidak ditemukan atau tidak aktif.', [['kolom' => 'harga', 'pesan' => 'Pilih kelas rawat yang aktif.']]);
        }
    }
}
