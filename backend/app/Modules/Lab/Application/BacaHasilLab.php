<?php

namespace App\Modules\Lab\Application;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\RawatInap\Application\NamaPelaku;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Bentuk kontrak `HasilLab` (tiket 2.31). Data pasien = penanda identitas yang sama dengan daftar kerja lab 2.30 (nama,
 * No. RM, tanggal lahir). `dapat` per aksi dari izin cakupan RS; otorisasi juga mensyaratkan akun bukan validatornya.
 */
final class BacaHasilLab
{
    public const IZIN_SAHKAN = 'penunjang.laboratorium.sahkan';

    public const IZIN_SETUJUI = 'penunjang.laboratorium.setujui';

    public function __construct(private readonly NamaPelaku $pelaku) {}

    /** @return array<string, mixed> */
    public function satu(string $hasilId, Pengguna $pengguna): array
    {
        return $this->untuk([$hasilId], $pengguna)[$hasilId];
    }

    /**
     * @param  list<string>  $hasilId
     * @return array<string, array<string, mixed>> id hasil => HasilLab, urutan sesuai $hasilId
     */
    public function untuk(array $hasilId, Pengguna $pengguna): array
    {
        if ($hasilId === []) {
            return [];
        }
        $baris = DB::table('lab.hasil AS h')->join('permintaan.permintaan_item AS i', 'i.id', '=', 'h.permintaan_item_id')
            ->join('permintaan.permintaan_pemeriksaan AS p', 'p.id', '=', 'i.permintaan_pemeriksaan_id')
            ->join('lab.pemeriksaan AS m', 'm.id', '=', 'h.pemeriksaan_id')
            ->join('pasien.pasien AS ps', 'ps.id', '=', 'p.pasien_id')
            ->whereIn('h.id', $hasilId)
            ->get(['h.*', 'i.permintaan_pemeriksaan_id', 'p.episode_id', 'i.kode_item', 'i.nama AS nama_item', 'm.jenis_hasil', 'm.butuh_otorisasi_spk',
                'ps.id AS ps_id', 'ps.no_rm', 'ps.nama AS ps_nama', 'ps.tanggal_lahir'])->keyBy('id');
        $adendum = DB::table('lab.hasil_adendum')->whereIn('hasil_id', $hasilId)->orderBy('dibuat_pada')->orderBy('id')->get()->groupBy('hasil_id');
        $pelaku = $this->pelaku->untuk($baris->pluck('diinput_oleh')->merge($baris->pluck('divalidasi_oleh'))->merge($baris->pluck('diotorisasi_oleh'))
            ->merge($adendum->flatten(1)->pluck('dibuat_oleh'))->filter()->map(fn ($v) => (string) $v)->unique()->values());
        $gate = Gate::forUser($pengguna);
        $boleh = [
            'input' => $gate->allows(BacaDaftarKerjaLab::IZIN_AMBIL, Cakupan::rs()),
            'sahkan' => $gate->allows(self::IZIN_SAHKAN, Cakupan::rs()),
            'setujui' => $gate->allows(self::IZIN_SETUJUI, Cakupan::rs()),
        ];

        $hasil = [];
        foreach ($hasilId as $id) {
            $h = $baris[$id];
            $hasil[$id] = $this->bentuk($h, $adendum->get($id, collect()), $pelaku, $boleh, $pengguna);
        }

        return $hasil;
    }

    /**
     * Ringkasan hasil per butir order untuk daftar kerja lab (butir tanpa hasil tidak ada di peta).
     *
     * @param  list<string>  $itemId
     * @return array<string, array<string, mixed>> id butir => HasilLab
     */
    public function perItem(array $itemId, Pengguna $pengguna): array
    {
        $peta = DB::table('lab.hasil')->whereIn('permintaan_item_id', $itemId)->pluck('id', 'permintaan_item_id')->map(fn ($v) => (string) $v);
        $hasil = $this->untuk($peta->values()->all(), $pengguna);

        return $peta->map(fn (string $id) => $hasil[$id])->all();
    }

