<?php

namespace App\Modules\MasterData\Application;

use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\Bangsal;
use App\Modules\MasterData\Models\Kamar;
use App\Modules\MasterData\Models\KelasRawat;
use App\Modules\MasterData\Models\Lokasi;
use App\Modules\MasterData\Models\TempatTidur;
use App\Modules\MasterData\Models\UnitOrganisasi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Butir usulan jenis data `ruang_tt` (tiket 1.8c): bentuk isian per objek/aksi, potret nilai lama dan revisi saat
 * diusulkan, penerapan lewat layanan 1.8b (aturan yang sama), dan baris tampilan diff berlabel.
 */
final class ButirRuangTt
{
    private const JENIS_KELAMIN = ['campur', 'laki_laki', 'perempuan'];

    private const LABEL = [
        'kamar_id' => 'Kamar', 'kode' => 'Kode', 'kelas_rawat_id' => 'Kelas rawat', 'aturan_jenis_kelamin' => 'Aturan jenis kelamin',
        'isolasi' => 'Isolasi', 'ekstra' => 'TT ekstra', 'status_tt' => 'Status', 'bangsal_id' => 'Bangsal', 'lokasi_id' => 'Ruang',
        'kapasitas_maksimum' => 'Kapasitas maksimum', 'atribut_standar' => 'Atribut standar', 'nama' => 'Nama', 'fungsi' => 'Fungsi',
        'unit_organisasi_id' => 'Unit pengelola', 'status' => 'Status', 'induk_id' => 'Induk',
    ];

    public function __construct(
        private readonly KelolaTempatTidur $tempatTidur,
        private readonly KelolaRawatInap $rawatInap,
        private readonly KelolaLokasi $lokasi,
    ) {}

