<?php

namespace App\Modules\Notifikasi\Application;

use App\Modules\Notifikasi\Domain\PengirimPesanSingkat;
use App\Modules\Notifikasi\Jobs\KirimEmailNotifikasi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kirim notifikasi ke sekumpulan akun (tiket 1.7, FR-53-06/09, K2): in-app selalu; email bila akun punya alamat dan
 * preferensinya menyala (selalu untuk kritis); pesan singkat lewat adapter bila tersedia dan dinyalakan.
 */
final class Pemberitahu
{
    public function __construct(private readonly PengirimPesanSingkat $pesanSingkat) {}

    /**
     * @param  list<string>  $penggunaId
     * @return list<string> kanal yang dipakai (in_app, email, pesan_singkat)
     */
    public function kirim(array $penggunaId, string $jenis, string $judul, string $isi, ?string $tautan, string $prioritas, ?string $tugasId = null, bool $bolehEmail = true): array
    {
        $penggunaId = array_values(array_unique($penggunaId));
        if ($penggunaId === []) {
            return [];
        }
        $sekarang = now();
        DB::table('notifikasi.notifikasi')->insert(array_map(fn (string $id) => [
            'id' => (string) Str::uuid7(), 'pengguna_id' => $id, 'jenis' => $jenis, 'judul' => $judul, 'isi' => $isi,
            'tautan' => $tautan, 'prioritas' => $prioritas, 'tugas_id' => $tugasId, 'dibuat_pada' => $sekarang->format('Y-m-d H:i:s.uP'),
        ], $penggunaId));

        $kanal = ['in_app'];
        $kritis = $prioritas === 'kritis';
        $preferensi = DB::table('notifikasi.preferensi')->whereIn('pengguna_id', $penggunaId)->where('jenis', $jenis)->get()->keyBy('pengguna_id');
        $punyaEmail = DB::table('akses.pengguna')->whereIn('id', $penggunaId)->whereNotNull('email')->where('email', '!=', '')->pluck('id')->flip();
        foreach ($penggunaId as $id) {
            $p = $preferensi->get($id);
            if ($bolehEmail && isset($punyaEmail[$id]) && ($kritis || $p === null || $p->email)) {
                KirimEmailNotifikasi::dispatch($id, $judul, $isi, $tautan);
                $kanal[] = 'email';
            }
            if ($this->pesanSingkat->tersedia() && ($kritis || ($p !== null && $p->pesan_singkat))) {
                $this->pesanSingkat->kirim($id, $judul);
                $kanal[] = 'pesan_singkat';
            }
        }

        return array_values(array_unique($kanal));
    }
}
