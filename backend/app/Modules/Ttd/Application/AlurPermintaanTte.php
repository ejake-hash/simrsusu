<?php

namespace App\Modules\Ttd\Application;

use App\Core\Audit\CatatAktivitas;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Ttd\Domain\IsiKanonik;
use App\Modules\Ttd\Domain\PenyediaTte;
use App\Modules\Ttd\Models\TandaTangan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Alur asinkron T2 (FR-57-04; fase 1 hanya untuk uji dengan SimulatorTte, tanpa endpoint publik, K10): permintaan
 * dicatat menunggu → dikirim ke penyedia (terkirim) → hasil webhook mengubah status; ditandatangani = baris
 * ttd.tanda_tangan tingkat T2 metode psre. Webhook nyata (verifikasi tanda tangan penyedia) dibuat saat PSrE dipilih.
 */
final class AlurPermintaanTte
{
    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly DaftarPenyediaIsi $penyedia,
        private readonly PenyediaTte $tte,
    ) {}

    public function minta(Pengguna $penandaTangan, string $objekJenis, string $objekId): string
    {
        $isi = $this->penyedia->untuk($objekJenis)?->isi($objekId) ?? throw new RuntimeException('Objek tidak ditemukan.');
        $hash = IsiKanonik::hash($isi);
        $id = (string) Str::uuid7();
        DB::table('ttd.permintaan')->insert([
            'id' => $id, 'objek_jenis' => $objekJenis, 'objek_id' => $objekId, 'penanda_tangan_id' => $penandaTangan->id,
            'tingkat' => 'T2', 'penyedia' => $this->tte->kode(), 'status' => 'menunggu', 'hash_isi' => $hash, 'dibuat_oleh' => $penandaTangan->id,
        ]);
        $transaksi = $this->tte->kirim($id, $penandaTangan->id, $hash);
        DB::table('ttd.permintaan')->where('id', $id)->update(['status' => 'terkirim', 'id_transaksi_penyedia' => $transaksi, 'diubah_pada' => now()]);

        return $id;
    }

    /** Tiruan penerimaan webhook: status diambil dari penyedia (bukan dari isi webhook), aman diulang. */
    public function terimaHasil(string $idTransaksi): string
    {
        return DB::transaction(function () use ($idTransaksi) {
            $p = DB::table('ttd.permintaan')->where('id_transaksi_penyedia', $idTransaksi)->lockForUpdate()->first()
                ?? throw new RuntimeException('Transaksi tidak dikenal.');
            $status = $this->tte->status($idTransaksi);
            if ($p->status !== 'terkirim' || $status === 'menunggu') {
                return (string) $p->status;
            }
            DB::table('ttd.permintaan')->where('id', $p->id)->update(['status' => $status, 'selesai_pada' => now(), 'diubah_pada' => now()]);
            if ($status === 'ditandatangani') {
                (new TandaTangan([
                    'objek_jenis' => $p->objek_jenis, 'objek_id' => $p->objek_id, 'penanda_tangan_jenis' => 'pengguna',
                    'pengguna_id' => $p->penanda_tangan_id, 'tingkat' => 'T2', 'metode' => 'psre', 'hash_isi' => $p->hash_isi,
                    'nilai_tanda_tangan' => base64_encode($idTransaksi), 'cap_waktu' => now(), 'bukti' => ['penyedia' => $p->penyedia],
                    'ditandatangani_pada' => now(),
                ]))->save();
            }
            $this->audit->catat('ttd.permintaan_selesai', null, 'ttd.permintaan', $p->id, ['status' => $status, 'penyedia' => $p->penyedia]);

            return $status;
        });
    }
}
