<?php

namespace App\Modules\MasterData\Application;

use App\Core\Audit\CatatAktivitas;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\Penjamin;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Data induk penjamin (tiket 2.1, kontrak v0.17.0). Tanpa DELETE (status nonaktif). Versi = revisi (412 bila berubah).
 * Kode ganda → 422 validasi_gagal pada field `kode`. Larangan mengubah `jenis` setelah dipakai kunjungan menunggu
 * tabel kunjungan (tiket 2.x pendaftaran); lihat Penjamin::dipakaiKunjungan.
 */
final class KelolaPenjamin
{
    public function __construct(private readonly CatatAktivitas $audit) {}

    /** @param  array<string, mixed>  $isi */
    public function buat(Pengguna $penyunting, array $isi): Penjamin
    {
        $penjamin = new Penjamin([
            'kode' => $isi['kode'], 'nama' => $isi['nama'], 'jenis' => $isi['jenis'], 'status' => 'aktif',
            'masa_berlaku_gl_hari' => $isi['masa_berlaku_gl_hari'] ?? null, 'plafon' => $isi['plafon'] ?? null,
            'jatuh_tempo_hari' => $isi['jatuh_tempo_hari'] ?? null,
        ]);

        return $this->tangkapKodeGanda(fn () => DB::transaction(function () use ($penyunting, $penjamin, $isi) {
            $penjamin->forceFill(['dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('master_data.penjamin_dibuat', $penyunting->id, $penjamin->getTable(), $penjamin->id,
                [...$this->isiAudit($penjamin->refresh()), 'dasar_perubahan' => $isi['dasar_perubahan']]);

            return $penjamin;
        }));
    }

    /** @param  array<string, mixed>  $isi */
    public function ubah(Pengguna $penyunting, Penjamin $penjamin, string $versi, array $isi): Penjamin
    {
        return $this->tangkapKodeGanda(fn () => DB::transaction(function () use ($penyunting, $penjamin, $versi, $isi) {
            SuntinganBerversi::naikkan(Penjamin::class, $penjamin->id, $versi, $penyunting->id, 'Penjamin');
            $penjamin->refresh();
            $sebelum = $this->isiAudit($penjamin);
            $penjamin->fill(array_intersect_key($isi, array_flip(['kode', 'nama', 'jenis', 'status', 'masa_berlaku_gl_hari', 'plafon', 'jatuh_tempo_hari'])));
            $penjamin->forceFill(['diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('master_data.penjamin_diubah', $penyunting->id, $penjamin->getTable(), $penjamin->id,
                [...$this->isiAudit($penjamin->refresh()), 'dasar_perubahan' => $isi['dasar_perubahan']], $sebelum);

            return $penjamin;
        }));
    }

    /** @return array<string, mixed> */
    private function isiAudit(Penjamin $penjamin): array
    {
        return array_diff_key($penjamin->attributesToArray(), array_flip(['id', 'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'revisi']));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $kerja
     * @return T
     */
    private function tangkapKodeGanda(callable $kerja): mixed
    {
        try {
            return $kerja();
        } catch (QueryException $e) {
            throw $e->getCode() === '23505'
                ? ValidationException::withMessages(['kode' => 'Kode sudah dipakai penjamin lain.'])
                : $e;
        }
    }
}
