<?php

namespace App\Modules\Akses\Application;

use App\Modules\Akses\Models\AksesDarurat;
use App\Modules\Akses\Models\Pengguna;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Akses darurat yang sedang berlaku (tiket 2.21, 9.2.5 lapis 3–4) dan log pembacaannya (audit.log_akses). Query langsung
 * tanpa cache agar akses tertutup tepat pada berakhir_pada.
 */
final class AksesDaruratAktif
{
    /**
     * Akses darurat aktif akun ini untuk salah satu pasien; RM lama yang digabung ke pasien akses itu ikut (2.9).
     *
     * @param  list<string>  $pasienId
     */
    public function untuk(Pengguna $pengguna, array $pasienId): ?AksesDarurat
    {
        $pasienId = array_values(array_filter($pasienId, fn ($id) => Str::isUuid($id)));
        if ($pasienId === [] || ! $pengguna->aktif() || $pengguna->terkunci()) {
            return null;
        }
        $sekarang = now();

        return AksesDarurat::query()->where('pengguna_id', $pengguna->id)
            ->where('mulai_pada', '<=', $sekarang)->where('berakhir_pada', '>', $sekarang)
            ->where(fn ($q) => $q->whereIn('pasien_id', $pasienId)
                ->orWhereIn('pasien_id', DB::table('pasien.pasien')->whereIn('id', $pasienId)->whereNotNull('digabung_ke_id')->select('digabung_ke_id')))
            ->orderByDesc('berakhir_pada')->first(['id', 'pasien_id', 'kategori_alasan', 'mulai_pada', 'berakhir_pada']);
    }

    /** @return list<string> */
    public static function pasienEpisode(string $episodeId): array
    {
        return DB::table('kunjungan.episode')->where('id', $episodeId)->pluck('pasien_id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * Catat satu pembacaan RM pasien selama akun ini punya akses darurat aktif untuknya (UC-33, FR-26-12). Tanpa isi klinis
     * dan tanpa uraian alasan; alasan = kategori. Tidak ada akses aktif → tidak dicatat.
     *
     * @param  list<string>  $pasienId  pasien yang dibaca dulu, lalu RM lama yang digabung ke pasien itu
     */
    public function catatBaca(Request $request, array $pasienId, ?string $kunjunganId, string $layar): void
    {
        $pengguna = $request->user();
        $akses = $pengguna instanceof Pengguna ? $this->untuk($pengguna, $pasienId) : null;
        if ($akses === null) {
            return;
        }
        DB::table('audit.log_akses')->insert([
            'id' => (string) Str::uuid7(), 'pengguna_id' => $pengguna->id, 'pasien_id' => $pasienId[0], 'kunjungan_id' => $kunjunganId,
            'layar' => $layar, 'aksi' => 'lihat', 'alasan' => $akses->kategori_alasan, 'akses_darurat_id' => $akses->id, 'ip' => $request->ip(),
            // Jam yang sama dengan mulai_pada/berakhir_pada (jumlah_akses disaring rentang itu).
            'terjadi_pada' => now(),
        ]);
    }
}
