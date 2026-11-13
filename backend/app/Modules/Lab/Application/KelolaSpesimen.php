<?php

namespace App\Modules\Lab\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Http\SuntinganBerversi;
use App\Modules\Akses\Models\Pengguna;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ambil sampel dan terima spesimen di lab (tiket 2.30; FR-19-02, FR-19-03, 7.00.5 butir 4). Jam dari server, pelaku dari
 * sesi. Ambil, terima, dan batal order (2.16) mengunci baris permintaan yang sama lebih dulu, lalu butirnya, jadi ketiganya
 * tidak balapan. Audit tanpa data pasien.
 */
final class KelolaSpesimen
{
    public function __construct(private readonly CatatAktivitas $audit) {}

    /** @param  array{jenis: string, lokasi_pengambilan: string}  $isian */
    public function ambil(Pengguna $oleh, string $itemId, array $isian): string
    {
        return DB::transaction(function () use ($oleh, $itemId, $isian) {
            $item = $this->kunciItem($itemId);
            if ($item->status !== 'dipesan') {
                throw new ApiError(409, 'status_tidak_sesuai', 'Sampel butir ini sudah diambil.', [['status' => $item->status]]);
            }
            $id = (string) Str::uuid7();
            $barcode = $this->barcodeBaru();
            DB::table('lab.spesimen')->insert([
                'id' => $id, 'permintaan_item_id' => $item->id, 'barcode' => $barcode, 'jenis' => $isian['jenis'],
                'lokasi_pengambilan' => $isian['lokasi_pengambilan'], 'diambil_pada' => now(), 'diambil_oleh' => $oleh->id,
                'dibuat_pada' => now(), 'dibuat_oleh' => $oleh->id, 'diubah_pada' => now(), 'diubah_oleh' => $oleh->id,
            ]);
            $this->ubahItem($item, 'sampel_diambil', $oleh);
            $this->audit->catat('lab.spesimen_diambil', $oleh->id, 'lab.spesimen', $id, ['permintaan_item_id' => $item->id, 'barcode' => $barcode]);

            return $id;
        });
    }

    public function terima(Pengguna $oleh, string $spesimenId): string
    {
        return DB::transaction(function () use ($oleh, $spesimenId) {
            $itemId = DB::table('lab.spesimen')->where('id', $spesimenId)->value('permintaan_item_id')
                ?? throw new ApiError(404, 'tidak_ditemukan', 'Spesimen tidak ditemukan.');
            $item = $this->kunciItem((string) $itemId);
            $s = DB::table('lab.spesimen')->where('id', $spesimenId)->lockForUpdate()->first(['id', 'diterima_pada', 'revisi']);
            if ($s === null) {
                throw new ApiError(404, 'tidak_ditemukan', 'Spesimen tidak ditemukan.');
            }
            if ($s->diterima_pada !== null || $item->status !== 'sampel_diambil') {
                throw new ApiError(409, 'status_tidak_sesuai', 'Spesimen sudah diterima atau sampelnya belum diambil.', [['status' => $item->status]]);
            }
            DB::table('lab.spesimen')->where('id', $spesimenId)->update([
                'diterima_pada' => now(), 'diterima_oleh' => $oleh->id, 'kondisi' => 'baik',
                'diubah_pada' => now(), 'diubah_oleh' => $oleh->id, 'revisi' => (int) $s->revisi + 1,
            ]);
            $this->ubahItem($item, 'diterima_lab', $oleh);
            $this->audit->catat('lab.spesimen_diterima', $oleh->id, 'lab.spesimen', $spesimenId, ['permintaan_item_id' => $item->id]);

            return $spesimenId;
        });
    }

    /**
     * Kunci permintaan lalu butirnya (urutan sama dengan batal order). Butir bukan order lab → 404; order atau butir batal → 409.
     */
    private function kunciItem(string $itemId): object
    {
        $permintaanId = DB::table('permintaan.permintaan_item')->where('id', $itemId)->value('permintaan_pemeriksaan_id')
            ?? throw new ApiError(404, 'tidak_ditemukan', 'Butir order tidak ditemukan.');
        $p = DB::table('permintaan.permintaan_pemeriksaan')->where('id', $permintaanId)->lockForUpdate()->first(['id', 'jenis', 'status']);
        $item = DB::table('permintaan.permintaan_item')->where('id', $itemId)->lockForUpdate()->first(['id', 'status', 'revisi']);
        if ($p === null || $item === null || $p->jenis !== 'lab') {
            throw new ApiError(404, 'tidak_ditemukan', 'Butir order lab tidak ditemukan.');
        }
        if ($p->status === 'batal' || $item->status === 'batal') {
            throw new ApiError(409, 'status_tidak_sesuai', 'Order sudah dibatalkan.', [['status' => 'batal']]);
        }

        return $item;
    }

    private function ubahItem(object $item, string $status, Pengguna $oleh): void
    {
        DB::table('permintaan.permintaan_item')->where('id', $item->id)
            ->update(['status' => $status, 'diubah_pada' => now(), 'diubah_oleh' => $oleh->id, 'revisi' => (int) $item->revisi + 1]);
    }

    /** `LAB-YYYYMMDD-00001`: penghitung per tanggal zona RS, dinaikkan di transaksi pemanggil (pola nomor kunjungan). */
    private function barcodeBaru(): string
    {
        $tanggal = SuntinganBerversi::hariIni();
        $urut = DB::selectOne('INSERT INTO lab.nomor_spesimen (tanggal, terakhir) VALUES (?, 1)
            ON CONFLICT (tanggal) DO UPDATE SET terakhir = lab.nomor_spesimen.terakhir + 1 RETURNING terakhir', [$tanggal])->terakhir;

        return sprintf('LAB-%s-%05d', str_replace('-', '', $tanggal), $urut);
    }
}
