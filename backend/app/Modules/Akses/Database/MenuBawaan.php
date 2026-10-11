<?php

namespace App\Modules\Akses\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menu bawaan produk (PRD 9.1) dari sidebar prototipe docs/html: grup → menu → sub-menu. Nomor
 * modul dari 9.1 (status aktif mengikuti inti.modul_aktif), fase dari D.3 dan 16.
 *
 * Dipasang lewat migrasi dan aman diulang (dicocokkan per kode). Menambah menu atau menandai
 * halaman baru TERSEDIA = ubah kelas ini lalu buat migrasi baru yang memanggil pasang(). Kolom
 * `aktif` (dinonaktifkan admin) tidak pernah ditimpa.
 */
final class MenuBawaan
{
    public const PERAN_SUPERADMIN = 'superadmin';

    /** Halaman yang sudah dibangun; menu lain tampil sebagai Segera hadir atau disembunyikan. */
    public const TERSEDIA = ['beranda', 'data_induk.peran', 'data_induk.akun'];

    /** Aksi dasar Permenkes 24/2022 Ps. 30 ayat 3 untuk setiap sub-menu (FR-09-03). */
    public const AKSI_DASAR = ['lihat', 'input', 'perbaiki'];

    /** Superadmin mengelola peran dan akun; tidak membuka rekam medis atau rupiah (PRD 9.2.4). */
    public const SUB_MENU_SUPERADMIN = ['data_induk.peran', 'data_induk.akun'];