    /**
     * Periksa bentuk satu butir; daftar rincian error (kolom relatif "perubahan.<i>").
     *
     * @param  array<string, mixed>  $butir
     * @return list<array{kolom: string, kode: string, pesan: string}>
     */
    public function periksaBentuk(int $i, array $butir): array
    {
        $kunci = ($butir['objek_jenis'] ?? '').'.'.($butir['aksi'] ?? '');
        $aturan = match ($kunci) {
            'tempat_tidur.tambah' => ['kamar_id' => ['required', 'uuid'], 'kode' => ['required', 'string', 'regex:/^[A-Z0-9]{1,10}$/'],
                'kelas_rawat_id' => ['required', 'uuid'], 'aturan_jenis_kelamin' => ['nullable', Rule::in(self::JENIS_KELAMIN)],
                'isolasi' => ['sometimes', 'boolean'], 'ekstra' => ['sometimes', 'boolean']],
            'tempat_tidur.ubah' => ['kelas_rawat_id' => ['sometimes', 'uuid'], 'aturan_jenis_kelamin' => ['sometimes', Rule::in(self::JENIS_KELAMIN)],
                'isolasi' => ['sometimes', 'boolean'], 'ekstra' => ['sometimes', 'boolean']],
            'tempat_tidur.tutup' => ['alasan' => ['required', 'string', 'min:5', 'max:500']],
            'kamar.tambah' => ['bangsal_id' => ['required', 'uuid'], 'lokasi_id' => ['required', 'uuid'],
                'kapasitas_maksimum' => ['nullable', 'integer', 'min:1', 'max:99'], 'atribut_standar' => ['nullable', 'array', 'max:50']],
            'kamar.ubah' => ['bangsal_id' => ['sometimes', 'uuid'], 'kapasitas_maksimum' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:99'],
                'atribut_standar' => ['sometimes', 'nullable', 'array', 'max:50']],
            'ruang.tambah' => ['induk_id' => ['required', 'uuid'], 'kode' => ['required', 'string', 'regex:/^[A-Z0-9]{1,10}$/'],
                'nama' => ['required', 'string', 'min:2', 'max:150'], 'fungsi' => ['required', Rule::in(Lokasi::FUNGSI)],
                'unit_organisasi_id' => ['nullable', 'uuid']],
            'ruang.ubah' => ['induk_id' => ['sometimes', 'uuid'], 'kode' => ['sometimes', 'string', 'regex:/^[A-Z0-9]{1,10}$/'],
                'nama' => ['sometimes', 'string', 'min:2', 'max:150'], 'fungsi' => ['sometimes', Rule::in(Lokasi::FUNGSI)],
                'unit_organisasi_id' => ['sometimes', 'nullable', 'uuid'], 'status' => ['sometimes', Rule::in(Lokasi::STATUS)]],
            default => null,
        };
        $kolom = "perubahan.$i";
        if ($aturan === null) {
            return [['kolom' => "$kolom.aksi", 'kode' => 'aksi_tidak_dikenal', 'pesan' => 'Aksi ini tidak tersedia untuk objek itu.']];
        }
        $tambah = ($butir['aksi'] ?? '') === 'tambah';
        if ($tambah !== (($butir['objek_id'] ?? null) === null)) {
            return [['kolom' => "$kolom.objek_id", 'kode' => 'objek_tidak_ditemukan', 'pesan' => $tambah ? 'Kosongkan objek untuk tambah.' : 'Pilih objek yang diubah.']];
        }
        $nilai = is_array($butir['nilai_baru'] ?? null) ? $butir['nilai_baru'] : [];
        $asing = array_diff(array_keys($nilai), array_keys($aturan));
        if ($asing !== []) {
            return [['kolom' => "$kolom.nilai_baru", 'kode' => 'isian_tidak_dikenal', 'pesan' => 'Isian tidak dikenal: '.implode(', ', $asing).'.']];
        }
        if (! $tambah && ($butir['aksi'] ?? '') === 'ubah' && $nilai === []) {
            return [['kolom' => "$kolom.nilai_baru", 'kode' => 'isian_tidak_valid', 'pesan' => 'Isi minimal satu perubahan.']];
        }
        $v = Validator::make($nilai, $aturan);
        $rincian = [];
        foreach ($v->errors()->messages() as $isian => $pesan) {
            $rincian[] = ['kolom' => "$kolom.$isian", 'kode' => 'isian_tidak_valid', 'pesan' => $pesan[0]];
        }
        if (isset($nilai['atribut_standar']) && is_array($nilai['atribut_standar'])) {
            foreach ($nilai['atribut_standar'] as $k => $n) {
                $sah = is_string($k) && preg_match('/^[a-z][a-z0-9_]{1,49}$/', $k) === 1
                    && (is_bool($n) || is_int($n) || is_float($n) || (is_string($n) && mb_strlen($n) <= 100));
                if (! $sah) {
                    $rincian[] = ['kolom' => "$kolom.atribut_standar", 'kode' => 'isian_tidak_valid', 'pesan' => 'Kunci snake_case dengan nilai ya/tidak, angka, atau teks pendek.'];
                    break;
                }
            }
        }

        return $rincian;
    }

    /**
     * Potret objek saat diusulkan: label, revisi, dan nilai lama isian yang diubah. Null bila objek tidak ada (atau, untuk
     * ruang, bukan lokasi jenis ruang).
     *
     * @param  array<string, mixed>  $butir
     * @return array{label: string, revisi: int|null, nilai_lama: array<string, mixed>|null}|null
     */
    public function potret(array $butir): ?array
    {
        $nilai = (array) ($butir['nilai_baru'] ?? []);
        if ($butir['aksi'] === 'tambah') {
            return ['label' => $this->labelTambah($butir['objek_jenis'], $nilai), 'revisi' => null, 'nilai_lama' => null];
        }
        $objek = $this->objek($butir['objek_jenis'], (string) $butir['objek_id']);
        if ($objek === null) {
            return null;
        }
        $isi = $this->isi($objek);
        $lama = $butir['aksi'] === 'tutup' ? ['status_tt' => 'aktif'] : array_intersect_key($isi, $nilai);

        return ['label' => $this->label($objek), 'revisi' => (int) $objek->getAttribute('revisi'), 'nilai_lama' => $lama];
    }

