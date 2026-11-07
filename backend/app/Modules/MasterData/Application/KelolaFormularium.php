<?php

namespace App\Modules\MasterData\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\SuntinganBerversi;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\Barang;
use App\Modules\MasterData\Models\Formularium;
use App\Modules\MasterData\Models\ItemFormularium;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Formularium dan itemnya (tiket 2.11, kontrak v0.23.0). Hanya sebelum siap melayani (sesudahnya 409
 * `harus_lewat_usulan`, jalur usulan di 2.12). Satu formularium per (jenis, penjamin) pada satu tanggal.
 */
final class KelolaFormularium
{
    public function __construct(private readonly CatatAktivitas $audit, private readonly PenjagaUsulan $penjaga) {}

    /** @param  array<string, mixed>  $isi */
    public function buat(Pengguna $penyunting, array $isi): Formularium
    {
        $this->penjaga->pastikanBolehLangsung('formularium');
        $this->periksaPenjamin($isi['jenis'], $isi['penjamin_id'] ?? null);

        return KelolaBarang::tangkapKodeGanda(fn () => DB::transaction(function () use ($penyunting, $isi) {
            $f = new Formularium([...array_intersect_key($isi, array_flip(['kode', 'nama', 'jenis', 'berlaku_dari', 'berlaku_sampai'])),
                'penjamin_id' => $isi['jenis'] === 'penjamin' ? $isi['penjamin_id'] : null]);
            $this->tolakTumpangTindih($f);
            $f->forceFill(['dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('master_data.formularium_dibuat', $penyunting->id, $f->getTable(), $f->id,
                [...$this->isiAudit($f->refresh()), 'dasar_perubahan' => $isi['dasar_perubahan']]);

            return $f;
        }), 'Kode sudah dipakai formularium lain.');
    }

    /** @param  array<string, mixed>  $isi */
    public function ubah(Pengguna $penyunting, Formularium $f, string $versi, array $isi): Formularium
    {
        $this->penjaga->pastikanBolehLangsung('formularium');

        return DB::transaction(function () use ($penyunting, $f, $versi, $isi) {
            SuntinganBerversi::naikkan(Formularium::class, $f->id, $versi, $penyunting->id, 'Formularium');
            $f->refresh();
            $sebelum = $this->isiAudit($f);
            $f->fill(array_intersect_key($isi, array_flip(['nama', 'berlaku_dari', 'berlaku_sampai'])));
            if ($f->berlaku_sampai !== null && $f->berlaku_sampai < $f->berlaku_dari) {
                throw ValidationException::withMessages(['berlaku_sampai' => 'Tanggal akhir tidak boleh sebelum tanggal mulai.']);
            }
            $this->tolakTumpangTindih($f);
            $f->forceFill(['diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('master_data.formularium_diubah', $penyunting->id, $f->getTable(), $f->id,
                [...$this->isiAudit($f->refresh()), 'dasar_perubahan' => $isi['dasar_perubahan']], $sebelum);

            return $f;
        });
    }

    /** @param  array<string, mixed>  $isi */
    public function tambahItem(Pengguna $penyunting, Formularium $f, array $isi): ItemFormularium
    {
        $this->penjaga->pastikanBolehLangsung('formularium');

        return DB::transaction(function () use ($penyunting, $f, $isi) {
            $barang = Barang::query()->whereKey($isi['barang_id'])->sharedLock()->first();
            if ($barang === null || $barang->status !== 'aktif') {
                throw ValidationException::withMessages(['barang_id' => 'Barang tidak ditemukan atau nonaktif.']);
            }
            if (ItemFormularium::query()->where('formularium_id', $f->id)->where('barang_id', $barang->id)->exists()) {
                throw ValidationException::withMessages(['barang_id' => 'Barang sudah ada di formularium ini.']);
            }
            // Dua tambah serentak barang yang sama: kunci unik (formularium_id, barang_id) → 422, bukan 500.
            $item = KelolaBarang::tangkapKodeGanda(fn () => $this->simpanItem($penyunting, $f, $barang->id, $isi), 'Barang sudah ada di formularium ini.', 'barang_id');
            $this->audit->catat('master_data.formularium_item_dibuat', $penyunting->id, $item->getTable(), $item->id,
                [...$this->isiAudit($item), 'dasar_perubahan' => $isi['dasar_perubahan']]);

            return $item;
        });
    }

    /** @param  array<string, mixed>  $isi */
    public function ubahItem(Pengguna $penyunting, ItemFormularium $item, string $versi, array $isi): ItemFormularium
    {
        $this->penjaga->pastikanBolehLangsung('formularium');

        return DB::transaction(function () use ($penyunting, $item, $versi, $isi) {
            SuntinganBerversi::naikkan(ItemFormularium::class, $item->id, $versi, $penyunting->id, 'Item formularium');
            $item->refresh();
            $sebelum = $this->isiAudit($item);
            $item->fill(array_intersect_key($isi, array_flip(['restriksi', 'maks_per_resep', 'kronis', 'status'])));
            $item->forceFill(['diubah_oleh' => $penyunting->id])->save();
            $this->audit->catat('master_data.formularium_item_diubah', $penyunting->id, $item->getTable(), $item->id,
                [...$this->isiAudit($item->refresh()), 'dasar_perubahan' => $isi['dasar_perubahan']], $sebelum);

            return $item;
        });
    }

    /** Dipakai impor item (di dalam transaksinya). @param  array<string, mixed>  $isi */
    public function simpanItem(Pengguna $penyunting, Formularium $f, string $barangId, array $isi): ItemFormularium
    {
        $item = new ItemFormularium([
            'formularium_id' => $f->id, 'barang_id' => $barangId, 'restriksi' => $isi['restriksi'] ?? null,
            'maks_per_resep' => $isi['maks_per_resep'] ?? null, 'kronis' => $isi['kronis'] ?? false,
        ]);
        $item->forceFill(['dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id])->save();

        return $item->refresh();
    }

    private function periksaPenjamin(string $jenis, ?string $penjaminId): void
    {
        if ($jenis !== 'penjamin' && $penjaminId !== null) {
            throw ValidationException::withMessages(['penjamin_id' => 'Penjamin hanya untuk formularium jenis penjamin.']);
        }
        if ($jenis === 'penjamin' && ($penjaminId === null || ! DB::table('inti.penjamin')->where('id', $penjaminId)->where('status', 'aktif')->exists())) {
            throw ValidationException::withMessages(['penjamin_id' => 'Pilih penjamin yang aktif.']);
        }
    }

    /** Rentang berlaku tidak boleh tumpang tindih dengan formularium lain berjenis dan berpenjamin sama. */
    private function tolakTumpangTindih(Formularium $f): void
    {
        // Kunci per (jenis, penjamin) sampai commit agar dua simpanan serentak tidak sama-sama lolos.
        DB::select('select pg_advisory_xact_lock(hashtext(?))', ['farmasi.formularium:'.$f->jenis.':'.$f->penjamin_id]);
        $bentrok = Formularium::query()->where('jenis', $f->jenis)
            ->when($f->penjamin_id, fn ($q, $id) => $q->where('penjamin_id', $id), fn ($q) => $q->whereNull('penjamin_id'))
            ->when($f->exists, fn ($q) => $q->whereKeyNot($f->id))
            ->where(fn ($q) => $q->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', $f->berlaku_dari))
            ->when($f->berlaku_sampai, fn ($q, $sampai) => $q->where('berlaku_dari', '<=', $sampai))
            ->exists();
        if ($bentrok) {
            throw ValidationException::withMessages(['berlaku_dari' => 'Masa berlaku tumpang tindih dengan formularium lain berjenis sama.']);
        }
    }

    /** @return array<string, mixed> */
    private function isiAudit(Formularium|ItemFormularium $m): array
    {
        return array_diff_key($m->attributesToArray(), array_flip(['id', 'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'revisi']));
    }
}
