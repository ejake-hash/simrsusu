<?php

namespace App\Modules\MasterData\Http\Resources;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Application\BukuTarif;
use App\Modules\MasterData\Application\ButirRuangTt;
use App\Modules\MasterData\Application\ButirTarif;
use App\Modules\MasterData\Application\MesinUsulan;
use App\Modules\MasterData\Models\TarifVersi;
use App\Modules\MasterData\Models\UsulanDataInduk;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Bentuk UsulanDataInduk dan VersiDataInduk (kontrak v0.9.0, tiket 1.8c). Nama pelaku dan riwayat dimuat per halaman. */
final class UsulanPresenter
{
    private const BATAS_RIWAYAT = 200;

    public function __construct(
        private readonly MesinUsulan $mesin,
        private readonly ButirRuangTt $butir,
        private readonly ButirTarif $butirTarif,
        private readonly RingkasanPegawai $pegawai,
    ) {}

    /** @return array<string, mixed> */
    public function satu(UsulanDataInduk $u, Pengguna $penonton): array
    {
        return $this->daftar(collect([$u]), $penonton)[0];
    }

    /**
     * @param  Collection<int, UsulanDataInduk>  $baris
     * @return list<array<string, mixed>>
     */
    public function daftar(Collection $baris, Pengguna $penonton): array
    {
        if ($baris->isEmpty()) {
            return [];
        }
        $kejadian = DB::table('inti.usulan_data_induk_kejadian')->whereIn('usulan_id', $baris->pluck('id'))
            ->orderBy('pada')->orderBy('id')->get()->groupBy('usulan_id');
        $idAkun = $baris->flatMap(fn (UsulanDataInduk $u) => [$u->diusulkan_oleh, $u->diverifikasi_oleh, $u->disetujui_oleh])
            ->merge($kejadian->flatten(1)->pluck('oleh'))->filter()->unique()->values();
        $peta = $this->pelaku($idAkun->all());
        $pelaku = fn (mixed $id): ?array => is_string($id) ? ($peta[$id] ?? null) : null;

        $hasil = [];
        foreach ($baris as $u) {
            $riwayat = [];
            foreach (($kejadian->get($u->id) ?? collect())->take(-self::BATAS_RIWAYAT) as $k) {
                $riwayat[] = ['aksi' => (string) $k->aksi, 'oleh' => $pelaku($k->oleh), 'pada' => Carbon::parse((string) $k->pada)->toIso8601String(),
                    'catatan' => $k->catatan, 'putaran' => (int) $k->putaran];
            }
            $perubahan = [];
            foreach ($u->perubahan as $b) {
                $perubahan[] = $this->butirTampil($u, $b);
            }
            $hasil[] = [
                'id' => $u->id, 'nomor' => $u->nomor, 'jenis_data' => $u->jenis_data, 'ringkasan' => $u->ringkasan, 'perubahan' => $perubahan,
                'berlaku_dari' => $u->berlaku_dari->toDateString(), 'alasan' => $u->alasan, 'dasar' => $u->dasar, 'tahap' => $u->tahap,
                'putaran' => $u->putaran, 'diusulkan_oleh' => $pelaku($u->diusulkan_oleh), 'diusulkan_pada' => $u->diusulkan_pada->toIso8601String(),
                'diverifikasi_oleh' => $pelaku($u->diverifikasi_oleh), 'disetujui_oleh' => $pelaku($u->disetujui_oleh),
                'gagal_alasan' => $u->gagal_alasan, 'riwayat' => $riwayat, 'dapat' => $this->mesin->dapat($penonton, $u),
            ];
        }

        return $hasil;
    }

    /**
     * @param  Collection<int, object>  $baris  baris inti.data_induk_versi
     * @return list<array<string, mixed>>
     */
    public function versi(Collection $baris): array
    {
        $usulan = UsulanDataInduk::query()->whereIn('id', $baris->pluck('usulan_id')->filter()->unique()->values())->pluck('nomor', 'id');
        $peta = $this->pelaku($baris->pluck('dibuat_oleh')->filter()->unique()->values()->all());

        $hasil = [];
        foreach ($baris as $v) {
            $sebelum = $v->sebelum === null ? null : (array) json_decode((string) $v->sebelum, true);
            $sesudah = (array) json_decode((string) $v->sesudah, true);
            $hasil[] = [
                'id' => $v->id, 'versi' => (int) $v->versi, 'objek_jenis' => $v->objek_jenis, 'objek_id' => $v->objek_id,
                'berlaku_dari' => $v->berlaku_dari, 'sebelum' => $sebelum, 'sesudah' => $sesudah,
                'tampilan' => $this->butir->tampilanVersi($sebelum, $sesudah),
                'usulan' => $v->usulan_id === null ? null : ['id' => $v->usulan_id, 'nomor' => (string) ($usulan[(string) $v->usulan_id] ?? '')],
                'dasar' => $v->dasar,
                'diubah_oleh' => is_string($v->dibuat_oleh) ? ($peta[$v->dibuat_oleh] ?? null) : null,
                'diubah_pada' => Carbon::parse((string) $v->dibuat_pada)->toIso8601String(),
            ];
        }

        return $hasil;
    }

