<?php

namespace App\Modules\Lab\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Input hasil, validasi analis / otorisasi Sp.PK, dan adendum (tiket 2.31; FR-19-02, FR-19-05, FR-19-06, FR-00-09).
 * Urutan kunci sama dengan ambil/terima spesimen dan batal order: permintaan, lalu butir, lalu hasil. Penanda dihitung server
 * dari data induk. Nilai hasil tidak di-UPDATE sesudah divalidasi; koreksi = baris adendum. Audit tanpa nilai hasil,
 * `catat()` di akhir transaksi.
 */
final class KelolaHasilLab
{
    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly HitungPenanda $penanda,
        private readonly RuteHasilLab $rute,
    ) {}

    /**
     * @param  array{nilai?: float|int|null, nilai_teks?: string|null, catatan?: string|null}  $isian
     * @return array{0: string, 1: bool} [id hasil, baru]
     */
    public function input(Pengguna $oleh, string $itemId, array $isian): array
    {
        return DB::transaction(function () use ($oleh, $itemId, $isian) {
            $item = $this->kunciItem($itemId);
            if ($item->status === 'divalidasi') {
                throw new ApiError(409, 'hasil_sudah_divalidasi', 'Hasil sudah divalidasi. Koreksi lewat adendum.');
            }
            if (! in_array($item->status, ['diterima_lab', 'menunggu_validasi'], true)) {
                throw new ApiError(409, 'status_tidak_sesuai', 'Hasil hanya bisa diinput sesudah spesimen diterima lab dan sebelum validasi.', [['status' => $item->status]]);
            }
            $m = DB::table('lab.pemeriksaan')->where('tarif_id', $item->tarif_id)->where('status', 'aktif')->first()
                ?? throw new ApiError(422, 'pemeriksaan_lab_belum_diatur', 'Data induk pemeriksaan lab untuk butir ini belum diatur RS.');
            [$nilai, $teks] = $this->nilai($m, $isian);
            $hitung = $this->hitung($m, $item, $nilai, $teks);
            $ada = DB::table('lab.hasil')->where('permintaan_item_id', $item->id)->lockForUpdate()->first(['id', 'revisi']);
            $isi = [
                'pemeriksaan_id' => $m->id, 'nilai' => $nilai, 'nilai_teks' => $teks, 'satuan' => $m->satuan, 'kode_loinc' => $m->kode_loinc,
                ...$hitung, 'catatan' => $isian['catatan'] ?? null, 'status' => 'menunggu_validasi',
                'diinput_oleh' => $oleh->id, 'diinput_pada' => now(), 'diubah_pada' => now(), 'diubah_oleh' => $oleh->id,
            ];
            if ($ada === null) {
                $id = (string) Str::uuid7();
                DB::table('lab.hasil')->insert([...$isi, 'id' => $id, 'permintaan_item_id' => $item->id,
                    'dibuat_pada' => now(), 'dibuat_oleh' => $oleh->id]);
                $this->ubahItem($item, 'menunggu_validasi', $oleh);
            } else {
                $id = (string) $ada->id;
                DB::table('lab.hasil')->where('id', $id)->update([...$isi, 'revisi' => (int) $ada->revisi + 1]);
            }
            $this->audit->catat('lab.hasil_diinput', $oleh->id, 'lab.hasil', $id, ['permintaan_item_id' => $item->id, 'input_ulang' => $ada !== null]);

            return [$id, $ada === null];
        });
    }

    /** Validasi analis (menunggu_validasi) atau otorisasi Sp.PK (menunggu_otorisasi); tahap dari status, izin per tahap. */
    public function validasi(Pengguna $oleh, string $hasilId): string
    {
        $status = DB::table('lab.hasil')->where('id', $hasilId)->value('status')
            ?? throw new ApiError(404, 'tidak_ditemukan', 'Hasil lab tidak ditemukan.');
        Gate::forUser($oleh)->authorize($status === 'menunggu_otorisasi' ? BacaHasilLab::IZIN_SETUJUI : BacaHasilLab::IZIN_SAHKAN, Cakupan::rs());

        return DB::transaction(function () use ($oleh, $hasilId) {
            $itemId = (string) DB::table('lab.hasil')->where('id', $hasilId)->value('permintaan_item_id');
            $item = $this->kunciItem($itemId);
            $h = DB::table('lab.hasil')->where('id', $hasilId)->lockForUpdate()->first(['id', 'status', 'divalidasi_oleh', 'revisi', 'pemeriksaan_id']);
            $h->butuh_otorisasi_spk = (bool) DB::table('lab.pemeriksaan')->where('id', $h->pemeriksaan_id)->value('butuh_otorisasi_spk');
            $sekarang = now();
            if ($h->status === 'divalidasi') {
                throw new ApiError(409, 'hasil_sudah_divalidasi', 'Hasil sudah divalidasi.');
            }
            if ($h->status === 'menunggu_validasi') {
                // Status bisa berubah sejak izin diperiksa di luar transaksi: izin tahap ini diperiksa ulang.
                Gate::forUser($oleh)->authorize(BacaHasilLab::IZIN_SAHKAN, Cakupan::rs());
                $final = ! $h->butuh_otorisasi_spk;
                $baru = $final ? 'divalidasi' : 'menunggu_otorisasi';
                $ubah = ['divalidasi_oleh' => $oleh->id, 'divalidasi_pada' => $sekarang];
                $aksi = 'lab.hasil_divalidasi';
            } else {
                Gate::forUser($oleh)->authorize(BacaHasilLab::IZIN_SETUJUI, Cakupan::rs());
                if ((string) $h->divalidasi_oleh === $oleh->id) {
                    throw new ApiError(409, 'sudah_memvalidasi', 'Otorisasi Sp.PK harus oleh akun lain dari analis yang memvalidasi.');
                }
                $final = true;
                $baru = 'divalidasi';
                $ubah = ['diotorisasi_oleh' => $oleh->id, 'diotorisasi_pada' => $sekarang];
                $aksi = 'lab.hasil_diotorisasi';
            }
            DB::table('lab.hasil')->where('id', $hasilId)->update([...$ubah, 'status' => $baru, 'dikeluarkan_pada' => $final ? $sekarang : null,
                'diubah_pada' => $sekarang, 'diubah_oleh' => $oleh->id, 'revisi' => (int) $h->revisi + 1]);
            $this->ubahItem($item, $baru, $oleh);
            $rute = $final ? $this->rute->kirim($hasilId, $hasilId, false) : null;
            $this->audit->catat($aksi, $oleh->id, 'lab.hasil', $hasilId, ['permintaan_item_id' => $item->id, 'status' => $baru,
                'tugas_id' => $rute['tugas_id'] ?? null], ['status' => $h->status]);

            return $hasilId;
        });
    }

    /** @param  array{nilai?: float|int|null, nilai_teks?: string|null, alasan: string}  $isian */
    public function adendum(Pengguna $oleh, string $hasilId, array $isian): string
    {
        return DB::transaction(function () use ($oleh, $hasilId, $isian) {
            $itemId = DB::table('lab.hasil')->where('id', $hasilId)->value('permintaan_item_id')
                ?? throw new ApiError(404, 'tidak_ditemukan', 'Hasil lab tidak ditemukan.');
            $item = $this->kunciItem((string) $itemId);
            $h = DB::table('lab.hasil')->where('id', $hasilId)->lockForUpdate()->first(['id', 'status', 'pemeriksaan_id', 'satuan']);
            if ($h->status !== 'divalidasi') {
                throw new ApiError(409, 'status_tidak_sesuai', 'Adendum hanya untuk hasil yang sudah divalidasi.', [['status' => $h->status]]);
            }
            $m = DB::table('lab.pemeriksaan')->where('id', $h->pemeriksaan_id)->first();
            [$nilai, $teks] = $this->nilai($m, $isian);
            $id = (string) Str::uuid7();
            DB::table('lab.hasil_adendum')->insert([
                'id' => $id, 'hasil_id' => $hasilId, 'nilai' => $nilai, 'nilai_teks' => $teks, 'satuan' => $h->satuan,
                ...$this->hitung($m, $item, $nilai, $teks), 'alasan' => $isian['alasan'],
                'dibuat_pada' => now(), 'dibuat_oleh' => $oleh->id, 'diubah_pada' => now(), 'diubah_oleh' => $oleh->id,
            ]);
            $rute = $this->rute->kirim($hasilId, $id, true);
            $this->audit->catat('lab.hasil_adendum', $oleh->id, 'lab.hasil', $hasilId, ['adendum_id' => $id, 'tugas_id' => $rute['tugas_id']]);

            return $hasilId;
        });
    }

    /**
     * Isian cocok dengan jenis hasil data induk: numerik → nilai, teks → nilai_teks; selain itu 422.
     *
     * @param  array<string, mixed>  $isian
     * @return array{0: float|null, 1: string|null}
     */
    private function nilai(object $m, array $isian): array
    {
        $nilai = $isian['nilai'] ?? null;
        $teks = isset($isian['nilai_teks']) ? trim((string) $isian['nilai_teks']) : null;
        $teks = $teks === '' ? null : $teks;
        if ($m->jenis_hasil === 'numerik' && ($nilai === null || $teks !== null)) {
            throw new ApiError(422, 'validasi_gagal', 'Hasil pemeriksaan ini berupa angka: isi nilai, tanpa nilai teks.',
                [['kolom' => 'nilai', 'pesan' => 'Nilai angka wajib untuk pemeriksaan ini.']]);
        }
        if ($m->jenis_hasil === 'teks' && ($teks === null || $nilai !== null)) {
            throw new ApiError(422, 'validasi_gagal', 'Hasil pemeriksaan ini berupa teks: isi nilai teks, tanpa nilai angka.',
                [['kolom' => 'nilai_teks', 'pesan' => 'Nilai teks wajib untuk pemeriksaan ini.']]);
        }

        return [$nilai === null ? null : (float) $nilai, $teks];
    }

    /** @return array<string, string|null> */
    private function hitung(object $m, object $item, ?float $nilai, ?string $teks): array
    {
        $pasien = DB::table('pasien.pasien')->where('id', $item->pasien_id)->first(['tanggal_lahir', 'jenis_kelamin']);
        $diambil = (string) DB::table('lab.spesimen')->where('permintaan_item_id', $item->id)->value('diambil_pada');

        return $this->penanda->hitung($m, $pasien->tanggal_lahir === null ? null : (string) $pasien->tanggal_lahir,
            (string) $pasien->jenis_kelamin, HitungPenanda::tanggalSpesimen($diambil), $nilai, $teks);
    }

    /** Kunci permintaan lalu butirnya (urutan KelolaSpesimen dan batal order). Bukan order lab → 404; batal → 409. */
    private function kunciItem(string $itemId): object
    {
        $permintaanId = DB::table('permintaan.permintaan_item')->where('id', $itemId)->value('permintaan_pemeriksaan_id')
            ?? throw new ApiError(404, 'tidak_ditemukan', 'Butir order tidak ditemukan.');
        $p = DB::table('permintaan.permintaan_pemeriksaan')->where('id', $permintaanId)->lockForUpdate()->first(['id', 'jenis', 'status', 'pasien_id', 'episode_id']);
        $item = DB::table('permintaan.permintaan_item')->where('id', $itemId)->lockForUpdate()->first(['id', 'status', 'revisi', 'tarif_id']);
        if ($p === null || $item === null || $p->jenis !== 'lab') {
            throw new ApiError(404, 'tidak_ditemukan', 'Butir order lab tidak ditemukan.');
        }
        if ($p->status === 'batal' || $item->status === 'batal') {
            throw new ApiError(409, 'status_tidak_sesuai', 'Order sudah dibatalkan.', [['status' => 'batal']]);
        }
        $item->pasien_id = $p->pasien_id;
        $item->episode_id = $p->episode_id;

        return $item;
    }

    private function ubahItem(object $item, string $status, Pengguna $oleh): void
    {
        DB::table('permintaan.permintaan_item')->where('id', $item->id)
            ->update(['status' => $status, 'diubah_pada' => now(), 'diubah_oleh' => $oleh->id, 'revisi' => (int) $item->revisi + 1]);
        $item->revisi = (int) $item->revisi + 1;
    }
}
