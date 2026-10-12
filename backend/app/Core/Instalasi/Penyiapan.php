<?php

namespace App\Core\Instalasi;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Database\MenuBawaan;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Wizard penyiapan instalasi kosong (tiket 1.8f, FR-54-08, kontrak v0.12.0). Status tiap langkah dihitung dari data
 * yang diisi di layar modul masing-masing, lalu dikonfirmasi penyiap (riwayat di inti.penyiapan_konfirmasi, diaudit).
 * `siap_melayani` terkunci di inti.profil_rs.siap_melayani_sejak sejak langkah 1–6 pertama kali selesai.
 */
final class Penyiapan
{
    /** Kode langkah => judul; urutan = urutan wajib FR-54-08. */
    public const LANGKAH = [
        'superadmin' => 'Dua superadmin dengan MFA',
        'profil_rs' => 'Profil RS dan paket identitas',
        'organisasi' => 'Unit, KSM, poliklinik, bangsal, kamar, TT, kelas rawat',
        'paket_referensi' => 'Paket data referensi',
        'pegawai' => 'Pegawai dan izin praktik',
        'penjamin_tarif' => 'Penjamin dan tarif',
        'formularium' => 'Formularium',
        'kredensial_integrasi' => 'Kredensial integrasi',
        'modul_aktif' => 'Modul aktif',
    ];

    /** Langkah 6–7: kerangka saja di fase 1 (K6). */
    private const FASE_2 = ['penjamin_tarif', 'formularium'];

    private const BISA_DILEWATI = 'kredensial_integrasi';

    /** Langkah 1–6 wajib sebelum melayani pasien. */
    private const JUMLAH_WAJIB_MELAYANI = 6;

    /** Sistem paket langkah 4 (urutan SistemReferensi) => wajib; ICD dari berkas RS, tidak wajib (K7). */
    private const PAKET = ['wilayah' => true, 'referensi' => true, 'icd10' => false, 'icd9cm' => false, 'loinc' => true, 'ucum' => true];

    private const LABEL_PAKET = ['wilayah' => 'Wilayah', 'referensi' => 'Kode variabel RME', 'loinc' => 'LOINC', 'ucum' => 'UCUM'];