    /**
     * @param  Collection<int, \stdClass>  $adendum
     * @param  array<string, array{id: string, nama: string}|null>  $pelaku
     * @param  array{input: bool, sahkan: bool, setujui: bool}  $boleh
     * @return array<string, mixed>
     */
    private function bentuk(object $h, Collection $adendum, array $pelaku, array $boleh, Pengguna $pengguna): array
    {
        $waktu = fn ($v) => $v === null ? null : Carbon::parse((string) $v)->toIso8601String();
        $orang = fn ($v) => $v === null ? null : ($pelaku[(string) $v] ?? null);
        $status = (string) $h->status;

        return [
            'id' => (string) $h->id, 'permintaan_item_id' => (string) $h->permintaan_item_id,
            'permintaan_pemeriksaan_id' => (string) $h->permintaan_pemeriksaan_id, 'episode_id' => (string) $h->episode_id,
            'kode_item' => (string) $h->kode_item, 'nama' => (string) $h->nama_item, 'status' => $status, 'jenis_hasil' => (string) $h->jenis_hasil,
            ...$this->isi($h),
            'catatan' => $h->catatan === null ? null : (string) $h->catatan,
            'butuh_otorisasi_spk' => (bool) $h->butuh_otorisasi_spk,
            'pasien' => ['id' => (string) $h->ps_id, 'no_rm' => (string) $h->no_rm, 'nama' => (string) $h->ps_nama,
                'tanggal_lahir' => $h->tanggal_lahir === null ? null : substr((string) $h->tanggal_lahir, 0, 10)],
            'diinput_oleh' => $orang($h->diinput_oleh), 'diinput_pada' => (string) $waktu($h->diinput_pada),
            'divalidasi_oleh' => $orang($h->divalidasi_oleh), 'divalidasi_pada' => $waktu($h->divalidasi_pada),
            'diotorisasi_oleh' => $orang($h->diotorisasi_oleh), 'diotorisasi_pada' => $waktu($h->diotorisasi_pada),
            'dikeluarkan_pada' => $waktu($h->dikeluarkan_pada),
            'adendum' => $adendum->map(fn ($a) => [
                'id' => (string) $a->id, 'hasil_id' => (string) $a->hasil_id, ...$this->isi($a),
                'alasan' => (string) $a->alasan, 'dibuat_oleh' => $orang($a->dibuat_oleh), 'dibuat_pada' => (string) $waktu($a->dibuat_pada),
            ])->values()->all(),
            'dapat' => [
                'input' => $status === 'menunggu_validasi' && $boleh['input'],
                'validasi' => ($status === 'menunggu_validasi' && $boleh['sahkan'])
                    || ($status === 'menunggu_otorisasi' && $boleh['setujui'] && (string) $h->divalidasi_oleh !== $pengguna->id),
                'adendum' => $status === 'divalidasi' && ($h->butuh_otorisasi_spk ? $boleh['setujui'] : $boleh['sahkan']),
            ],
        ];
    }

    /** Nilai, satuan, batas, penanda, dan peringatan (sama untuk hasil dan adendum). @return array<string, mixed> */
    private function isi(object $h): array
    {
        $angka = fn ($v) => $v === null ? null : (float) $v;
        $penanda = $h->penanda === null ? null : (string) $h->penanda;

        return [
            'nilai' => $angka($h->nilai), 'nilai_teks' => $h->nilai_teks === null ? null : (string) $h->nilai_teks,
            'satuan' => $h->satuan === null ? null : (string) $h->satuan,
            ...(property_exists($h, 'kode_loinc') ? ['kode_loinc' => $h->kode_loinc === null ? null : (string) $h->kode_loinc] : []),
            'rujukan_min' => $angka($h->rujukan_min), 'rujukan_maks' => $angka($h->rujukan_maks),
            'kritis_min' => $angka($h->kritis_min), 'kritis_maks' => $angka($h->kritis_maks),
            'penanda' => $penanda, 'kritis' => HitungPenanda::kritis($penanda),
            'peringatan' => $h->peringatan === null ? null : (string) $h->peringatan,
        ];
    }
}