    /**
     * kode grup => [label, menu]; menu: kode => [label, fase, modul, sub-menu];
     * sub-menu: kode => [label, modul] atau [label, modul, fase] bila fasenya berbeda dari menunya.
     *
     * @var array<string, array{0: string|null, 1: array<string, array{0: string, 1: int, 2: int, 3: array<string, array{0: string, 1: int, 2?: int}>}>}>
     */
    public const POHON = [
        'utama' => [null, [
            'beranda' => ['Beranda', 1, 53, []],
        ]],
        'pelayanan' => ['Pelayanan', [
            'pendaftaran' => ['Pendaftaran', 2, 1, [
                'pasien' => ['Pendaftaran pasien', 1],
                'admisi' => ['Admisi rawat inap', 1],
                'antrean' => ['Antrean & display', 2],
                'sep' => ['SEP & surat kontrol', 50],
                'master_pasien' => ['Master pasien', 1],
                'persetujuan_umum' => ['Persetujuan umum', 57],
            ]],
            'igd' => ['IGD', 2, 3, [
                'papan' => ['Papan pasien IGD', 3],
                'triase' => ['Triase', 3],
                'cppt' => ['CPPT episode', 26],
                'rujukan' => ['Rujukan SISRUTE', 4],
            ]],
            'rawat_jalan' => ['Rawat Jalan', 2, 7, [
                'worklist' => ['Worklist poliklinik', 7],
                'pemeriksaan' => ['Pemeriksaan', 7],
                'cppt' => ['CPPT episode', 26],
                'asesmen_perawat' => ['Asesmen perawat', 7],
                'supervisi' => ['Supervisi peserta didik', 46],
                'jadwal' => ['Jadwal praktik', 7],
            ]],
            'rawat_inap' => ['Rawat Inap', 2, 8, [
                'peta_tempat_tidur' => ['Peta tempat tidur', 8],
                'pasien' => ['Pasien ruangan', 8],
                'cppt' => ['CPPT & instruksi', 26],
                'pemberian_obat' => ['Pemberian obat', 8],
                'asesmen' => ['Asesmen & EWS', 8],
                'pemulangan' => ['Pemulangan', 5],
            ]],
            'kamar_operasi' => ['Kamar Operasi', 3, 10, [
                'jadwal' => ['Jadwal kamar operasi', 10],
                'ceklis' => ['Ceklis keselamatan', 10],
                'pengajuan' => ['Ajukan jadwal', 10],
                'anestesi' => ['Anestesi & pemulihan', 11],
                'cppt' => ['CPPT episode', 26],
                'laporan' => ['Laporan operasi', 10],
            ]],
            'penunjang' => ['Penunjang', 2, 19, [
                'laboratorium' => ['Laboratorium', 19],
                'radiologi' => ['Radiologi', 20],
                'patologi_anatomi' => ['Patologi anatomi', 18, 3],
                'bank_darah' => ['Bank darah', 17, 3],
            ]],
            'farmasi' => ['Farmasi', 2, 22, [
                'resep' => ['Antrean & telaah resep', 22],
                'klinis' => ['Farmasi klinis', 22],
                'stok' => ['Stok depo', 22],
                'narkotika' => ['Narkotika & psikotropika', 22],
                'formularium' => ['Formularium', 22],
            ]],
            'rekam_medis' => ['Rekam Medis', 2, 26, [
                'pencarian' => ['Pencarian & riwayat RM', 26],
                'kelengkapan' => ['Kelengkapan RM', 26],
                'pelepasan' => ['Pelepasan informasi & akses', 26],
                'arsip' => ['Alih media & retensi', 26],
            ]],
        ]],
        'pendapatan' => ['Pendapatan', [
            'kasir' => ['Kasir', 2, 28, [
                'tagihan' => ['Tagihan pasien', 28],
                'deposit' => ['Deposit & refund', 28],
                'tutup' => ['Tutup kasir', 28],
            ]],
            'casemix_klaim' => ['Casemix dan Klaim', 3, 27, [
                'koding' => ['Koding', 27],
                'berkas' => ['Berkas klaim', 27],
                'verifikasi' => ['Hasil verifikasi', 27],
                'piutang' => ['Piutang & rekonsiliasi', 29],
                'simulasi' => ['Simulasi selama rawat', 27],
            ]],
        ]],
        'tata_kelola' => ['Tata Kelola', [
            'mutu' => ['Mutu dan Keselamatan', 4, 32, [
                'indikator' => ['Indikator mutu', 32],
                'insiden' => ['Insiden keselamatan', 33],
                'ppi' => ['PPI', 34],
                'keluhan' => ['Keluhan & kepuasan', 37],
            ]],
            'sdm' => ['SDM', 4, 39, [
                'jadwal_dinas' => ['Jadwal dinas', 39],
                'pegawai' => ['Data pegawai', 39],
                'kredensial' => ['Izin & kredensial', 40],
                'cuti' => ['Cuti & presensi', 39],
            ]],
            'logistik' => ['Logistik', 4, 42, [
                'gudang' => ['Gudang & persediaan', 42],
                'pengadaan' => ['Pengadaan', 42],
                'aset' => ['Aset tetap', 42],
                'pemeliharaan' => ['Pemeliharaan & kalibrasi', 43],
            ]],
            'penelitian' => ['Penelitian', 5, 47, [
                'protokol' => ['Protokol & Komite Etik', 47],
                'uji_klinik' => ['Uji klinik', 47],
                'permintaan_data' => ['Permintaan data riset', 47],
                'registri' => ['Registri klinis', 47],
                'luaran' => ['Luaran penelitian', 47],
            ]],
            'pendidikan' => ['Pendidikan Klinis', 5, 46, [
                'peserta_didik' => ['Peserta didik', 46],
                'rotasi' => ['Rotasi', 46],
                'supervisi' => ['Supervisi', 46],
            ]],
            'integrasi' => ['Integrasi dan Laporan', 2, 49, [
                'satusehat' => ['SATUSEHAT', 49],
                'bpjs' => ['Bridging BPJS', 50],
                'laporan_wajib' => ['Laporan wajib', 51, 4],
                'kepatuhan' => ['Kepatuhan pelaporan', 51, 4],
                'laporan_kustom' => ['Laporan kustom', 52, 4],
            ]],
            'dasbor' => ['Dasbor', 4, 52, [
                'ringkasan_direksi' => ['Ringkasan direksi', 52],
                'keuangan' => ['Keuangan', 52],
                'pendapatan_penjamin' => ['Pendapatan per penjamin', 52],
                'pelayanan' => ['Pelayanan', 52],
                'mutu' => ['Mutu dan keselamatan', 52],
                'pelaporan_wajib' => ['Pelaporan wajib', 52],
                'penelitian' => ['Penelitian', 52, 5],
                'unit' => ['Dasbor unit', 52],
            ]],
            'data_induk' => ['Master Data', 1, 54, [
                'katalog' => ['Data induk', 54],
                'usulan' => ['Usulan perubahan', 54],
                'peran' => ['Peran & menu', 54],
                'akun' => ['Akun & akses', 54],
                'audit' => ['Jejak audit', 54],
            ]],
            'pengaturan' => ['Pengaturan Sistem', 1, 54, [
                'menu' => ['Pengaturan menu', 54],
                'integrasi' => ['Konfigurasi integrasi', 54],
                'profil_rs' => ['Profil rumah sakit', 54],
            ]],
        ]],
    ];