    private const SELESAI_URUTAN = ['selesai', 'dilewati', 'menunggu_fase_2'];

    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly RingkasanPegawai $pegawai,
    ) {}

    /** @return array<string, mixed> StatusPenyiapan untuk penonton (dapat_* sesuai izinnya) */
    public function status(Pengguna $penonton): array
    {
        $bolehUbah = Gate::forUser($penonton)->allows('pengaturan.penyiapan.perbaiki', Cakupan::rs());
        $langkah = $this->hitung();
        $urutanTerpenuhi = true;
        $hasil = [];
        $no = 0;
        foreach ($langkah as $kode => $l) {
            $hasil[] = [
                'no' => ++$no,
                'kode' => $kode,
                'judul' => self::LANGKAH[$kode],
                'status' => $l['status'],
                'wajib_untuk_melayani' => $no <= self::JUMLAH_WAJIB_MELAYANI,
                'syarat' => $l['syarat'],
                'dapat_dikonfirmasi' => $bolehUbah && $urutanTerpenuhi && ! in_array($kode, self::FASE_2, true) && self::semuaTerpenuhi($l['syarat']),
                'dapat_dilewati' => $bolehUbah && $urutanTerpenuhi && $kode === self::BISA_DILEWATI && $l['status'] !== 'selesai',
                'konfirmasi_terakhir' => $l['konfirmasi'],
                'paket' => $l['paket'],
            ];
            $urutanTerpenuhi = $urutanTerpenuhi && in_array($l['status'], self::SELESAI_URUTAN, true);
        }
        $sejak = DB::table('inti.profil_rs')->value('siap_melayani_sejak');

        return [
            'status' => self::ringkasan(array_column($langkah, 'status')),
            'siap_melayani' => $sejak !== null,
            'siap_melayani_sejak' => $sejak === null ? null : Carbon::parse($sejak)->toIso8601String(),
            'langkah' => $hasil,
        ];
    }

    /**
     * Konfirmasi satu langkah (atau langkah 8 dilewati); syarat dan urutan diperiksa ulang di dalam kunci.
     *
     * @return array<string, mixed> StatusPenyiapan sesudahnya
     */
    public function konfirmasi(Pengguna $pengguna, string $kode, bool $dilewati, ?string $alasan): array
    {
        DB::transaction(function () use ($pengguna, $kode, $dilewati, $alasan) {
            // Konfirmasi diserialkan agar kunci siap melayani dihitung dari keadaan yang sama.
            DB::select("SELECT pg_advisory_xact_lock(hashtext('inti.penyiapan'))");
            $langkah = $this->hitung();
            $ini = $langkah[$kode];

            if ($dilewati && ($kode !== self::BISA_DILEWATI || $ini['status'] === 'selesai')) {
                throw new ApiError(422, 'tidak_bisa_dilewati', 'Hanya langkah kredensial integrasi yang belum selesai yang bisa dilewati.');
            }
            if (in_array($kode, self::FASE_2, true)) {
                throw new ApiError(409, 'langkah_menunggu_fase_2', 'Langkah ini diisi di fase 2 (penjamin, tarif, formularium).');
            }
            $belum = [];
            foreach ($langkah as $k => $l) {
                if ($k === $kode) {
                    break;
                }
                if (! in_array($l['status'], self::SELESAI_URUTAN, true)) {
                    $belum[] = ['langkah' => $k, 'status' => $l['status']];
                }
            }
            if ($belum !== []) {
                throw new ApiError(409, 'langkah_sebelumnya_belum_selesai', 'Selesaikan langkah sebelumnya lebih dulu.', $belum);
            }
            $kurang = array_values(array_map(
                fn (array $s) => ['kode' => $s['kode'], 'label' => $s['label'], 'keterangan' => $s['keterangan']],
                array_filter($ini['syarat'], fn (array $s) => ! $s['terpenuhi']),
            ));
            if (! $dilewati && $kurang !== []) {
                throw new ApiError(409, 'syarat_belum_lengkap', 'Syarat langkah ini belum lengkap.', $kurang);
            }

            $id = (string) Str::uuid7();
            DB::table('inti.penyiapan_konfirmasi')->insert([
                'id' => $id, 'langkah' => $kode, 'dilewati' => $dilewati, 'alasan' => $dilewati ? $alasan : null,
                'dikonfirmasi_oleh' => $pengguna->id, 'dikonfirmasi_pada' => now()->format('Y-m-d H:i:s.uP'),
            ]);
            if ($kode === 'profil_rs') {
                // Ringkasan langkah 2 di profil (ProfilRs.rinci.status_penyiapan, 1.8a).
                // revisi naik agar versi/ETag GET /profil-rs ikut berubah.
                DB::table('inti.profil_rs')->update(['status_penyiapan' => 'selesai', 'revisi' => DB::raw('revisi + 1'), 'diubah_pada' => now()]);
            }

            $kunciBaru = false;
            $sesudah = $this->hitung();
            $wajib = array_slice(array_column($sesudah, 'status'), 0, self::JUMLAH_WAJIB_MELAYANI);
            if (array_unique($wajib) === ['selesai'] && DB::table('inti.profil_rs')->whereNull('siap_melayani_sejak')->exists()) {
                DB::table('inti.profil_rs')->update(['siap_melayani_sejak' => now()->format('Y-m-d H:i:s.uP')]);
                $kunciBaru = true;
            }

            // Audit terakhir di transaksi: kunci rantai dipegang sampai commit (ADR 0003).
            $this->audit->catat('pengaturan.penyiapan_dikonfirmasi', $pengguna->id, 'penyiapan_konfirmasi', $id, [
                'langkah' => $kode, 'dilewati' => $dilewati, 'alasan' => $dilewati ? $alasan : null, 'siap_melayani_terkunci' => $kunciBaru,
            ]);
        });

        return $this->status($pengguna);
    }

    /** Penjaga siap melayani (StatusInstalasi). */
    public function siapMelayani(): bool
    {
        return DB::table('inti.profil_rs')->whereNotNull('siap_melayani_sejak')->exists();
    }

    /**
     * @return array<string, array{status: string, syarat: list<array{kode: string, label: string, terpenuhi: bool, keterangan: ?string}>, konfirmasi: ?array<string, mixed>, paket: ?list<array<string, mixed>>}>
     */
    private function hitung(): array
    {
        $konfirmasi = $this->konfirmasiTerakhir();
        // Dihitung sekali per permintaan: hitungan kode aktif menyentuh tabel besar (wilayah, terminologi).
        $paket = $this->paket();
        $hasil = [];
        foreach (array_keys(self::LANGKAH) as $kode) {
            $syarat = match ($kode) {
                'superadmin' => $this->syaratSuperadmin(),
                'profil_rs' => $this->syaratProfil(),
                'organisasi' => $this->syaratOrganisasi(),
                'paket_referensi' => $this->syaratPaket($paket),
                'pegawai' => $this->syaratPegawai(),
                'kredensial_integrasi' => [self::syarat('kredensial', 'Kredensial tersimpan dan uji koneksi berhasil', false, 'Dibangun di tiket 1.9')],
                'modul_aktif' => $this->syaratModul(),
                default => [],
            };
            $k = $konfirmasi[$kode] ?? null;
            $status = match (true) {
                in_array($kode, self::FASE_2, true) => 'menunggu_fase_2',
                $k !== null && $k['dilewati'] => 'dilewati',
                $k !== null && self::semuaTerpenuhi($syarat) => 'selesai',
                $k !== null => 'sebagian',
                self::adaTerpenuhi($syarat) => 'sebagian',
                default => 'belum',
            };
            $hasil[$kode] = ['status' => $status, 'syarat' => $syarat, 'konfirmasi' => $k, 'paket' => $kode === 'paket_referensi' ? $paket : null];
        }

        return $hasil;
    }

    /** @return array<string, array<string, mixed>> konfirmasi terbaru per langkah */
    private function konfirmasiTerakhir(): array
    {
        $baris = DB::select(
            'SELECT DISTINCT ON (langkah) langkah, dilewati, alasan, dikonfirmasi_pada, dikonfirmasi_oleh
             FROM inti.penyiapan_konfirmasi ORDER BY langkah, dikonfirmasi_pada DESC, id DESC'
        );
        $akun = Pengguna::query()->whereIn('id', array_column($baris, 'dikonfirmasi_oleh'))->get(['id', 'pegawai_id', 'nama_pengguna'])->keyBy('id');
        $nama = $this->pegawai->nama($akun->pluck('pegawai_id')->filter()->values()->all());
        $hasil = [];
        foreach ($baris as $b) {
            $a = $akun[$b->dikonfirmasi_oleh];
            $hasil[$b->langkah] = [
                'oleh' => ['id' => $a->id, 'nama' => $nama[$a->pegawai_id] ?? $a->nama_pengguna],
                'pada' => Carbon::parse($b->dikonfirmasi_pada)->toIso8601String(),
                'dilewati' => (bool) $b->dilewati,
                'alasan' => $b->alasan,
            ];
        }

        return $hasil;
    }

    /** @return list<array{kode: string, label: string, terpenuhi: bool, keterangan: ?string}> */
    private function syaratSuperadmin(): array
    {
        $peranId = DB::table('akses.peran')->where('kode', MenuBawaan::PERAN_SUPERADMIN)->value('id');
        $pemegang = PenggunaPeran::query()->berlaku()->where('peran_id', $peranId)->distinct()->pluck('pengguna_id');
        $n = DB::table('akses.pengguna')->whereIn('id', $pemegang)->where('status', 'aktif')
            ->whereNotNull('mfa_aktif_pada')->whereNotNull('mfa_rahasia_enkripsi')->count();

        return [
            self::syarat('superadmin_mfa', 'Dua superadmin dengan MFA', $n >= 2, $n >= 2 ? "{$n} superadmin" : "{$n} dari 2"),
            self::syarat('jaringan_rs', 'Jaringan RS terisi di server', config('autentikasi.mfa.jaringan_rs', []) !== [], null),
        ];
    }

    /** @return list<array{kode: string, label: string, terpenuhi: bool, keterangan: ?string}> */
    private function syaratProfil(): array
    {
        $profil = DB::table('inti.profil_rs')->first(['nama', 'paket_identitas']);
        $identitas = $profil === null ? [] : (array) json_decode((string) $profil->paket_identitas, true);

        return [
            self::syarat('profil', 'Nama RS tersimpan', $profil?->nama !== null, null),
            self::syarat('logo', 'Logo paket identitas terunggah', isset($identitas['logo']), null),
        ];
    }

    /** @return list<array{kode: string, label: string, terpenuhi: bool, keterangan: ?string}> */
    private function syaratOrganisasi(): array
    {
        $hariIni = self::hariIni();
        $jumlah = [
            'unit' => ['Unit organisasi', DB::table('inti.unit_organisasi')->where('status', 'aktif')->count()],
            'ksm' => ['KSM', DB::table('inti.ksm')->where('status', 'aktif')->count()],
            'kelas_rawat' => ['Kelas rawat', DB::table('inti.kelas_rawat')->where('status', 'aktif')->count()],
            'bangsal' => ['Bangsal', DB::table('inti.bangsal')->where('status', 'aktif')->count()],
            'tempat_tidur' => ['Tempat tidur', DB::table('inti.tempat_tidur')->where('berlaku_dari', '<=', $hariIni)
                ->where(fn ($q) => $q->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', $hariIni))->count()],
            'poliklinik' => ['Poliklinik', DB::table('inti.poliklinik')->where('status', 'aktif')->count()],
        ];

        return array_map(
            fn (string $kode, array $j) => self::syarat($kode, $j[0], $j[1] > 0, $j[1] > 0 ? "{$j[1]} aktif" : 'Belum ada yang aktif'),
            array_keys($jumlah), $jumlah,
        );
    }

    /**
     * @param  list<array{sistem: string, wajib: bool, versi: ?string, sumber: ?string, dimuat_pada: ?string, jumlah_kode_aktif: int}>  $daftar
     * @return list<array{kode: string, label: string, terpenuhi: bool, keterangan: ?string}>
     */
    private function syaratPaket(array $daftar): array
    {
        $paket = collect($daftar)->keyBy('sistem');

        return array_map(
            fn (string $sistem) => self::syarat($sistem, self::LABEL_PAKET[$sistem], $paket[$sistem]['versi'] !== null,
                $paket[$sistem]['versi'] !== null ? "Versi {$paket[$sistem]['versi']}" : 'Belum dimuat'),
            array_keys(array_filter(self::PAKET)),
        );
    }

    /** @return list<array{sistem: string, wajib: bool, versi: ?string, sumber: ?string, dimuat_pada: ?string, jumlah_kode_aktif: int}> */
    private function paket(): array
    {
        $terakhir = collect(DB::select(
            'SELECT DISTINCT ON (sistem) sistem, versi, sumber, dimuat_pada FROM inti.paket_referensi ORDER BY sistem, dimuat_pada DESC, id DESC'
        ))->keyBy('sistem');
        $terminologi = DB::table('inti.terminologi')->where('aktif', true)->selectRaw('sistem, count(*) AS n')->groupBy('sistem')->pluck('n', 'sistem');
        $hasil = [];
        foreach (self::PAKET as $sistem => $wajib) {
            $p = $terakhir[$sistem] ?? null;
            $hasil[] = [
                'sistem' => $sistem,
                'wajib' => $wajib,
                'versi' => $p?->versi,
                'sumber' => $p?->sumber,
                'dimuat_pada' => $p === null ? null : Carbon::parse($p->dimuat_pada)->toIso8601String(),
                'jumlah_kode_aktif' => (int) match ($sistem) {
                    'wilayah' => DB::table('inti.wilayah')->where('aktif', true)->count(),
                    'referensi' => DB::table('inti.referensi')->where('aktif', true)->count(),
                    default => $terminologi[$sistem] ?? 0,
                },
            ];
        }

        return $hasil;
    }

    /** @return list<array{kode: string, label: string, terpenuhi: bool, keterangan: ?string}> */
    private function syaratPegawai(): array
    {
        $hariIni = self::hariIni();
        $pegawaiAktif = fn ($q) => $q->where('status', 'aktif')->where(fn ($q) => $q->whereNull('tanggal_keluar')->orWhere('tanggal_keluar', '>', $hariIni));
        $pegawai = DB::table('sdm.pegawai')->where($pegawaiAktif)->count();
        $izin = DB::table('sdm.izin_praktik')->where('status', 'aktif')
            ->where(fn ($q) => $q->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', $hariIni))
            ->whereIn('pegawai_id', DB::table('sdm.pegawai')->where($pegawaiAktif)->select('id'))->count();

        return [
            self::syarat('pegawai', 'Pegawai aktif', $pegawai > 0, $pegawai > 0 ? "{$pegawai} pegawai" : 'Belum ada'),
            self::syarat('izin_praktik', 'Izin praktik yang berlaku', $izin > 0, $izin > 0 ? "{$izin} izin" : 'Belum ada'),
        ];
    }

    /** @return list<array{kode: string, label: string, terpenuhi: bool, keterangan: ?string}> */
    private function syaratModul(): array
    {
        $n = DB::table('inti.modul_aktif')->whereNull('unit_organisasi_id')->count();

        return [self::syarat('modul', 'Modul aktif untuk seluruh RS', $n > 0, "{$n} modul")];
    }

    /** @return array{kode: string, label: string, terpenuhi: bool, keterangan: ?string} */
    private static function syarat(string $kode, string $label, bool $terpenuhi, ?string $keterangan): array
    {
        return ['kode' => $kode, 'label' => $label, 'terpenuhi' => $terpenuhi, 'keterangan' => $keterangan];
    }

    /** @param list<array{terpenuhi: bool}> $syarat */
    private static function semuaTerpenuhi(array $syarat): bool
    {
        return $syarat !== [] && array_filter($syarat, fn (array $s) => ! $s['terpenuhi']) === [];
    }

    /** @param list<array{terpenuhi: bool}> $syarat */
    private static function adaTerpenuhi(array $syarat): bool
    {
        return array_filter($syarat, fn (array $s) => $s['terpenuhi']) !== [];
    }

    /** @param list<string> $status */
    private static function ringkasan(array $status): string
    {
        $tuntas = array_filter($status, fn (string $s) => in_array($s, ['selesai', 'dilewati'], true));
        if ($tuntas === []) {
            return 'belum';
        }

        return array_filter($status, fn (string $s) => ! in_array($s, self::SELESAI_URUTAN, true)) === [] ? 'selesai' : 'sebagian';
    }

    private static function hariIni(): string
    {
        return now(config('instalasi.zona_waktu'))->toDateString();
    }
}