    /**
     * Terapkan satu butir lewat layanan 1.8b. Kembalikan id objek (baru untuk tambah).
     *
     * @param  array<string, mixed>  $butir
     */
    public function terapkan(Pengguna $penyunting, array $butir, string $berlakuDari, string $dasar): string
    {
        $nilai = (array) ($butir['nilai_baru'] ?? []);
        $versi = (string) ($butir['revisi_objek'] ?? '');
        $objek = $butir['aksi'] === 'tambah' ? null : ($this->objek($butir['objek_jenis'], (string) $butir['objek_id'])
            ?? throw new ApiError(404, 'objek_tidak_ditemukan', 'Objek tidak ditemukan lagi.'));

        return match ($butir['objek_jenis'].'.'.$butir['aksi']) {
            'tempat_tidur.tambah' => $this->tempatTidur->buat($penyunting, [...$nilai, 'berlaku_dari' => $berlakuDari, 'dasar_perubahan' => $dasar])[0]->id,
            'tempat_tidur.ubah' => $this->tempatTidur->ubah($penyunting, $this->tt($objek), $versi, [...$nilai, 'dasar_perubahan' => $dasar])->id,
            'tempat_tidur.tutup' => $this->tempatTidur->tutup($penyunting, $this->tt($objek), $versi, $berlakuDari, (string) $nilai['alasan'], $dasar)->id,
            'kamar.tambah' => $this->rawatInap->buatKamar($penyunting, [...$nilai, 'dasar_perubahan' => $dasar])->id,
            'ruang.tambah' => $this->lokasi->buat($penyunting, [...$nilai, 'jenis' => 'ruang', 'unit_organisasi_id' => $nilai['unit_organisasi_id'] ?? null,
                'dasar_perubahan' => $dasar])->id,
            'kamar.ubah' => $this->rawatInap->ubahKamar($penyunting, $objek instanceof Kamar ? $objek : throw new \LogicException, $versi, [...$nilai, 'dasar_perubahan' => $dasar])->id,
            // Status ruang berlaku pada tanggal usulan; saat penerapan tanggal itu ≤ hari ini.
            'ruang.ubah' => $this->lokasi->ubah($penyunting, $objek instanceof Lokasi ? $objek : throw new \LogicException, $versi, [
                ...$nilai, ...(isset($nilai['status']) ? ['status_berlaku_dari' => SuntinganBerversi::hariIni()] : []),
                'dasar_perubahan' => $dasar,
            ])->id,
            default => throw new \LogicException('Butir tidak dikenal.'),
        };
    }