    public static function pasang(): void
    {
        $urutanGrup = 0;
        foreach (self::POHON as $kodeGrup => [$labelGrup, $daftarMenu]) {
            $idGrup = self::simpanMenu($kodeGrup, null, 'grup', ['label' => $labelGrup, 'urutan' => ++$urutanGrup]);

            $urutanMenu = 0;
            foreach ($daftarMenu as $kodeMenu => [$label, $fase, $modul, $daftarSub]) {
                $rute = $kodeMenu === 'beranda' ? '/' : '/'.Str::replace('_', '-', $kodeMenu);
                $idMenu = self::simpanMenu($kodeMenu, $idGrup, 'menu', [
                    'label' => $label, 'ikon' => $kodeMenu, 'rute' => $rute, 'modul_no' => $modul, 'fase' => $fase,
                    'urutan' => ++$urutanMenu, 'tersedia' => in_array($kodeMenu, self::TERSEDIA, true),
                    'selalu_tampil' => $kodeMenu === 'beranda',
                ]);

                $urutanSub = 0;
                foreach ($daftarSub as $kodeSub => $sub) {
                    $kode = "$kodeMenu.$kodeSub";
                    $idSub = self::simpanMenu($kode, $idMenu, 'sub_menu', [
                        'label' => $sub[0], 'rute' => $rute.'/'.Str::replace('_', '-', $kodeSub), 'modul_no' => $sub[1],
                        'fase' => $sub[2] ?? $fase, 'urutan' => ++$urutanSub, 'tersedia' => in_array($kode, self::TERSEDIA, true),
                    ]);
                    foreach (self::AKSI_DASAR as $aksi) {
                        self::simpanIzin("$kode.$aksi", $idSub, $aksi);
                    }
                }
            }
        }

        self::pasangSuperadmin();
    }

    /** @param  array<string, mixed>  $isi */
    private static function simpanMenu(string $kode, ?string $induk, string $tingkat, array $isi): string
    {
        $isi = ['induk_id' => $induk, 'tingkat' => $tingkat, ...$isi];
        $id = DB::table('akses.menu')->where('kode', $kode)->value('id');
        if ($id === null) {
            $id = (string) Str::uuid7();
            DB::table('akses.menu')->insert(['id' => $id, 'kode' => $kode, ...$isi]);
        } else {
            DB::table('akses.menu')->where('id', $id)->update([...$isi, 'diubah_pada' => now()]);
        }

        return $id;
    }

    private static function simpanIzin(string $kode, string $menuId, string $aksi): void
    {
        DB::table('akses.izin')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'kode' => $kode, 'menu_id' => $menuId, 'jenis_aksi' => $aksi,
        ]);
    }

    private static function pasangSuperadmin(): void
    {
        DB::table('akses.peran')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'kode' => self::PERAN_SUPERADMIN,
            'nama' => 'Superadmin',
            'uraian' => 'Mengelola menu, peran, dan akun. Tidak membuka rekam medis atau angka rupiah (PRD 9.2.4).',
            'bawaan_sistem' => true,
            'wajib_mfa' => true,
        ]);
        $peranId = DB::table('akses.peran')->where('kode', self::PERAN_SUPERADMIN)->value('id');

        foreach (DB::table('akses.menu')->whereIn('kode', self::SUB_MENU_SUPERADMIN)->pluck('id') as $menuId) {
            DB::table('akses.peran_menu')->insertOrIgnore(['id' => (string) Str::uuid7(), 'peran_id' => $peranId, 'menu_id' => $menuId]);
            foreach (DB::table('akses.izin')->where('menu_id', $menuId)->pluck('id') as $izinId) {
                DB::table('akses.peran_izin')->insertOrIgnore(['id' => (string) Str::uuid7(), 'peran_id' => $peranId, 'izin_id' => $izinId]);
            }
        }
    }
}
