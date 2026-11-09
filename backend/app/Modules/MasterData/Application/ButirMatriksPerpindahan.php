<?php

namespace App\Modules\MasterData\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Http\SuntinganBerversi;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\AturanPerpindahan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Butir usulan jenis data `matriks_perpindahan` (tiket 2.26, kontrak v0.33.0; FR-00-02): objek `aturan_perpindahan`
 * (tambah/ubah/tutup). Matriks hanya berubah lewat usulan berjenjang; tidak ada endpoint tulis langsung. Penerapan
 * menaikkan revisi (CAS), mencatat versi (inti.data_induk_versi) dan audit di transaksi penerapan usulan.
 */
final class ButirMatriksPerpindahan
{
    public const OBJEK = 'aturan_perpindahan';

    public const LABEL_POS = [
        'pendaftaran' => 'Pendaftaran', 'poliklinik' => 'Poliklinik', 'igd' => 'IGD', 'penunjang' => 'Penunjang', 'kamar_operasi' => 'Kamar operasi',
        'icu_hcu' => 'ICU/HCU', 'rawat_inap' => 'Rawat inap', 'farmasi' => 'Farmasi', 'kasir' => 'Kasir', 'poli_lain' => 'Poli lain',
        'rujuk_keluar' => 'Rujuk keluar', 'pulang' => 'Pulang', 'meninggal' => 'Meninggal', 'aps' => 'APS (pulang atas permintaan sendiri)', 'doa' => 'DOA',
    ];

    private const LABEL = ['pos_asal' => 'Pos asal', 'pos_tujuan' => 'Pos tujuan', 'izin_perintah' => 'Izin pemberi perintah',
        'keterangan' => 'Keterangan', 'status' => 'Status'];

    public function __construct(private readonly CatatAktivitas $audit, private readonly CatatVersi $versi) {}

    /**
     * @param  array<string, mixed>  $butir
     * @return list<array{kolom: string, kode: string, pesan: string}>
     */
    public function periksaBentuk(int $i, array $butir): array
    {
        $izin = ['string', 'max:100'];
        $aturan = match ($butir['aksi'] ?? '') {
            'tambah' => ['pos_asal' => ['required', Rule::in(AturanPerpindahan::POS_ASAL)], 'pos_tujuan' => ['required', Rule::in(AturanPerpindahan::POS_TUJUAN)],
                'izin_perintah' => ['required', ...$izin], 'keterangan' => ['nullable', 'string', 'max:500']],
            'ubah' => ['izin_perintah' => ['sometimes', ...$izin], 'keterangan' => ['sometimes', 'nullable', 'string', 'max:500'],
                'status' => ['sometimes', Rule::in(['aktif', 'nonaktif'])]],
            'tutup' => ['alasan' => ['required', 'string', 'min:5', 'max:500']],
            default => null,
        };
        $kolom = "perubahan.$i";
        if ($aturan === null) {
            return [['kolom' => "$kolom.aksi", 'kode' => 'aksi_tidak_dikenal', 'pesan' => 'Aksi ini tidak tersedia untuk objek itu.']];
        }
        $tambah = $butir['aksi'] === 'tambah';
        if ($tambah !== (($butir['objek_id'] ?? null) === null)) {
            return [['kolom' => "$kolom.objek_id", 'kode' => 'objek_tidak_ditemukan', 'pesan' => $tambah ? 'Kosongkan objek untuk tambah.' : 'Pilih sel matriks yang diubah.']];
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
            return ['label' => 'Sel baru '.$this->pasangan((string) ($nilai['pos_asal'] ?? ''), (string) ($nilai['pos_tujuan'] ?? '')), 'revisi' => null, 'nilai_lama' => null];
        }
        $objek = $this->objek(self::OBJEK, (string) $butir['objek_id']);
        if ($objek === null) {
            return null;
        }
        $isi = $this->isi($objek);

        return ['label' => $this->pasangan($objek->pos_asal, $objek->pos_tujuan), 'revisi' => $objek->revisi,
            'nilai_lama' => $butir['aksi'] === 'tutup' ? ['status' => $isi['status']] : array_intersect_key($isi, $nilai)];
    }

