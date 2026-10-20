<?php

namespace App\Modules\MasterData\Application;

use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\KelasRawat;
use App\Modules\MasterData\Models\Tarif;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Butir usulan jenis data `tarif` (tiket 2.2): bentuk isian, potret nilai lama dan revisi, penerapan lewat KelolaTarif,
 * dan baris tampilan diff berlabel. Pasangan ButirRuangTt untuk mesin usulan 1.8c.
 */
final class ButirTarif
{
    private const RUPIAH = ['required', 'string', 'regex:/^[0-9]{1,13}(\.[0-9]{1,2})?$/'];

    private const LABEL = ['kode' => 'Kode', 'nama' => 'Nama', 'kelompok' => 'Kelompok', 'status' => 'Status', 'harga' => 'Harga per kelas'];

    public function __construct(private readonly KelolaTarif $kelola, private readonly BukuTarif $buku) {}

    /**
     * @param  array<string, mixed>  $butir
     * @return list<array{kolom: string, kode: string, pesan: string}>
     */
    public function periksaBentuk(int $i, array $butir): array
    {
        $harga = ['required', 'array', 'min:1', 'max:50'];
        $aturan = match ($butir['aksi'] ?? '') {
            'tambah' => ['kode' => ['required', 'string', 'regex:/^[A-Z0-9][A-Z0-9._-]{0,29}$/'], 'nama' => ['required', 'string', 'min:2', 'max:200'],
                'kelompok' => ['required', 'string', 'min:1', 'max:50'], 'harga' => $harga],
            'ubah' => ['nama' => ['sometimes', 'string', 'min:2', 'max:200'], 'kelompok' => ['sometimes', 'string', 'min:1', 'max:50'],
                'status' => ['sometimes', Rule::in(['aktif', 'nonaktif'])], 'harga' => ['sometimes', ...array_slice($harga, 1)]],
            'tutup' => ['alasan' => ['required', 'string', 'min:5', 'max:500']],
            default => null,
        };
        $kolom = "perubahan.$i";
        if ($aturan === null) {
            return [['kolom' => "$kolom.aksi", 'kode' => 'aksi_tidak_dikenal', 'pesan' => 'Aksi ini tidak tersedia untuk objek itu.']];
        }
        $tambah = $butir['aksi'] === 'tambah';
        if ($tambah !== (($butir['objek_id'] ?? null) === null)) {
            return [['kolom' => "$kolom.objek_id", 'kode' => 'objek_tidak_ditemukan', 'pesan' => $tambah ? 'Kosongkan objek untuk tambah.' : 'Pilih objek yang diubah.']];
        }
        $nilai = is_array($butir['nilai_baru'] ?? null) ? $butir['nilai_baru'] : [];
        $asing = array_diff(array_keys($nilai), array_keys($aturan));
        if ($asing !== []) {
            return [['kolom' => "$kolom.nilai_baru", 'kode' => 'isian_tidak_dikenal', 'pesan' => 'Isian tidak dikenal: '.implode(', ', $asing).'.']];
        }
        if ($butir['aksi'] === 'ubah' && $nilai === []) {
            return [['kolom' => "$kolom.nilai_baru", 'kode' => 'isian_tidak_valid', 'pesan' => 'Isi minimal satu perubahan.']];
        }
        $rincian = [];
        foreach (Validator::make($nilai, $aturan)->errors()->messages() as $isian => $pesan) {
            $rincian[] = ['kolom' => "$kolom.$isian", 'kode' => 'isian_tidak_valid', 'pesan' => $pesan[0]];
        }
        if ($rincian === [] && isset($nilai['harga'])) {
            array_push($rincian, ...$this->periksaHarga($kolom, $nilai['harga']));
        }

        return $rincian;
    }

    /**
     * @param  array<mixed>  $harga
     * @return list<array{kolom: string, kode: string, pesan: string}>
     */
    private function periksaHarga(string $kolom, array $harga): array
    {
        $baris = Validator::make(['harga' => $harga], [
            'harga.*' => ['array:kelas_rawat_id,jasa_sarana,jasa_pelayanan,bhp'], 'harga.*.kelas_rawat_id' => ['required', 'uuid'],
            'harga.*.jasa_sarana' => self::RUPIAH, 'harga.*.jasa_pelayanan' => self::RUPIAH, 'harga.*.bhp' => self::RUPIAH,
        ]);
        $rincian = [];
        foreach ($baris->errors()->messages() as $isian => $pesan) {
            $rincian[] = ['kolom' => "$kolom.nilai_baru.$isian", 'kode' => 'isian_tidak_valid', 'pesan' => $pesan[0]];
        }
        $id = array_column($harga, 'kelas_rawat_id');
        if ($rincian === [] && count($id) !== count(array_unique($id))) {
            $rincian[] = ['kolom' => "$kolom.nilai_baru.harga", 'kode' => 'kelas_ganda', 'pesan' => 'Satu kelas rawat hanya boleh punya satu baris harga.'];
        }

        return $rincian;
    }