    /**
     * Versi buku tarif (bentuk VersiDataInduk, objek_jenis `tarif`): `sebelum` = isi versi sebelumnya.
     *
     * @param  Collection<int, TarifVersi>  $baris
     * @return list<array<string, mixed>>
     */
    public function versiTarif(Collection $baris, BukuTarif $buku): array
    {
        $usulan = UsulanDataInduk::query()->whereIn('id', $baris->pluck('usulan_id')->filter()->unique()->values())->pluck('nomor', 'id');
        $peta = $this->pelaku($baris->pluck('dibuat_oleh')->filter()->unique()->values()->all());
        $sebelumnya = TarifVersi::query()->whereIn('tarif_id', $baris->pluck('tarif_id')->unique()->values())
            ->whereIn('versi', $baris->pluck('versi')->map(fn (int $v) => $v - 1)->unique()->values())->get()
            ->keyBy(fn (TarifVersi $v) => $v->tarif_id.'#'.$v->versi);

        $hasil = [];
        foreach ($baris as $v) {
            $lama = $sebelumnya->get($v->tarif_id.'#'.($v->versi - 1));
            $sebelum = $lama === null ? null : $buku->isi($lama);
            $sesudah = $buku->isi($v);
            $hasil[] = [
                'id' => $v->id, 'versi' => $v->versi, 'objek_jenis' => 'tarif', 'objek_id' => $v->tarif_id,
                'berlaku_dari' => $v->berlaku_dari->toDateString(), 'sebelum' => $sebelum, 'sesudah' => $sesudah,
                'tampilan' => $this->butirTarif->tampilanVersi($sebelum, $sesudah),
                'usulan' => $v->usulan_id === null ? null : ['id' => $v->usulan_id, 'nomor' => (string) ($usulan[$v->usulan_id] ?? '')],
                'dasar' => $v->dasar, 'diubah_oleh' => $v->dibuat_oleh === null ? null : ($peta[$v->dibuat_oleh] ?? null),
                'diubah_pada' => $v->dibuat_pada->toIso8601String(),
            ];
        }

        return $hasil;
    }

    /**
     * @param  list<string>  $id
     * @return array<string, array{id: string, nama: string}>
     */
    private function pelaku(array $id): array
    {
        $akun = Pengguna::query()->whereIn('id', $id)->get(['id', 'pegawai_id', 'nama_pengguna']);
        $nama = $this->pegawai->nama($akun->pluck('pegawai_id')->filter()->values()->all());
        $peta = [];
        foreach ($akun as $a) {
            $peta[$a->id] = ['id' => $a->id, 'nama' => $nama[$a->pegawai_id] ?? $a->nama_pengguna];
        }

        return $peta;
    }

    /**
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function butirTampil(UsulanDataInduk $u, array $b): array
    {
        $berubah = false;
        if ($b['aksi'] !== 'tambah' && in_array($u->tahap, [...UsulanDataInduk::BERJALAN, 'dikembalikan'], true)) {
            $objek = $b['objek_jenis'] === 'tarif' ? $this->butirTarif->objek((string) $b['objek_id'])
                : $this->butir->objek($b['objek_jenis'], (string) $b['objek_id']);
            $berubah = $objek === null || (int) $objek->getAttribute('revisi') !== (int) ($b['revisi_objek'] ?? -1);
        }

        return [
            'objek_jenis' => $b['objek_jenis'], 'aksi' => $b['aksi'], 'objek_id' => $b['objek_id'] ?? $b['objek_id_hasil'] ?? null,
            'objek_label' => $b['objek_label'] ?? '', 'objek_berubah' => $berubah, 'nilai_lama' => $b['nilai_lama'] ?? null,
            'nilai_baru' => (object) ($b['nilai_baru'] ?? []), 'tampilan' => ($b['objek_jenis'] === 'tarif' ? $this->butirTarif : $this->butir)->tampilan($b, $u->berlaku_dari->toDateString()),
        ];
    }
}