    /** @param  array<string, mixed>  $butir */
    public function terapkan(Pengguna $penyunting, array $butir, string $berlakuDari, string $dasar): string
    {
        $nilai = (array) ($butir['nilai_baru'] ?? []);
        if (isset($nilai['izin_perintah']) && ! DB::table('akses.izin')->where('kode', $nilai['izin_perintah'])->exists()) {
            throw new ApiError(422, 'isian_tidak_valid', 'Kode izin tidak dikenal.', [['kolom' => 'nilai_baru.izin_perintah']]);
        }
        try {
            return DB::transaction(fn () => $butir['aksi'] === 'tambah'
                ? $this->tambah($penyunting, $nilai, $berlakuDari, $dasar)
                : $this->ubah($penyunting, $butir, $butir['aksi'] === 'tutup' ? ['status' => 'nonaktif'] : $nilai, $berlakuDari, $dasar));
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }

            throw new ApiError(409, 'pasangan_sudah_ada', 'Sel matriks untuk pasangan pos ini sudah ada. Ubah sel yang ada.', [['kolom' => 'nilai_baru.pos_tujuan']]);
        }
    }

    public function objek(string $jenis, string $id): ?AturanPerpindahan
    {
        return AturanPerpindahan::query()->find($id);
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

    /** @param  array<string, mixed>  $nilai */
    private function tambah(Pengguna $penyunting, array $nilai, string $berlakuDari, string $dasar): string
    {
        if (AturanPerpindahan::query()->where('pos_asal', $nilai['pos_asal'])->where('pos_tujuan', $nilai['pos_tujuan'])->exists()) {
            throw new ApiError(409, 'pasangan_sudah_ada', 'Sel matriks untuk pasangan pos ini sudah ada. Ubah sel yang ada.', [['kolom' => 'nilai_baru.pos_tujuan']]);
        }
        $aturan = new AturanPerpindahan(array_intersect_key($nilai, array_flip(['pos_asal', 'pos_tujuan', 'izin_perintah', 'keterangan'])));
        $aturan->forceFill(['dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id])->save();
        $aturan->refresh();
        $this->versi->catat(self::OBJEK, $aturan->id, null, $this->isi($aturan), $berlakuDari, $dasar, $penyunting->id);
        $this->audit->catat('master_data.matriks_perpindahan_ditambah', $penyunting->id, $aturan->getTable(), $aturan->id,
            [...$this->isi($aturan), 'dasar_perubahan' => $dasar]);

        return $aturan->id;
    }

    /**
     * @param  array<string, mixed>  $butir
     * @param  array<string, mixed>  $nilai
     */
    private function ubah(Pengguna $penyunting, array $butir, array $nilai, string $berlakuDari, string $dasar): string
    {
        $id = (string) $butir['objek_id'];
        $aturan = AturanPerpindahan::query()->find($id) ?? throw new ApiError(404, 'objek_tidak_ditemukan', 'Sel matriks tidak ditemukan lagi.');
        SuntinganBerversi::naikkan(AturanPerpindahan::class, $id, (string) ($butir['revisi_objek'] ?? ''), $penyunting->id, 'Sel matriks');
        $sebelum = $this->isi($aturan);
        $aturan->refresh();
        $aturan->fill(array_intersect_key($nilai, array_flip(['izin_perintah', 'keterangan', 'status'])));
        $aturan->forceFill(['diubah_oleh' => $penyunting->id])->save();
        $sesudah = $this->isi($aturan->refresh());
        $this->versi->catat(self::OBJEK, $aturan->id, $sebelum, $sesudah, $berlakuDari, $dasar, $penyunting->id);
        $this->audit->catat('master_data.matriks_perpindahan_diubah', $penyunting->id, $aturan->getTable(), $aturan->id,
            [...$sesudah, 'dasar_perubahan' => $dasar], $sebelum);

        return $aturan->id;
    }

    /** @return array<string, mixed> */
    private function isi(AturanPerpindahan $a): array
    {
        return ['pos_asal' => $a->pos_asal, 'pos_tujuan' => $a->pos_tujuan, 'izin_perintah' => $a->izin_perintah, 'keterangan' => $a->keterangan, 'status' => $a->status];
    }

    private function pasangan(string $asal, string $tujuan): string
    {
        return (self::LABEL_POS[$asal] ?? $asal).' → '.(self::LABEL_POS[$tujuan] ?? $tujuan);
    }

    private function teks(string $isian, mixed $nilai): ?string
    {
        return match (true) {
            $nilai === null => null,
            in_array($isian, ['pos_asal', 'pos_tujuan'], true) => self::LABEL_POS[(string) $nilai] ?? (string) $nilai,
            $isian === 'status' => ['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif'][$nilai] ?? (string) $nilai,
            default => (string) $nilai,
        };
    }
}
