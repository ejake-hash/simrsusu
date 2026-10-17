<?php

namespace App\Modules\Akses\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Menu bawaan produk (PRD 9.1) dari sidebar prototipe HTML (di luar repo): grup → menu → sub-menu. Nomor
 * modul dari 9.1 (status aktif mengikuti inti.modul_aktif), fase dari D.3 dan 16.
 *
 * Dipasang lewat migrasi dan aman diulang (dicocokkan per kode). Menambah menu atau menandai
 * halaman baru TERSEDIA = ubah kelas ini lalu buat migrasi baru yang memanggil pasang(). Kolom
 * `aktif` (dinonaktifkan admin) tidak pernah ditimpa.
 */
final class MenuBawaan
{
    public const PERAN_SUPERADMIN = 'superadmin';

    /**
     * Peran contoh penyiap instalasi (tiket 1.8f): konfirmasi langkah wizard dan memuat paket referensi (langkah 4,
     * `master_data.katalog.input`). Superadmin tidak memegang data induk (PRD 9.2.4), jadi RS menugaskan peran ini (dan
     * menambah izin data induk lain bila perlu). Hanya dibuat sekali; tidak terkunci, tidak ditimpa pasang() berikutnya.
     */
    public const PERAN_PENYIAP = 'penyiap_instalasi';

    public const IZIN_PENYIAP = ['pengaturan.penyiapan.lihat', 'pengaturan.penyiapan.perbaiki', 'master_data.katalog.lihat', 'master_data.katalog.input'];

    /** Halaman yang sudah dibangun; menu lain tampil sebagai Segera hadir atau disembunyikan. */
    public const TERSEDIA = ['beranda', 'pengaturan.peran', 'pengaturan.akun', 'pengaturan.persetujuan', 'pengaturan.profil_rs', 'pengaturan.keamanan', 'master_data.organisasi', 'master_data.jenis_tugas', 'master_data.usulan', 'pengaturan.penyiapan', 'pengaturan.integrasi', 'integrasi.satusehat', 'integrasi.bpjs', 'integrasi.log', 'sdm.pegawai'];

    /** Aksi dasar Permenkes 24/2022 Ps. 30 ayat 3 untuk setiap sub-menu (FR-09-03). */
    public const AKSI_DASAR = ['lihat', 'input', 'perbaiki'];

    /** Aksi selain aksi dasar per sub-menu (PRD 9.2 JenisAksi): mis. alur usulan data induk berjenjang (tiket 1.8c). */
    public const AKSI_TAMBAHAN = [
        'master_data.usulan' => ['verifikasi', 'setujui'],
    ];

    /** Superadmin mengelola peran, akun, dan profil RS (wizard langkah 2); tidak membuka rekam medis, rupiah, atau data induk milik unit (PRD 9.2.4, FR-54-01). */
    public const SUB_MENU_SUPERADMIN = ['pengaturan.peran', 'pengaturan.akun', 'pengaturan.persetujuan', 'pengaturan.profil_rs', 'pengaturan.penyiapan', 'pengaturan.keamanan'];

    /**
     * Izin yang setara kuasa superadmin (membuat/mengubah peran, memberi akses): sensitif, jadi tidak bisa
     * dibagikan lewat peran biasa atau per akun tanpa persetujuan (tiket 1.2d) dan pemegangnya wajib MFA
     * (PRD 9.2.4, 10.3.2). pasang() hanya menyalakan tanda ini, tidak pernah mematikannya.
     */
    public const IZIN_SENSITIF = [
        'pengaturan.peran.input', 'pengaturan.peran.perbaiki', 'pengaturan.akun.input', 'pengaturan.akun.perbaiki',
        // Tiket 1.9: menukar kredensial integrasi mengalihkan kiriman data pasien ke identitas faskes lain.
        'pengaturan.integrasi.perbaiki',
        // Tiket 1.13: menaikkan masa percaya perangkat melonggarkan frekuensi MFA seluruh RS.
        'pengaturan.keamanan.perbaiki',
    ];

