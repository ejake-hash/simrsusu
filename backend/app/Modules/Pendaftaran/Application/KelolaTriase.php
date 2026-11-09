<?php

namespace App\Modules\Pendaftaran\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Instalasi\StatusInstalasi;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Pendaftaran\Models\Kunjungan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Triase IGD dan ulang triase (tiket 2.24, kontrak v0.32.0; FR-03-02). Setiap triase = baris baru di kunjungan.triase
 * (tidak pernah diubah); triase berlaku = ditriase_pada terbaru. Kategori hanya empat warna FR-03-02. Instrumen dan skala
 * dibaca dari data induk inti.instrumen_triase: kode tidak memuat daftar instrumen, skala, atau pemetaan skala ke
 * kategori. Keluhan triase klinis: tidak ke log/audit.
 */
final class KelolaTriase
{
    public const KATEGORI = ['merah', 'kuning', 'hijau', 'hitam'];

    public function __construct(private readonly CatatAktivitas $audit, private readonly StatusInstalasi $instalasi) {}

    /**
     * @param  array{kategori: string, instrumen_triase_id?: string|null, skala?: string|null, keluhan?: string|null}  $isi  sudah divalidasi bentuknya
     * @return string id triase baru
     */
    public function catat(Pengguna $petugas, string $kunjunganId, array $isi): string
    {
        KelolaPasien::pastikanSiapMelayani($this->instalasi);

        return DB::transaction(function () use ($petugas, $kunjunganId, $isi) {
            $k = KunciKunjungan::pasienLaluKunjungan($kunjunganId) ?? throw new ApiError(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.');
            if ($k->jenis_kunjungan !== Kunjungan::IGD || ! in_array($k->status, Kunjungan::STATUS_AKTIF, true)) {
                throw new ApiError(409, 'status_tidak_sesuai', 'Triase hanya untuk kunjungan IGD yang masih terdaftar atau dilayani.',
                    [['jenis_kunjungan' => $k->jenis_kunjungan, 'status' => $k->status]]);
            }
            [$instrumenId, $skala] = $this->periksaInstrumen($isi['instrumen_triase_id'] ?? null, $isi['skala'] ?? null);
            $keluhan = isset($isi['keluhan']) && trim($isi['keluhan']) !== '' ? trim($isi['keluhan']) : null;

            // Kunjungan terkunci: triase serentak kunjungan yang sama berurutan, jadi `ulang` dan urutan riwayat tetap benar.
            $sebelumnya = DB::table('kunjungan.triase')->where('kunjungan_id', $k->id)
                ->orderByDesc('ditriase_pada')->orderByDesc('id')->value('id');
            $id = (string) Str::uuid7();
            // Format eksplisit: binding Carbon bawaan membuang mikrodetik, padahal urutan riwayat memakai jam ini.
            $sekarang = now()->utc()->format('Y-m-d H:i:s.uP');
            DB::table('kunjungan.triase')->insert([
                'id' => $id, 'kunjungan_id' => $k->id, 'kategori' => $isi['kategori'], 'instrumen_triase_id' => $instrumenId,
                'skala' => $skala, 'keluhan' => $keluhan, 'ditriase_pada' => $sekarang, 'petugas_id' => $petugas->id, 'dibuat_pada' => $sekarang,
            ]);

            $this->audit->catat('kunjungan.ditriase', $petugas->id, 'kunjungan.triase', $id, [
                'kunjungan_id' => $k->id, 'kategori' => $isi['kategori'], 'instrumen_triase_id' => $instrumenId,
                'ulang' => $sebelumnya !== null, 'triase_sebelumnya_id' => $sebelumnya,
            ]);

            return $id;
        });
    }

    /**
     * Triase berlaku (terbaru) per kunjungan, satu kueri per halaman daftar.
     *
     * @param  list<string>  $kunjunganId
     * @return array<string, \stdClass> kunjungan_id => {id, kunjungan_id, kategori, ditriase_pada}
     */
    public static function berlaku(array $kunjunganId): array
    {
        if ($kunjunganId === []) {
            return [];
        }
        $tanda = implode(', ', array_fill(0, count($kunjunganId), '?'));
        $hasil = [];
        foreach (DB::select("SELECT DISTINCT ON (kunjungan_id) id, kunjungan_id, kategori, ditriase_pada FROM kunjungan.triase
            WHERE kunjungan_id IN ($tanda) ORDER BY kunjungan_id, ditriase_pada DESC, id DESC", $kunjunganId) as $baris) {
            $hasil[(string) $baris->kunjungan_id] = $baris;
        }

        return $hasil;
    }

    /**
     * Instrumen wajib bila RS punya instrumen aktif; skala wajib dan harus salah satu kode skala instrumen bila instrumen
     * itu punya daftar skala.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function periksaInstrumen(?string $instrumenId, ?string $skala): array
    {
        $skala = $skala === null || trim($skala) === '' ? null : trim($skala);
        if ($instrumenId === null) {
            if (DB::table('inti.instrumen_triase')->where('status', 'aktif')->exists()) {
                throw ValidationException::withMessages(['instrumen_triase_id' => 'Pilih instrumen triase.']);
            }
            if ($skala !== null) {
                throw ValidationException::withMessages(['skala' => 'Skala hanya diisi bersama instrumen triase.']);
            }

            return [null, null];
        }

        $instrumen = DB::table('inti.instrumen_triase')->where('id', $instrumenId)->where('status', 'aktif')->first(['id', 'skala']);
        if ($instrumen === null) {
            throw ValidationException::withMessages(['instrumen_triase_id' => 'Instrumen triase tidak ditemukan atau tidak aktif.']);
        }
        $kode = array_column(self::daftarSkala($instrumen->skala), 'kode');
        if ($kode === [] && $skala !== null) {
            throw ValidationException::withMessages(['skala' => 'Instrumen ini tidak memakai skala.']);
        }
        if ($kode !== [] && ($skala === null || ! in_array($skala, $kode, true))) {
            throw ValidationException::withMessages(['skala' => 'Pilih skala dari instrumen triase ini.']);
        }

        return [$instrumen->id, $skala];
    }

    /**
     * Isi kolom jsonb skala data induk; butir yang bentuknya rusak dilewati.
     *
     * @return list<array{kode: string, label: string}>
     */
    public static function daftarSkala(mixed $json): array
    {
        $isi = is_string($json) ? json_decode($json, true) : $json;
        if (! is_array($isi)) {
            return [];
        }
        $hasil = [];
        foreach ($isi as $butir) {
            if (is_array($butir) && isset($butir['kode']) && is_scalar($butir['kode']) && (string) $butir['kode'] !== '') {
                $hasil[] = ['kode' => (string) $butir['kode'], 'label' => isset($butir['label']) && is_scalar($butir['label']) ? (string) $butir['label'] : (string) $butir['kode']];
            }
        }

        return $hasil;
    }
}
