<?php

namespace App\Modules\MasterData\Application;

use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Http\Controllers\IsianOrganisasi;
use App\Modules\MasterData\Models\Barang;
use App\Modules\MasterData\Models\Formularium;
use App\Modules\MasterData\Models\ItemFormularium;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Butir usulan jenis data `formularium` (tiket 2.12, kontrak v0.24.0): objek `formularium` (tambah/ubah) dan
 * `formularium_item` (tambah/ubah/tutup), diterapkan lewat KelolaFormularium. Pasangan ButirTarif untuk mesin usulan 1.8c.
 */
final class ButirFormularium
{
    private const LABEL = ['kode' => 'Kode', 'nama' => 'Nama', 'jenis' => 'Jenis', 'penjamin_id' => 'Penjamin', 'berlaku_sampai' => 'Berlaku sampai',
        'formularium_id' => 'Formularium', 'barang_id' => 'Barang', 'restriksi' => 'Restriksi', 'maks_per_resep' => 'Maks. per resep',
        'kronis' => 'Obat kronis', 'status' => 'Status'];

    public function __construct(private readonly KelolaFormularium $kelola) {}

    /**
     * @param  array<string, mixed>  $butir
     * @return list<array{kolom: string, kode: string, pesan: string}>
     */
    public function periksaBentuk(int $i, array $butir): array
    {
        $item = ['restriksi' => ['sometimes', 'nullable', 'string', 'max:1000'], 'maks_per_resep' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'kronis' => ['sometimes', 'boolean']];
        $aturan = match ($butir['objek_jenis'].'.'.($butir['aksi'] ?? '')) {
            'formularium.tambah' => ['kode' => ['required', ...IsianOrganisasi::KODE_DATA], 'nama' => ['required', 'string', 'min:2', 'max:150'],
                'jenis' => ['required', Rule::in(Formularium::JENIS)], 'penjamin_id' => ['nullable', 'uuid'], 'berlaku_sampai' => ['nullable', 'date_format:Y-m-d']],
            'formularium.ubah' => ['nama' => ['sometimes', 'string', 'min:2', 'max:150'], 'berlaku_sampai' => ['sometimes', 'nullable', 'date_format:Y-m-d']],
            'formularium_item.tambah' => ['formularium_id' => ['required', 'uuid'], 'barang_id' => ['required', 'uuid'], ...$item],
            'formularium_item.ubah' => $item,
            'formularium_item.tutup' => ['alasan' => ['required', 'string', 'min:5', 'max:500']],
            default => null,
        };
        $kolom = "perubahan.$i";
        if ($aturan === null) {
            return [['kolom' => "$kolom.aksi", 'kode' => 'aksi_tidak_dikenal', 'pesan' => 'Aksi ini tidak tersedia untuk objek itu.']];
        }
        $tambah = $butir['aksi'] === 'tambah';
        if ($tambah !== (($butir['objek_id'] ?? null) === null)) {
            return [['kolom' => "$kolom.objek_id", 'kode' => 'objek_tidak_ditemukan', 'pesan' => $tambah ? 'Kosongkan objek untuk tambah.' : 'Pilih objek yang diubah.']];
        }
        $nilai = is_array($butir['nilai_baru'] ?? null) ? $butir['nilai_baru'] : [];
        $asing = array_diff(array_keys($nilai), array_keys($aturan));
        if ($asing !== []) {
            return [['kolom' => "$kolom.nilai_baru", 'kode' => 'isian_tidak_dikenal', 'pesan' => 'Isian tidak dikenal: '.implode(', ', $asing).'.']];
        }
        if ($butir['aksi'] === 'ubah' && $nilai === []) {
            return [['kolom' => "$kolom.nilai_baru", 'kode' => 'isian_tidak_valid', 'pesan' => 'Isi minimal satu perubahan.']];
        }
        $rincian = [];
        foreach (Validator::make($nilai, $aturan)->errors()->messages() as $isian => $pesan) {
            $rincian[] = ['kolom' => "$kolom.nilai_baru.$isian", 'kode' => 'isian_tidak_valid', 'pesan' => $pesan[0]];
        }

        return $rincian;
    }

    /**
     * @param  array<string, mixed>  $butir
     * @return array{label: string, revisi: int|null, nilai_lama: array<string, mixed>|null}|null
     */
    public function potret(array $butir): ?array
    {
        $nilai = (array) ($butir['nilai_baru'] ?? []);
        if ($butir['aksi'] === 'tambah') {
            $label = $butir['objek_jenis'] === 'formularium'
                ? 'Formularium baru '.($nilai['kode'] ?? '').' · '.($nilai['nama'] ?? '')
                : 'Item baru '.(Barang::query()->whereKey($nilai['barang_id'])->value('nama') ?? '?').' · '.(Formularium::query()->whereKey($nilai['formularium_id'])->value('nama') ?? '?');

            return ['label' => $label, 'revisi' => null, 'nilai_lama' => null];
        }
        $objek = $this->objek((string) $butir['objek_jenis'], (string) $butir['objek_id']);
        if ($objek === null) {
            return null;
        }
        $label = $objek instanceof Formularium ? $objek->kode.' · '.$objek->nama
            : ($objek->barang->nama ?? '?').' · '.(Formularium::query()->whereKey($objek->formularium_id)->value('nama') ?? '?');
        $isi = $objek->attributesToArray();

        return ['label' => $label, 'revisi' => $objek->revisi,
            'nilai_lama' => $butir['aksi'] === 'tutup' ? ['status' => $isi['status']] : array_intersect_key($isi, $nilai)];
    }