    /**
     * @param  array<string, mixed>  $butir
     * @return array{label: string, revisi: int|null, nilai_lama: array<string, mixed>|null}|null
     */
    public function potret(array $butir): ?array
    {
        $nilai = (array) ($butir['nilai_baru'] ?? []);
        if ($butir['aksi'] === 'tambah') {
            return ['label' => 'Tarif baru '.($nilai['kode'] ?? '').' · '.($nilai['nama'] ?? ''), 'revisi' => null, 'nilai_lama' => null];
        }
        $tarif = $this->objek((string) $butir['objek_id']);
        $terbaru = $tarif === null ? null : $this->buku->versiTerbaru($tarif->id);
        if ($tarif === null || $terbaru === null) {
            return null;
        }
        $isi = $this->buku->isi($terbaru);

        return ['label' => $tarif->kode.' · '.$terbaru->nama, 'revisi' => $tarif->revisi,
            'nilai_lama' => $butir['aksi'] === 'tutup' ? ['status' => $isi['status']] : array_intersect_key($isi, $nilai)];
    }

    /** @param  array<string, mixed>  $butir */
    public function terapkan(Pengguna $penyunting, array $butir, string $berlakuDari, string $dasar): string
    {
        $nilai = (array) ($butir['nilai_baru'] ?? []);
        if ($butir['aksi'] === 'tambah') {
            return $this->kelola->buat($penyunting, $nilai, $berlakuDari, $dasar)->id;
        }
        $tarif = $this->objek((string) $butir['objek_id']) ?? throw new ApiError(404, 'objek_tidak_ditemukan', 'Objek tidak ditemukan lagi.');
        $versi = (string) ($butir['revisi_objek'] ?? '');

        return match ($butir['aksi']) {
            'ubah' => $this->kelola->ubah($penyunting, $tarif, $versi, $nilai, $berlakuDari, $dasar)->id,
            'tutup' => $this->kelola->tutup($penyunting, $tarif, $versi, $berlakuDari, (string) $nilai['alasan'], $dasar)->id,
            default => throw new \LogicException('Butir tidak dikenal.'),
        };
    }

    public function objek(string $id): ?Tarif
    {
        return Tarif::query()->find($id);
    }

    /**
     * @param  array<string, mixed>  $butir
     * @return list<array{isian: string, label_isian: string, lama: string|null, baru: string|null}>
     */
    public function tampilan(array $butir, string $berlakuDari): array
    {
        if ($butir['aksi'] === 'tutup') {
            return [['isian' => 'status', 'label_isian' => 'Status', 'lama' => 'Aktif', 'baru' => 'Ditutup mulai '.Carbon::parse($berlakuDari)->format('d-m-Y')]];
        }
        $lama = (array) ($butir['nilai_lama'] ?? []);
        $baris = [];
        foreach ((array) $butir['nilai_baru'] as $isian => $baru) {
            $baris[] = ['isian' => (string) $isian, 'label_isian' => self::LABEL[$isian] ?? (string) $isian,
                'lama' => $butir['aksi'] === 'tambah' ? null : $this->teks((string) $isian, $lama[$isian] ?? null),
                'baru' => $this->teks((string) $isian, $baru) ?? '—'];
        }

        return $baris;
    }

    /**
     * @param  array<string, mixed>|null  $sebelum
     * @param  array<string, mixed>  $sesudah
     * @return list<array{isian: string, label_isian: string, lama: string|null, baru: string|null}>
     */
    public function tampilanVersi(?array $sebelum, array $sesudah): array
    {
        $baris = [];
        foreach ($sesudah as $isian => $baru) {
            if ($sebelum !== null && ($sebelum[$isian] ?? null) === $baru) {
                continue;
            }
            $baris[] = ['isian' => (string) $isian, 'label_isian' => self::LABEL[$isian] ?? (string) $isian,
                'lama' => $sebelum === null ? null : $this->teks((string) $isian, $sebelum[$isian] ?? null), 'baru' => $this->teks((string) $isian, $baru)];
        }

        return $baris;
    }

    private function teks(string $isian, mixed $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }
        if ($isian === 'status') {
            return ['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif'][$nilai] ?? (string) $nilai;
        }
        if ($isian !== 'harga' || ! is_array($nilai)) {
            return (string) $nilai;
        }
        $kelas = KelasRawat::query()->whereIn('id', array_column($nilai, 'kelas_rawat_id'))->pluck('nama', 'id');

        return implode('; ', array_map(function (array $h) use ($kelas) {
            $r = BukuTarif::barisHarga((string) $h['kelas_rawat_id'], '', (string) $h['jasa_sarana'], (string) $h['jasa_pelayanan'], (string) $h['bhp']);

            return ($kelas[$h['kelas_rawat_id']] ?? $h['kelas_rawat_id']).': sarana '.$r['jasa_sarana'].', pelayanan '.$r['jasa_pelayanan'].', BHP '.$r['bhp'].', total '.$r['total'];
        }, $nilai));
    }
}
