<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Http\Paginasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\MasterData\Application\BukuTarif;
use App\Modules\MasterData\Application\SuntinganBerversi;
use App\Modules\MasterData\Http\Resources\UsulanPresenter;
use App\Modules\MasterData\Models\Tarif;
use App\Modules\MasterData\Models\TarifVersi;
use App\Modules\MasterData\Models\UsulanDataInduk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/data-induk/tarif dan /{id}/versi (kontrak v0.18.0, tiket 2.2). Hanya baca: izin master_data.tarif.lihat cakupan `rs`.
 * Perubahan tarif lewat usulan 1.8c. Butir yang belum punya versi berlaku pada `tanggal` tidak ikut daftar.
 */
final class TarifController
{
    public function __construct(private readonly BukuTarif $buku) {}

    public function index(Request $request): JsonResponse
    {
        $this->izin();
        $f = $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['aktif', 'nonaktif'])],
            'kelompok' => ['nullable', 'string', 'max:50'],
            'kelas_rawat_id' => ['nullable', 'uuid'],
            'tanggal' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $tanggal = $f['tanggal'] ?? SuntinganBerversi::hariIni();
        $berlaku = BukuTarif::SQL_VERSI_BERLAKU;
        $cari = isset($f['cari']) ? '%'.addcslashes($f['cari'], '\\%_').'%' : null;

        $query = Tarif::query()->whereRaw("$berlaku is not null", [$tanggal])
            ->when($f['status'] ?? null, fn ($q, $s) => $q->whereRaw("(select status from keuangan.tarif_versi where id = $berlaku) = ?", [$tanggal, $s]))
            ->when($f['kelompok'] ?? null, fn ($q, $k) => $q->whereRaw("(select kelompok from keuangan.tarif_versi where id = $berlaku) = ?", [$tanggal, $k]))
            ->when($f['kelas_rawat_id'] ?? null, fn ($q, $k) => $q->whereExists(fn ($e) => $e->selectRaw('1')->from('keuangan.tarif_versi_harga as h')
                ->whereRaw("h.tarif_versi_id = $berlaku", [$tanggal])->where('h.kelas_rawat_id', $k)))
            ->when($cari, fn ($q) => $q->where(fn ($w) => $w->where('kode', 'ilike', $cari)
                ->orWhereRaw("(select nama from keuangan.tarif_versi where id = $berlaku) ilike ?", [$tanggal, $cari])));

        return Paginasi::respons($query, $request, 'kode', arah: 'asc',
            bentuk: fn (Collection $baris) => $this->bentuk($baris, $tanggal, $f['kelas_rawat_id'] ?? null));
    }

    public function versi(Request $request, UsulanPresenter $presenter, Tarif $tarif): JsonResponse
    {
        $this->izin();

        return Paginasi::respons(TarifVersi::query()->where('tarif_id', $tarif->id), $request, 'dibuat_pada',
            bentuk: fn (Collection $baris) => $presenter->versiTarif($baris, $this->buku));
    }

    /**
     * @param  Collection<int, Tarif>  $baris
     * @return list<array<string, mixed>>
     */
    private function bentuk(Collection $baris, string $tanggal, ?string $kelas): array
    {
        if ($baris->isEmpty()) {
            return [];
        }
        $id = $baris->pluck('id')->all();
        $berlaku = TarifVersi::query()->whereIn('tarif_id', $id)->where('berlaku_dari', '<=', $tanggal)
            ->orderByDesc('berlaku_dari')->orderByDesc('versi')->get()->unique('tarif_id')->keyBy('tarif_id');
        $harga = $this->buku->harga($berlaku->pluck('id')->values()->all());
        $saring = fn (array $h) => $kelas === null ? $h : array_values(array_filter($h, fn (array $r) => $r['kelas_rawat_id'] === $kelas));

        $lihatUsulan = Gate::allows('master_data.usulan.lihat', Cakupan::rs());
        $usulan = UsulanDataInduk::query()->where('jenis_data', 'tarif')->whereIn('tahap', UsulanDataInduk::BERJALAN)->get(['id', 'nomor', 'tahap', 'berlaku_dari', 'perubahan']);
        $perTarif = [];
        foreach ($usulan as $u) {
            foreach ($u->perubahan as $b) {
                if (($b['objek_id'] ?? null) !== null) {
                    $perTarif[$b['objek_id']][] = [$u, $b];
                }
            }
        }

        $hasil = [];
        foreach ($baris as $t) {
            $v = $berlaku[$t->id];
            $terbuka = $perTarif[$t->id] ?? [];
            $hasil[] = [
                'id' => $t->id, 'kode' => $t->kode, 'nama' => $v->nama, 'kelompok' => $v->kelompok, 'status' => $v->status,
                'berlaku_dari' => $v->berlaku_dari->toDateString(), 'harga' => $saring(($harga[$v->id] ?? [])),
                'akan_berlaku' => $this->akanBerlaku($terbuka, $tanggal, ($harga[$v->id] ?? []), $saring),
                'usulan_terbuka' => $lihatUsulan && $terbuka !== [] ? ['id' => $terbuka[0][0]->id, 'nomor' => $terbuka[0][0]->nomor] : null,
                'versi' => (string) $t->revisi, 'diubah_pada' => $t->diubah_pada->toIso8601String(),
            ];
        }

        return $hasil;
    }

    /**
     * Versi terjadwal terdekat: usulan yang sudah disahkan tetapi belum berlaku (diterapkan pukul 00.00 tanggalnya).
     *
     * @param  list<array{0: UsulanDataInduk, 1: array<string, mixed>}>  $terbuka
     * @param  list<array<string, string>>  $hargaSekarang
     * @return array{berlaku_dari: string, harga: list<array<string, string>>}|null
     */
    private function akanBerlaku(array $terbuka, string $tanggal, array $hargaSekarang, callable $saring): ?array
    {
        $disetujui = array_values(array_filter($terbuka, fn (array $x) => $x[0]->tahap === 'disetujui' && $x[0]->berlaku_dari->toDateString() >= $tanggal));
        if ($disetujui === []) {
            return null;
        }
        [$u, $b] = $disetujui[0];
        $nilai = (array) ($b['nilai_baru'] ?? []);
        $baru = isset($nilai['harga']) ? $this->buku->hargaDariIsian($nilai['harga']) : $hargaSekarang;

        return ['berlaku_dari' => $u->berlaku_dari->toDateString(), 'harga' => $saring($baru)];
    }

    private function izin(): void
    {
        abort_unless(Gate::allows('master_data.tarif.lihat', Cakupan::rs()), 403);
    }
}
