<?php

namespace App\Modules\RekamMedis\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Http\SuntinganBerversi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\TarifVersi;
use App\Modules\Pendaftaran\Application\KunciKunjungan;
use App\Modules\RekamMedis\Events\PermintaanPemeriksaanDibuat;
use App\Modules\RekamMedis\Models\PermintaanPemeriksaan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Order penunjang bersama (tiket 2.16, kontrak v0.26.0; FR-00-08, FR-07-27, 7.00.5): pesan dan batal. Terikat kunjungan dan
 * episode saat dipesan. Baris pasien dikunci saat memesan agar cek order ganda tidak balapan. Isi klinis tidak ke log/audit.
 */
final class KelolaPermintaanPemeriksaan
{
    public const IZIN_LIHAT = 'rawat_jalan.pemeriksaan.lihat';

    public function __construct(private readonly CatatAktivitas $audit) {}

    /**
     * @param  array{jenis: string, prioritas: string, diagnosis_kerja: string, catatan?: string|null, alasan_ganda?: string|null, item: list<array{tarif_id: string}>}  $isian
     */
    public function pesan(Pengguna $oleh, string $kunjunganId, array $isian): PermintaanPemeriksaan
    {
        $tarifId = array_column($isian['item'], 'tarif_id');
        $versi = $this->versiBerlaku($tarifId, $isian['jenis']);

        return DB::transaction(function () use ($oleh, $kunjunganId, $isian, $tarifId, $versi) {
            // Pasien lalu kunjungan, urutan pendaftaran dan BentukEpisodeIgd (tinjauan #191): kunci pasien juga menjaga cek order ganda.
            $k = KunciKunjungan::pasienLaluKunjungan($kunjunganId) ?? throw new ApiError(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.');
            if ($k->status !== 'dilayani') {
                throw new ApiError(409, 'status_tidak_sesuai', 'Order hanya bisa dibuat saat kunjungan sedang dilayani.', [['status' => $k->status]]);
            }
            if (($isian['alasan_ganda'] ?? null) === null) {
                $ganda = $this->ganda($oleh, $k->pasien_id, $tarifId);
                if ($ganda !== []) {
                    throw new ApiError(409, 'order_ganda', 'Sebagian butir sudah dipesan untuk pasien ini hari ini. Isi alasan untuk tetap memesan.', $ganda);
                }
            }
            $p = new PermintaanPemeriksaan([
                'kunjungan_id' => $k->id, 'episode_id' => $k->episode_id, 'pasien_id' => $k->pasien_id, 'jenis' => $isian['jenis'],
                'prioritas' => $isian['prioritas'], 'pemesan_id' => $oleh->id, 'diagnosis_kerja' => $isian['diagnosis_kerja'],
                'catatan' => $isian['catatan'] ?? null, 'alasan_ganda' => $isian['alasan_ganda'] ?? null,
                'dibuat_oleh' => $oleh->id, 'diubah_oleh' => $oleh->id,
            ]);
            $p->save();
            DB::table('permintaan.permintaan_item')->insert(array_map(fn (string $id) => [
                'id' => (string) Str::uuid7(), 'permintaan_pemeriksaan_id' => $p->id, 'tarif_id' => $id, 'kode_item' => $versi[$id]['kode'],
                'nama' => $versi[$id]['nama'], 'dibuat_oleh' => $oleh->id, 'diubah_oleh' => $oleh->id,
            ], $tarifId));
            PermintaanPemeriksaanDibuat::dispatch($p->id, $oleh->id, now()->toIso8601String());
            $this->audit->catat('permintaan.pemeriksaan_dibuat', $oleh->id, $p->getTable(), $p->id, ['kunjungan_id' => $k->id]);

            return $p->refresh();
        });
    }

    public function batal(Pengguna $oleh, string $permintaanId, string $alasan): PermintaanPemeriksaan
    {
        return DB::transaction(function () use ($oleh, $permintaanId, $alasan) {
            $p = PermintaanPemeriksaan::query()->whereKey($permintaanId)->lockForUpdate()->firstOrFail();
            if ($p->status !== 'dipesan') {
                throw new ApiError(409, 'status_tidak_sesuai', 'Order sudah dibatalkan atau sudah diterima penunjang.', [['status' => $p->status]]);
            }
            // Tiket 2.30: batal hanya sebelum diterima lab. Ambil/terima spesimen mengunci baris permintaan yang sama dulu.
            if (DB::table('permintaan.permintaan_item')->where('permintaan_pemeriksaan_id', $p->id)->where('status', 'diterima_lab')->exists()) {
                throw new ApiError(409, 'status_tidak_sesuai', 'Order sudah diterima penunjang.', [['status' => 'diterima_lab']]);
            }
            $p->forceFill(['status' => 'batal', 'alasan_batal' => $alasan, 'dibatalkan_oleh' => $oleh->id, 'dibatalkan_pada' => now(),
                'diubah_oleh' => $oleh->id, 'revisi' => $p->revisi + 1])->save();
            DB::table('permintaan.permintaan_item')->where('permintaan_pemeriksaan_id', $p->id)->whereIn('status', ['dipesan', 'sampel_diambil'])
                ->update(['status' => 'batal', 'diubah_oleh' => $oleh->id, 'diubah_pada' => now()]);
            $this->audit->catat('permintaan.pemeriksaan_dibatalkan', $oleh->id, $p->getTable(), $p->id, ['kunjungan_id' => $p->kunjungan_id]);

            return $p->refresh();
        });
    }

    /**
     * Unit poli bertanda sensitif (punya nama netral) yang ordernya boleh terlihat akun ini (9.2.5); poli lain selalu terlihat.
     *
     * @return list<string>
     */
    public static function unitSensitifTerbuka(Pengguna $pengguna): array
    {
        $gate = Gate::forUser($pengguna);

        return DB::table('inti.poliklinik')->whereNotNull('nama_netral')->distinct()->pluck('unit_organisasi_id')
            ->filter(fn ($u) => $gate->allows(self::IZIN_LIHAT, new Cakupan('unit', (string) $u))
                || $gate->allows('rawat_jalan.pemeriksaan.order.input', new Cakupan('unit', (string) $u)))
            ->map(fn ($u) => (string) $u)->values()->all();
    }

    /**
     * Versi berlaku hari ini per butir; butir tidak aktif atau kategori lain dari jenis → 422 per baris.
     *
     * @param  list<string>  $tarifId
     * @return array<string, array{kode: string, nama: string}>
     */
    private function versiBerlaku(array $tarifId, string $jenis): array
    {
        $berlaku = TarifVersi::query()->join('keuangan.tarif AS t', 't.id', '=', 'keuangan.tarif_versi.tarif_id')
            ->whereIn('tarif_id', $tarifId)->where('berlaku_dari', '<=', SuntinganBerversi::hariIni())
            ->orderByDesc('berlaku_dari')->orderByDesc('versi')->get(['keuangan.tarif_versi.*', 't.kode'])->unique('tarif_id')->keyBy('tarif_id');
        $salah = [];
        foreach ($tarifId as $n => $id) {
            $v = $berlaku[$id] ?? null;
            if ($v === null || $v->status !== 'aktif' || $v->kategori !== $jenis) {
                $salah["item.$n.tarif_id"] = 'Butir tidak aktif hari ini atau bukan butir order jenis ini.';
            }
        }
        if ($salah !== []) {
            throw ValidationException::withMessages($salah);
        }

        return $berlaku->map(fn (TarifVersi $v) => ['kode' => (string) $v->getAttribute('kode'), 'nama' => $v->nama])->all();
    }

    /**
     * Butir yang sudah dipesan untuk pasien ini hari ini (zona RS), pemesan siapa pun; order dari poli sensitif di luar
     * cakupan akun tidak dihitung. Rincian tanpa pemesan atau poli asal.
     *
     * @param  list<string>  $tarifId
     * @return list<array<string, string>>
     */
    private function ganda(Pengguna $oleh, string $pasienId, array $tarifId): array
    {
        $sensitif = self::unitSensitifTerbuka($oleh);
        $ada = DB::table('permintaan.permintaan_item AS i')
            ->join('permintaan.permintaan_pemeriksaan AS p', 'p.id', '=', 'i.permintaan_pemeriksaan_id')
            ->join('kunjungan.kunjungan AS k', 'k.id', '=', 'p.kunjungan_id')->join('inti.poliklinik AS pl', 'pl.id', '=', 'k.poli_id')
            ->where('p.pasien_id', $pasienId)->where('p.status', '<>', 'batal')->where('i.status', '<>', 'batal')->whereIn('i.tarif_id', $tarifId)
            ->whereRaw('(p.dibuat_pada AT TIME ZONE ?)::date = ?', [config('instalasi.zona_waktu'), SuntinganBerversi::hariIni()])
            ->where(fn ($q) => $q->whereNull('pl.nama_netral')->orWhereIn('pl.unit_organisasi_id', $sensitif))
            ->orderBy('p.dibuat_pada')->get(['i.tarif_id', 'i.nama', 'p.dibuat_pada'])->unique('tarif_id')->keyBy('tarif_id');
        $hasil = [];
        foreach ($tarifId as $n => $id) {
            if (isset($ada[$id])) {
                $hasil[] = ['kolom' => "item.$n.tarif_id", 'pesan' => 'Sudah dipesan hari ini.', 'tarif_id' => $id, 'nama' => $ada[$id]->nama,
                    'dipesan_pada' => Carbon::parse($ada[$id]->dibuat_pada)->toIso8601String()];
            }
        }

        return $hasil;
    }
}
