<?php

namespace App\Modules\MasterData\Application;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Data induk yang dibaca modul Integrasi (tiket 1.9). PRD 10.3: modul lain membaca lewat antarmuka modul ini, tidak
 * langsung ke tabel `inti.*`.
 */
final class DataIntegrasi
{
    /**
     * Ruang Aplicares sebuah bangsal pada satu tanggal: satu baris per kelas rawat yang punya TT (aktif atau sudah ditutup)
     * di kamar bangsal itu; kelas yang semua TT-nya ditutup muncul dengan kapasitas 0. Kelas yang hilang karena TT diubah
     * kelasnya atau kamar dipindah tidak muncul lagi (Integrasi menurunkannya dari catatan ruang yang pernah dikirim). TT
     * ekstra (lonjakan) tidak dihitung. Jumlah per aturan jenis kelamin TT.
     *
     * @return array{kode: string, nama: string, unit_organisasi_id: string, kelas: list<array{kode: string, nama: string, kode_aplicares: string|null, kapasitas: int, laki_laki: int, perempuan: int, campur: int}>}|null
     */
    public function ruangAplicares(string $bangsalId, string $tanggal): ?array
    {
        $bangsal = DB::table('inti.bangsal')->where('id', $bangsalId)->first(['kode', 'nama', 'unit_organisasi_id']);
        if ($bangsal === null) {
            return null;
        }
        $aktif = 'NOT tt.ekstra AND tt.berlaku_dari <= ?::date AND (tt.berlaku_sampai IS NULL OR tt.berlaku_sampai >= ?::date)';
        $kelas = DB::table('inti.tempat_tidur AS tt')->join('inti.kamar AS k', 'k.id', '=', 'tt.kamar_id')
            ->join('inti.kelas_rawat AS kr', 'kr.id', '=', 'tt.kelas_rawat_id')->where('k.bangsal_id', $bangsalId)
            ->groupBy('kr.id', 'kr.kode', 'kr.nama', 'kr.kode_aplicares')->orderBy('kr.kode')
            ->selectRaw("kr.kode, kr.nama, kr.kode_aplicares, count(*) FILTER (WHERE $aktif) AS kapasitas,
                count(*) FILTER (WHERE $aktif AND tt.aturan_jenis_kelamin = 'laki_laki') AS laki_laki,
                count(*) FILTER (WHERE $aktif AND tt.aturan_jenis_kelamin = 'perempuan') AS perempuan,
                count(*) FILTER (WHERE $aktif AND tt.aturan_jenis_kelamin = 'campur') AS campur", array_fill(0, 8, $tanggal))
            ->get();

        return ['kode' => $bangsal->kode, 'nama' => $bangsal->nama, 'unit_organisasi_id' => $bangsal->unit_organisasi_id,
            'kelas' => $kelas->map(fn (object $k) => ['kode' => $k->kode, 'nama' => $k->nama, 'kode_aplicares' => $k->kode_aplicares,
                'kapasitas' => (int) $k->kapasitas, 'laki_laki' => (int) $k->laki_laki, 'perempuan' => (int) $k->perempuan, 'campur' => (int) $k->campur])->all()];
    }

    /**
     * Bangsal yang pernah punya TT (sinkron Aplicares menyeluruh). Jumlah bangsal satu RS kecil (puluhan).
     *
     * @return list<string>
     */
    public function bangsalBerTempatTidur(): array
    {
        return DB::table('inti.kamar AS k')->whereExists(fn ($q) => $q->from('inti.tempat_tidur AS tt')->whereColumn('tt.kamar_id', 'k.id'))
            ->distinct()->orderBy('k.bangsal_id')->pluck('k.bangsal_id')->all();
    }

    /**
     * Satu lokasi fisik untuk Location SATUSEHAT. Status TT diturunkan dari masa berlakunya (seperti tampilan lokasi):
     * di luar masa berlaku = `ditutup`. `sidik` = ringkasan isi yang dikirim; berubah = perlu dikirim ulang.
     *
     * @return array{id: string, induk_id: string|null, jenis: string, kode_lengkap: string, nama: string, status: string, unit_organisasi_id: string|null, sidik: string}|null
     */
    public function lokasi(string $id): ?array
    {
        return $this->queryLokasi()->where('l.id', $id)->get()->map(fn (object $l) => self::barisLokasi($l))->first();
    }

    /**
     * Semua lokasi untuk sinkron SATUSEHAT, induk lebih dulu (kedalaman = jumlah segmen kode_lengkap − 1). Satu RS
     * paling banyak beberapa ribu lokasi.
     *
     * @return list<array{id: string, status: string, sidik: string}>
     */
    public function daftarLokasi(): array
    {
        return $this->queryLokasi()->orderByRaw("length(l.kode_lengkap) - length(replace(l.kode_lengkap, '-', ''))")->orderBy('l.kode_lengkap')
            ->get()->map(fn (object $l) => array_intersect_key(self::barisLokasi($l), array_flip(['id', 'status', 'sidik'])))->all();
    }

    /** @return list<string> id lokasi anak langsung */
    public function anakLokasi(string $id): array
    {
        return DB::table('inti.lokasi')->where('induk_id', $id)->orderBy('id')->pluck('id')->all();
    }

    /**
     * Id Location SATUSEHAT produksi ke kolom tampilan (`inti.lokasi`, `inti.tempat_tidur`). Bukan suntingan data induk:
     * tidak membuat versi dan tidak mengubah `diubah_pada`.
     */
    public function simpanSatusehatLokasiId(string $lokasiId, string $idLuar): void
    {
        DB::table('inti.lokasi')->where('id', $lokasiId)->update(['satusehat_lokasi_id' => $idLuar]);
        DB::table('inti.tempat_tidur')->where('lokasi_id', $lokasiId)->update(['satusehat_lokasi_id' => $idLuar]);
    }

    /**
     * Satu unit organisasi untuk Organization SATUSEHAT.
     *
     * @return array{id: string, induk_id: string|null, kode: string, nama: string, status: string, sidik: string}|null
     */
    public function unit(string $id): ?array
    {
        $u = DB::table('inti.unit_organisasi')->where('id', $id)->first(['id', 'induk_id', 'kode', 'nama', 'status']);

        return $u === null ? null : [...(array) $u, 'sidik' => self::sidikUnit($u)];
    }

    /**
     * Semua unit organisasi, induk lebih dulu (puluhan sampai ratusan baris).
     *
     * @return list<array{id: string, status: string, sidik: string}>
     */
    public function daftarUnit(): array
    {
        $unit = DB::table('inti.unit_organisasi')->orderBy('kode')->get(['id', 'induk_id', 'nama', 'status'])->keyBy('id');
        $kedalaman = function (object $u) use ($unit): int {
            for ($d = 0, $induk = $u->induk_id; $induk !== null && $d < 50; $d++) {
                $induk = $unit[$induk]->induk_id ?? null;
            }

            return $d;
        };

        return $unit->sortBy($kedalaman)->map(fn (object $u) => ['id' => $u->id, 'status' => $u->status, 'sidik' => self::sidikUnit($u)])->values()->all();
    }

    /** @return list<string> id unit anak langsung */
    public function anakUnit(string $id): array
    {
        return DB::table('inti.unit_organisasi')->where('induk_id', $id)->orderBy('id')->pluck('id')->all();
    }

    private function queryLokasi(): Builder
    {
        return DB::table('inti.lokasi AS l')->leftJoin('inti.tempat_tidur AS tt', 'tt.lokasi_id', '=', 'l.id')
            ->select(['l.id', 'l.induk_id', 'l.jenis', 'l.kode_lengkap', 'l.nama', 'l.status', 'l.unit_organisasi_id', 'tt.berlaku_dari', 'tt.berlaku_sampai']);
    }

    /** @return array{id: string, induk_id: string|null, jenis: string, kode_lengkap: string, nama: string, status: string, unit_organisasi_id: string|null, sidik: string} */
    private static function barisLokasi(object $l): array
    {
        $hariIni = SuntinganBerversi::hariIni();
        $status = $l->jenis === 'tempat_tidur' && $l->berlaku_dari !== null
            && ($l->berlaku_dari > $hariIni || ($l->berlaku_sampai !== null && $l->berlaku_sampai < $hariIni)) ? 'ditutup' : $l->status;
        $baris = ['id' => $l->id, 'induk_id' => $l->induk_id, 'jenis' => $l->jenis, 'kode_lengkap' => $l->kode_lengkap, 'nama' => $l->nama,
            'status' => $status, 'unit_organisasi_id' => $l->unit_organisasi_id];

        return [...$baris, 'sidik' => md5((string) json_encode([$baris['nama'], $status, $baris['induk_id'], $baris['jenis'], $baris['kode_lengkap']]))];
    }

    private static function sidikUnit(object $u): string
    {
        return md5((string) json_encode([$u->nama, $u->status, $u->induk_id]));
    }
}
