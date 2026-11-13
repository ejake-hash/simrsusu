<?php

namespace App\Modules\Lab\Application;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\Notifikasi\Application\KotakKerja;
use App\Modules\Notifikasi\Application\PenerimaTugas;
use App\Modules\Notifikasi\Application\Pemberitahu;
use App\Modules\Notifikasi\Models\JenisTugas;
use App\Modules\Notifikasi\Models\Tugas;
use Illuminate\Support\Facades\DB;

/**
 * Hasil mengikuti pasien (tiket 2.31; FR-00-09, 7.00.5 butir 2). Saat hasil final (atau adendum), di transaksi pemanggil:
 * tugas HASIL_LAB_TINDAK_LANJUT ke penanggung jawab pasien saat itu, dan notifikasi informasi ke pemesan bila pemesan bukan
 * penanggung jawab itu. Urutan lokasi = `lokasi_saat_ini` daftar kerja lab: TT aktif → DPJP kunjungan rawat inap; kunjungan
 * IGD aktif → dokter kunjungan IGD; selain itu → pemesan. Tugas hanya ke akun yang bisa membuka linimasa hasil episode
 * (AksesHasilLab, keputusan pemilik 13 Nov 2026); penanggung jawab tanpa akun atau tanpa akses → pemesan (unit
 * bangsal/IGD belum menerima tugas: perlu keputusan akses rawat inap). Judul tanpa data pasien dan tanpa nilai (7.53).
 */
final class RuteHasilLab
{
    public const TUGAS = 'HASIL_LAB_TINDAK_LANJUT';

    public const NOTIFIKASI = 'HASIL_LAB_INFORMASI';

    public function __construct(
        private readonly KotakKerja $kotakKerja,
        private readonly Pemberitahu $pemberitahu,
        private readonly PenerimaTugas $penerima,
        private readonly AksesHasilLab $akses,
    ) {}

    /**
     * @param  string  $objekId  id hasil, atau id adendum untuk adendum
     * @return array{pelaksana: array{0: string, 1: string}, tugas_id: string|null, pemesan_diberitahu: bool}
     */
    public function kirim(string $hasilId, string $objekId, bool $adendum): array
    {
        $p = DB::table('lab.hasil AS h')->join('permintaan.permintaan_item AS i', 'i.id', '=', 'h.permintaan_item_id')
            ->join('permintaan.permintaan_pemeriksaan AS p', 'p.id', '=', 'i.permintaan_pemeriksaan_id')
            ->join('kunjungan.kunjungan AS k', 'k.id', '=', 'p.kunjungan_id')
            ->where('h.id', $hasilId)->first(['p.pasien_id', 'p.pemesan_id', 'p.episode_id', 'k.nomor']);
        $pemesan = ['pengguna', (string) $p->pemesan_id];
        [$pelaksana, $unit] = $this->penanggungJawab((string) $p->pasien_id, (string) $p->episode_id) ?? [$pemesan, null];

        $judul = ($adendum ? 'Adendum hasil lab' : 'Hasil lab').' kunjungan '.$p->nomor.' untuk ditindaklanjuti';
        $tugas = $this->kotakKerja->buat(self::TUGAS, $objekId, $judul, $pelaksana, $unit, ['hasil_id' => $hasilId]);

        $beritahu = $pelaksana !== $pemesan;
        if ($beritahu) {
            $info = ($adendum ? 'Adendum hasil lab' : 'Hasil lab').' order Anda di kunjungan '.$p->nomor.' sudah keluar';
            $this->pemberitahu->kirim([(string) $p->pemesan_id], self::NOTIFIKASI, $info, $info.'.',
                str_replace('{hasil_id}', $hasilId, (string) JenisTugas::query()->where('kode', self::TUGAS)->value('tautan_layar')), 'biasa');
        }

        return ['pelaksana' => $pelaksana, 'tugas_id' => $tugas?->id, 'pemesan_diberitahu' => $beritahu];
    }

    /**
     * Selesaikan tugas tindak lanjut terbuka milik akun ini (langsung, peran, atau unit cakupannya) untuk objek itu
     * (id hasil dan id adendum): hasil sudah dibuka penanggung jawab.
     *
     * @param  list<string>  $objekId
     */
    public function selesaikanDibuka(Pengguna $pengguna, array $objekId): int
    {
        if ($objekId === []) {
            return 0;
        }
        $identitas = $this->penerima->identitasPelaksana($pengguna);
        $objek = Tugas::query()->whereIn('jenis_tugas_id', JenisTugas::query()->where('kode', self::TUGAS)->select('id'))
            ->whereIn('objek_id', $objekId)->where('status', 'terbuka')
            ->where(function ($q) use ($identitas) {
                foreach ($identitas as [$jenis, $id]) {
                    $q->orWhere(fn ($q) => $q->where('pelaksana_jenis', $jenis)->where('pelaksana_id', $id));
                }
            })->pluck('objek_id')->unique()->values();
        if ($objek->isEmpty()) {
            return 0;
        }

        return DB::transaction(fn () => $objek->sum(fn ($id) => $this->kotakKerja->selesaikan(self::TUGAS, (string) $id, 'Hasil dibuka penanggung jawab')));
    }

    /**
     * Penanggung jawab saat ini yang bisa membuka linimasa episode order; null = jatuh ke pemesan.
     *
     * @return array{0: array{0: string, 1: string}, 1: string|null}|null [pelaksana, unit teguran]
     */
    private function penanggungJawab(string $pasienId, string $episodeId): ?array
    {
        $ri = DB::table('kunjungan.penempatan_tempat_tidur AS pn')->join('kunjungan.kunjungan AS k', 'k.id', '=', 'pn.kunjungan_id')
            ->where('pn.pasien_id', $pasienId)->whereNull('pn.selesai_pada')->first(['k.pegawai_id', 'k.unit_id']);
        if ($ri !== null) {
            $akun = $this->akunBisaBuka($ri->pegawai_id, $episodeId);

            // Unit bangsal (kunjungan rawat inap selalu ber-unit) hanya sebagai unit teguran tugas DPJP.
            return $akun === null ? null : [['pengguna', $akun], (string) $ri->unit_id];
        }
        $igd = DB::table('kunjungan.kunjungan')->where('pasien_id', $pasienId)->where('jenis_kunjungan', 'igd')
            ->whereIn('status', ['terdaftar', 'dilayani'])->first(['pegawai_id']);
        if ($igd !== null) {
            $akun = $this->akunBisaBuka($igd->pegawai_id, $episodeId);

            return $akun === null ? null : [['pengguna', $akun], null];
        }

        return null;
    }

    /** Akun aktif pegawai itu yang bisa membuka GET /episode/{id}/hasil-lab episode order (tugas tanpa akses tak berguna). */
    private function akunBisaBuka(mixed $pegawaiId, string $episodeId): ?string
    {
        if ($pegawaiId === null) {
            return null;
        }
        foreach (Pengguna::query()->where('pegawai_id', (string) $pegawaiId)->where('status', 'aktif')->orderBy('id')->limit(5)->get() as $akun) {
            if ($this->akses->bolehBukaEpisode($akun, $episodeId)) {
                return (string) $akun->id;
            }
        }

        return null;
    }
}