    /**
     * Izin yang selalu meminta MFA ulang di sesi berjalan (K11, lapis 5). pasang() hanya menyalakan tanda ini; RS boleh
     * menambah izin lain dari data, tidak mengurangi yang ada di sini.
     */
    public const IZIN_BUTUH_MFA = ['pengaturan.keamanan.perbaiki'];

    /**
     * Bagian halaman (PRD 9.1, 9.2.1) per sub-menu: kode bagian => [label, aksi]. Data bagian tanpa izin tidak dikirim
     * server. Tidak ikut induk: diberikan tersendiri.
     */
    public const BAGIAN = [
        // NIK pegawai utuh (tiket 1.8d); tanpa izin ini hanya NIK tersamar.
        'sdm.pegawai' => ['identitas' => ['Identitas pribadi (NIK)', ['lihat']]],
    ];

    /**
     * Izin data pribadi yang sensitif tanpa penyetuju bawaan: tidak bisa diberikan sampai RS menetapkan pemilik datanya
     * (K3, PUT /izin/{kode}/penyetuju). pasang() hanya menyalakan tanda sensitif, tidak pernah mematikannya.
     */
    public const IZIN_DATA_PRIBADI = ['sdm.pegawai.identitas.lihat'];

    /**
     * Kode yang pindah tempat (lama => baru), diganti di tempat sebelum pohon dipasang agar id, peran,
     * dan pengecualian per akun ikut menunjuk kode baru. Hanya bila kode baru belum ada; aman diulang.
     * Tiket 1.2c: peran, akun, dan jejak audit pindah dari Master Data ke Pengaturan Sistem (PRD 9.1).
     */
    public const KODE_PINDAH = [
        'master_data.peran' => 'pengaturan.peran',
        'master_data.akun' => 'pengaturan.akun',
        'master_data.audit' => 'pengaturan.audit',
    ];