    /** @param  array<string, mixed>  $butir */
    public function terapkan(Pengguna $penyunting, array $butir, string $berlakuDari, string $dasar): string
    {
        $nilai = [...(array) ($butir['nilai_baru'] ?? []), 'dasar_perubahan' => $dasar];
        $versi = (string) ($butir['revisi_objek'] ?? '');
        $objek = $butir['aksi'] === 'tambah' ? null : ($this->objek((string) $butir['objek_jenis'], (string) $butir['objek_id'])
            ?? throw new ApiError(404, 'objek_tidak_ditemukan', 'Objek tidak ditemukan lagi.'));
        try {
            return match ($butir['objek_jenis'].'.'.$butir['aksi']) {
                'formularium.tambah' => $this->kelola->buat($penyunting, [...$nilai, 'berlaku_dari' => $berlakuDari])->id,
                'formularium.ubah' => $this->kelola->ubah($penyunting, $this->formularium($objek), $versi, $nilai)->id,
                'formularium_item.tambah' => $this->kelola->tambahItem($penyunting,
                    Formularium::query()->whereKey($nilai['formularium_id'])->firstOr(fn () => throw ValidationException::withMessages(['formularium_id' => 'Formularium tidak ditemukan.'])),
                    $nilai)->id,
                'formularium_item.ubah' => $this->kelola->ubahItem($penyunting, $this->item($objek), $versi, $nilai)->id,
                'formularium_item.tutup' => $this->kelola->ubahItem($penyunting, $this->item($objek), $versi, ['status' => 'nonaktif', 'dasar_perubahan' => $dasar])->id,
                default => throw new \LogicException('Butir tidak dikenal.'),
            };
        } catch (ValidationException $e) {
            // Aturan 2.11 (tumpang tindih, barang nonaktif/ganda, penjamin) → galat per butir mesin usulan.
            $isian = (string) array_key_first($e->errors());

            throw new ApiError(422, 'isian_tidak_valid', $e->errors()[$isian][0], [['kolom' => "nilai_baru.$isian"]]);
        }
    }

    public function objek(string $jenis, string $id): Formularium|ItemFormularium|null
    {
        return $jenis === 'formularium' ? Formularium::query()->find($id) : ItemFormularium::query()->with('barang')->find($id);
    }

    /**
     * @param  array<string, mixed>  $butir
     * @return list<array{isian: string, label_isian: string, lama: string|null, baru: string|null}>
     */
    public function tampilan(array $butir, string $berlakuDari): array
    {
        if ($butir['aksi'] === 'tutup') {
            return [['isian' => 'status', 'label_isian' => 'Status', 'lama' => 'Aktif', 'baru' => 'Nonaktif mulai '.Carbon::parse($berlakuDari)->format('d-m-Y')]];
        }
        $lama = (array) ($butir['nilai_lama'] ?? []);
        $baris = [];
        foreach ((array) $butir['nilai_baru'] as $isian => $baru) {
            $baris[] = ['isian' => (string) $isian, 'label_isian' => self::LABEL[$isian] ?? (string) $isian,
                'lama' => $butir['aksi'] === 'tambah' ? null : $this->teks((string) $isian, $lama[$isian] ?? null),
                'baru' => $this->teks((string) $isian, $baru) ?? '—'];
        }

        return $baris;
    }

    private function teks(string $isian, mixed $nilai): ?string
    {
        return match (true) {
            $nilai === null => null,
            is_bool($nilai) => $nilai ? 'Ya' : 'Tidak',
            $isian === 'barang_id' => Barang::query()->whereKey($nilai)->value('nama') ?? (string) $nilai,
            $isian === 'formularium_id' => Formularium::query()->whereKey($nilai)->value('nama') ?? (string) $nilai,
            $isian === 'penjamin_id' => DB::table('inti.penjamin')->where('id', $nilai)->value('nama') ?? (string) $nilai,
            $isian === 'status' => ['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif'][$nilai] ?? (string) $nilai,
            default => (string) $nilai,
        };
    }

    private function formularium(mixed $objek): Formularium
    {
        return $objek instanceof Formularium ? $objek : throw new \LogicException;
    }

    private function item(mixed $objek): ItemFormularium
    {
        return $objek instanceof ItemFormularium ? $objek : throw new \LogicException;
    }
}