    /**
     * Baris diff berlabel untuk layar (bukan isi kanonik TTE).
     *
     * @param  array<string, mixed>  $butir
     * @return list<array{isian: string, label_isian: string, lama: string|null, baru: string|null}>
     */
    public function tampilan(array $butir, string $berlakuDari): array
    {
        if ($butir['aksi'] === 'tutup') {
            return [['isian' => 'status_tt', 'label_isian' => 'Status', 'lama' => 'Aktif',
                'baru' => 'Ditutup mulai '.Carbon::parse($berlakuDari)->format('d-m-Y')]];
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
     * Baris diff dari dua isi versi (riwayat versi).
     *
     * @param  array<string, mixed>|null  $sebelum
     * @param  array<string, mixed>  $sesudah
     * @return list<array{isian: string, label_isian: string, lama: string|null, baru: string|null}>
     */
    public function tampilanVersi(?array $sebelum, array $sesudah): array
    {
        $baris = [];
        foreach ($sesudah as $isian => $baru) {
            if ($sebelum !== null && array_key_exists($isian, $sebelum) && $sebelum[$isian] === $baru) {
                continue;
            }
            $baris[] = ['isian' => (string) $isian, 'label_isian' => self::LABEL[$isian] ?? str_replace('_', ' ', ucfirst((string) $isian)),
                'lama' => $sebelum === null ? null : $this->teks((string) $isian, $sebelum[$isian] ?? null), 'baru' => $this->teks((string) $isian, $baru)];
        }

        return $baris;
    }

    /** Label objek untuk daftar/riwayat (dipotret saat diusulkan). */
    public function label(TempatTidur|Kamar|Lokasi $objek): string
    {
        return match (true) {
            $objek instanceof TempatTidur => 'TT '.$objek->kode,
            $objek instanceof Kamar => 'Kamar '.Lokasi::query()->whereKey($objek->lokasi_id)->value('kode_lengkap'),
            default => $objek->kode_lengkap.' · '.$objek->nama,
        };
    }

    public function objek(string $jenis, string $id): TempatTidur|Kamar|Lokasi|null
    {
        return match ($jenis) {
            'tempat_tidur' => TempatTidur::query()->find($id),
            'kamar' => Kamar::query()->find($id),
            'ruang' => Lokasi::query()->whereKey($id)->where('jenis', 'ruang')->first(),
            default => null,
        };
    }

    /** Bangsal tempat TT/kamar butir ini (ringkasan "ICU: 12 → 16 TT"). */
    public function bangsalId(array $butir): ?string
    {
        $nilai = (array) ($butir['nilai_baru'] ?? []);

        return match (true) {
            $butir['objek_jenis'] === 'tempat_tidur' && $butir['aksi'] === 'tambah' => Kamar::query()->whereKey($nilai['kamar_id'] ?? null)->value('bangsal_id'),
            $butir['objek_jenis'] === 'tempat_tidur' => Kamar::query()->whereKey(TempatTidur::query()->whereKey($butir['objek_id'])->value('kamar_id'))->value('bangsal_id'),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function isi(TempatTidur|Kamar|Lokasi $objek): array
    {
        return match (true) {
            $objek instanceof TempatTidur => $objek->only(['kamar_id', 'kode', 'kelas_rawat_id', 'aturan_jenis_kelamin', 'isolasi', 'ekstra']),
            $objek instanceof Kamar => $objek->only(['bangsal_id', 'lokasi_id', 'kapasitas_maksimum', 'atribut_standar']),
            default => $objek->only(['induk_id', 'kode', 'nama', 'fungsi', 'unit_organisasi_id', 'status']),
        };
    }

    /** @param  array<string, mixed>  $nilai */
    private function labelTambah(string $jenis, array $nilai): string
    {
        if ($jenis === 'tempat_tidur') {
            $ruang = Lokasi::query()->whereKey(Kamar::query()->whereKey($nilai['kamar_id'] ?? null)->value('lokasi_id'))->value('kode_lengkap');

            return 'TT baru '.($ruang !== null ? $ruang.'-' : '').($nilai['kode'] ?? '');
        }

        if ($jenis === 'ruang') {
            return 'Ruang baru '.(($induk = Lokasi::query()->whereKey($nilai['induk_id'] ?? null)->value('kode_lengkap')) !== null ? $induk.'-' : '')
                .($nilai['kode'] ?? '').' · '.($nilai['nama'] ?? '');
        }

        return 'Kamar baru '.(Lokasi::query()->whereKey($nilai['lokasi_id'] ?? null)->value('kode_lengkap') ?? '');
    }

    private function tt(TempatTidur|Kamar|Lokasi|null $objek): TempatTidur
    {
        return $objek instanceof TempatTidur ? $objek : throw new \LogicException('Bukan TT.');
    }

    /** Nilai siap tampil: id → nama data induknya, enum → label, boolean → Ya/Tidak. */
    private function teks(string $isian, mixed $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }

        return match ($isian) {
            'kelas_rawat_id' => KelasRawat::query()->whereKey($nilai)->value('nama') ?? (string) $nilai,
            'bangsal_id' => Bangsal::query()->whereKey($nilai)->value('nama') ?? (string) $nilai,
            'unit_organisasi_id' => UnitOrganisasi::query()->whereKey($nilai)->value('nama') ?? (string) $nilai,
            'kamar_id' => ($k = Kamar::query()->find($nilai)) === null ? (string) $nilai : $this->label($k),
            'lokasi_id', 'induk_id' => ($l = Lokasi::query()->find($nilai)) === null ? (string) $nilai : $l->kode_lengkap.' · '.$l->nama,
            'aturan_jenis_kelamin' => ['campur' => 'Campur', 'laki_laki' => 'Laki-laki', 'perempuan' => 'Perempuan'][$nilai] ?? (string) $nilai,
            'status', 'status_tt' => ['aktif' => 'Aktif', 'renovasi' => 'Renovasi', 'ditutup' => 'Ditutup'][$nilai] ?? (string) $nilai,
            'fungsi' => ucfirst(str_replace('_', ' ', (string) $nilai)),
            default => match (true) {
                is_bool($nilai) => $nilai ? 'Ya' : 'Tidak',
                is_array($nilai) => implode(', ', array_map(fn ($k, $v) => $k.': '.(is_bool($v) ? ($v ? 'ya' : 'tidak') : $v), array_keys($nilai), $nilai)) ?: '—',
                default => (string) $nilai,
            },
        };
    }
}