    /**
     * Kode menu = kode prototipe, juga nama ikon di frontend/src/design-system/ikon/ikon.ts.
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
            'casemix' => ['Casemix dan Klaim', 3, 27, [
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
                'pegawai' => ['Data pegawai', 39, 1],
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
            // Fase 1 (tiket 1.9): pemantauan kotak keluar per tujuan dan log integrasi; laporan menyusul fase 4.
            'integrasi' => ['Integrasi dan Laporan', 1, 49, [
                'satusehat' => ['SATUSEHAT', 49],
                'bpjs' => ['Bridging BPJS', 50],
                'log' => ['Log integrasi', 49],
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
            'master_data' => ['Master Data', 1, 54, [
                'organisasi' => ['Organisasi, Unit, Ruang & Tempat Tidur', 54],
                'jenis_tugas' => ['Jenis Tugas, Tenggat & Eskalasi', 53, 1],
                'katalog' => ['Data induk', 54],
                'usulan' => ['Usulan perubahan', 54],
            ]],
            'pengaturan' => ['Pengaturan Sistem', 1, 54, [
                'akun' => ['Akun & akses', 54],
                'peran' => ['Peran & izin', 54],
                'persetujuan' => ['Persetujuan akses', 54],
                'menu' => ['Pengaturan menu', 54],
                'audit' => ['Jejak audit', 54],
                'integrasi' => ['Konfigurasi integrasi', 54],
                'profil_rs' => ['Profil rumah sakit', 54],
                'penyiapan' => ['Penyiapan instalasi', 54],
                // Kebijakan keamanan instalasi, mis. masa percaya perangkat (tiket 1.13).
                'keamanan' => ['Keamanan', 54],
            ]],
        ]],
    ];

    public static function pasang(): void
    {
        foreach (self::KODE_PINDAH as $lama => $baru) {
            self::pindahKode($lama, $baru);
        }

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
                    foreach ([...self::AKSI_DASAR, ...(self::AKSI_TAMBAHAN[$kode] ?? [])] as $aksi) {
                        self::simpanIzin("$kode.$aksi", $idSub, $aksi);
                    }
                    $urutanBagian = 0;
                    foreach (self::BAGIAN[$kode] ?? [] as $kodeBagian => [$labelBagian, $aksiBagian]) {
                        $idBagian = self::simpanMenu("$kode.$kodeBagian", $idSub, 'bagian', [
                            'label' => $labelBagian, 'modul_no' => $sub[1], 'fase' => $sub[2] ?? $fase, 'urutan' => ++$urutanBagian,
                            'tersedia' => in_array($kode, self::TERSEDIA, true), 'ikut_induk' => false,
                        ]);
                        foreach ($aksiBagian as $aksi) {
                            self::simpanIzin("$kode.$kodeBagian.$aksi", $idBagian, $aksi);
                        }
                    }
                }
            }
        }

        DB::table('akses.izin')->whereIn('kode', [...self::IZIN_SENSITIF, ...self::IZIN_DATA_PRIBADI])->where('sensitif', false)
            ->update(['sensitif' => true, 'diubah_pada' => now()]);
        DB::table('akses.izin')->whereIn('kode', self::IZIN_BUTUH_MFA)->where('butuh_mfa', false)
            ->update(['butuh_mfa' => true, 'diubah_pada' => now()]);
        self::pasangSuperadmin();
        self::pasangPenyetujuBawaan();
        self::pasangPenyiap();
    }

    private static function pasangPenyiap(): void
    {
        if (DB::table('akses.peran')->where('kode', self::PERAN_PENYIAP)->exists()) {
            return;
        }
        $peranId = (string) Str::uuid7();
        DB::table('akses.peran')->insert([
            'id' => $peranId, 'kode' => self::PERAN_PENYIAP, 'nama' => 'Penyiap instalasi', 'bawaan_sistem' => false, 'wajib_mfa' => true,
            'uraian' => 'Wizard penyiapan instalasi (FR-54-08): konfirmasi langkah dan memuat paket referensi. Tambah izin data induk lain sesuai kebutuhan RS.',
        ]);
        $izin = DB::table('akses.izin')->whereIn('kode', self::IZIN_PENYIAP)->get(['id', 'menu_id']);
        foreach ($izin->pluck('menu_id')->unique() as $menuId) {
            DB::table('akses.peran_menu')->insert(['id' => (string) Str::uuid7(), 'peran_id' => $peranId, 'menu_id' => $menuId]);
        }
        foreach ($izin as $i) {
            DB::table('akses.peran_izin')->insert(['id' => (string) Str::uuid7(), 'peran_id' => $peranId, 'izin_id' => $i->id]);
        }
    }

    /**
     * Ganti kode node beserta bagian dan izin di bawahnya; induk dan rute diperbaiki simpanMenu().
     * Awalan dicocokkan persis dengan starts_with(), bukan LIKE (`_` di LIKE cocok dengan karakter apa pun).
     */
    private static function pindahKode(string $lama, string $baru): void
    {
        $ada = fn (string $kode) => DB::table('akses.menu')->where('kode', $kode)->exists();
        if (! $ada($lama) || $ada($baru)) {
            return;
        }

        DB::update(
            'UPDATE akses.menu SET kode = ? || substr(kode, ?), diubah_pada = now() WHERE kode = ? OR starts_with(kode, ?)',
            [$baru, strlen($lama) + 1, $lama, $lama.'.'],
        );
        DB::update(
            'UPDATE akses.izin SET kode = ? || substr(kode, ?), diubah_pada = now() WHERE starts_with(kode, ?)',
            [$baru, strlen($lama) + 1, $lama.'.'],
        );
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

    /**
     * Data awal K13: penyetuju izin pengelola akses = peran superadmin (superadmin selain pengaju). Hanya bila
     * belum ada penyetuju, jadi tidak menimpa penyetuju yang sudah diubah admin.
     */
    private static function pasangPenyetujuBawaan(): void
    {
        if (! Schema::hasColumn('akses.izin', 'peran_penyetuju_id')) {
            return;
        }
        DB::table('akses.izin')->whereIn('kode', self::IZIN_SENSITIF)->whereNull('peran_penyetuju_id')->update([
            'peran_penyetuju_id' => DB::table('akses.peran')->where('kode', self::PERAN_SUPERADMIN)->value('id'),
            'diubah_pada' => now(),
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
