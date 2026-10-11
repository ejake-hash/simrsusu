<?php

namespace App\Modules\Notifikasi\Http\Resources;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\Notifikasi\Models\JenisTugas;
use App\Modules\Notifikasi\Models\Notifikasi;
use App\Modules\Notifikasi\Models\Tugas;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Bentuk kontrak v0.7.0 (tiket 1.7): Notifikasi, Tugas, TugasRinci, JenisTugas. Data terkait dimuat per halaman. */
final class NotifikasiResource
{
    public function __construct(private readonly RingkasanPegawai $pegawai) {}

    /** @return array<string, mixed> */
    public static function notifikasi(Notifikasi|\stdClass $n): array
    {
        // Mikrodetik dipertahankan: klien mengirim balik dibuat_pada terbaru sebagai `sampai` di semua-dibaca.
        $waktu = fn ($w) => $w === null ? null : Carbon::parse($w)->format('Y-m-d\\TH:i:s.uP');

        return [
            'id' => $n->id, 'jenis' => $n->jenis, 'judul' => $n->judul, 'isi' => $n->isi, 'tautan' => $n->tautan,
            'prioritas' => $n->prioritas, 'tugas_id' => $n->tugas_id, 'dibaca_pada' => $waktu($n->dibaca_pada),
            'dibuat_pada' => $waktu($n->dibuat_pada),
        ];
    }

    /**
     * @param  Collection<int, Tugas>  $baris
     * @return list<array<string, mixed>>
     */
    public function tugas(Collection $baris): array
    {
        $jenis = JenisTugas::query()->whereIn('id', $baris->pluck('jenis_tugas_id')->unique())->get(['id', 'kode', 'nama'])->keyBy('id');
        $nama = $this->namaPelaksana($baris);
        $sekarang = now();

        return $baris->map(function (Tugas $t) use ($jenis, $nama, $sekarang) {
            $j = $jenis->get($t->jenis_tugas_id);

            return [
                'id' => $t->id,
                'jenis' => ['kode' => (string) $j?->kode, 'nama' => (string) $j?->nama],
                'judul' => $t->judul,
                'objek' => ['jenis' => $t->objek_jenis, 'id' => $t->objek_id, 'label' => $t->judul],
                'tautan' => $t->tautan,
                'pelaksana' => ['jenis' => $t->pelaksana_jenis, 'id' => $t->pelaksana_id, 'nama' => $nama[$t->pelaksana_jenis.':'.$t->pelaksana_id] ?? ''],
                'tenggat_pada' => $t->tenggat_pada->toIso8601String(),
                'tingkat_saat_ini' => $t->tingkat_saat_ini,
                'status' => $t->status,
                'terlambat' => $t->status === 'terbuka' ? $t->tenggat_pada->lt($sekarang) : ($t->selesai_pada !== null && $t->selesai_pada->gt($t->tenggat_pada)),
                'kritis' => $t->kritis,
                'dibuat_pada' => $t->dibuat_pada->toIso8601String(),
                'selesai_pada' => $t->selesai_pada?->toIso8601String(),
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    public function tugasRinci(Tugas $t): array
    {
        $kejadian = DB::table('inti.tugas_kejadian')->where('tugas_id', $t->id)->orderBy('terjadi_pada')->orderBy('id')->get();
        $penerimaId = $kejadian->flatMap(fn ($k) => json_decode((string) $k->penerima, true) ?: [])->unique()->values();
        $akun = Pengguna::query()->whereIn('id', $penerimaId)->get(['id', 'pegawai_id', 'nama_pengguna'])->keyBy('id');
        $namaPegawai = $this->pegawai->nama($akun->pluck('pegawai_id'));

        return [...$this->tugas(collect([$t]))[0], 'riwayat' => $kejadian->map(fn ($k) => [
            'jenis' => $k->jenis,
            'tingkat' => (int) $k->tingkat,
            'terjadi_pada' => Carbon::parse($k->terjadi_pada)->toIso8601String(),
            'penerima' => collect(json_decode((string) $k->penerima, true) ?: [])->map(fn ($id) => ($a = $akun->get($id)) === null ? null
                : ['id' => $a->id, 'nama' => $namaPegawai[$a->pegawai_id] ?? $a->nama_pengguna])->filter()->values()->all(),
            'kanal' => json_decode((string) $k->kanal, true) ?: [],
            'catatan' => $k->catatan,
        ])->values()->all()];
    }

    /** @return array<string, mixed> */
    public static function jenisTugas(JenisTugas $j): array
    {
        $unit = DB::table('inti.unit_organisasi')->whereIn('id', array_filter([$j->unit_teguran_id, $j->unit_eskalasi_id]))->get(['id', 'kode', 'nama'])->keyBy('id');
        $ringkas = fn (?string $id) => ($u = $id === null ? null : $unit->get($id)) === null ? null : ['id' => $u->id, 'kode' => $u->kode, 'nama' => $u->nama];

        return [
            'id' => $j->id, 'kode' => $j->kode, 'nama' => $j->nama, 'uraian' => $j->uraian, 'modul' => $j->modul,
            'tenggat_dari' => $j->tenggat_dari, 'batas_menit' => $j->batas_menit,
            'unit_teguran' => $ringkas($j->unit_teguran_id), 'unit_eskalasi' => $ringkas($j->unit_eskalasi_id),
            'eskalasi' => ['pengingat_menit' => $j->pengingat_menit, 'teguran_menit' => $j->teguran_menit, 'eskalasi_menit' => $j->eskalasi_menit],
            'kritis' => $j->kritis, 'aktif' => $j->aktif, 'versi' => (string) $j->revisi, 'diubah_pada' => $j->diubah_pada->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, Tugas>  $baris
     * @return array<string, string> "jenis:id" => nama
     */
    private function namaPelaksana(Collection $baris): array
    {
        $id = fn (string $jenis) => $baris->where('pelaksana_jenis', $jenis)->pluck('pelaksana_id')->unique()->values();
        $hasil = [];
        foreach (DB::table('akses.peran')->whereIn('id', $id('peran'))->pluck('nama', 'id') as $k => $v) {
            $hasil["peran:$k"] = (string) $v;
        }
        foreach (DB::table('inti.unit_organisasi')->whereIn('id', $id('unit'))->pluck('nama', 'id') as $k => $v) {
            $hasil["unit:$k"] = (string) $v;
        }
        $akun = Pengguna::query()->whereIn('id', $id('pengguna'))->get(['id', 'pegawai_id', 'nama_pengguna']);
        $namaPegawai = $this->pegawai->nama($akun->pluck('pegawai_id'));
        foreach ($akun as $a) {
            $hasil["pengguna:{$a->id}"] = $namaPegawai[$a->pegawai_id] ?? $a->nama_pengguna;
        }

        return $hasil;
    }
}
