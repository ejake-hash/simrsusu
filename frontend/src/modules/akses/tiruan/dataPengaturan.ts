import type { IzinNode, JenisAksi, NodeMenu, PohonMenu } from '../tipePengaturan'

/**
 * Data awal server tiruan 1.2c (dev/test saja, tidak masuk build produksi). Pohon menu = MenuBawaan backend
 * (PRD 9.1) dengan peran/akun/audit sudah dipindah ke `pengaturan.*` sesuai paket 1.2c. Bagian dan aksi khusus
 * (`kasir.tagihan.*`, `rawat_inap.cppt.*`, `rekam_medis.pelepasan.*`) hanya contoh bentuk kontrak agar layar
 * teruji untuk kasus itu; backend 1.2a belum punya bagian maupun aksi khusus. Nama akun sintetis mengikuti
 * AkunSintetisSeeder (contoh.*), tidak ada data orang sungguhan.
 */

export const uid = (n: number) => `00000000-0000-7000-8000-${n.toString(16).padStart(12, '0')}`

type DefSub = [kode: string, label: string, fase?: number]
type DefMenu = [kode: string, label: string, fase: number, sub: DefSub[]]

const POHON: [kode: string, label: string | null, menu: DefMenu[]][] = [
  ['utama', null, [['beranda', 'Beranda', 1, []]]],
  [
    'pelayanan',
    'Pelayanan',
    [
      ['pendaftaran', 'Pendaftaran', 2, [['pasien', 'Pendaftaran pasien'], ['admisi', 'Admisi rawat inap'], ['antrean', 'Antrean & display'], ['sep', 'SEP & surat kontrol'], ['master_pasien', 'Master pasien'], ['persetujuan_umum', 'Persetujuan umum']]],
      ['igd', 'IGD', 2, [['papan', 'Papan pasien IGD'], ['triase', 'Triase'], ['cppt', 'CPPT episode'], ['rujukan', 'Rujukan SISRUTE']]],
      ['rawat_jalan', 'Rawat Jalan', 2, [['worklist', 'Worklist poliklinik'], ['pemeriksaan', 'Pemeriksaan'], ['cppt', 'CPPT episode'], ['asesmen_perawat', 'Asesmen perawat'], ['supervisi', 'Supervisi peserta didik'], ['jadwal', 'Jadwal praktik']]],
      ['rawat_inap', 'Rawat Inap', 2, [['peta_tempat_tidur', 'Peta tempat tidur'], ['pasien', 'Pasien ruangan'], ['cppt', 'CPPT & instruksi'], ['pemberian_obat', 'Pemberian obat'], ['asesmen', 'Asesmen & EWS'], ['pemulangan', 'Pemulangan']]],
      ['kamar_operasi', 'Kamar Operasi', 3, [['jadwal', 'Jadwal kamar operasi'], ['ceklis', 'Ceklis keselamatan'], ['pengajuan', 'Ajukan jadwal'], ['anestesi', 'Anestesi & pemulihan'], ['cppt', 'CPPT episode'], ['laporan', 'Laporan operasi']]],
      ['penunjang', 'Penunjang', 2, [['laboratorium', 'Laboratorium'], ['radiologi', 'Radiologi'], ['patologi_anatomi', 'Patologi anatomi', 3], ['bank_darah', 'Bank darah', 3]]],
      ['farmasi', 'Farmasi', 2, [['resep', 'Antrean & telaah resep'], ['klinis', 'Farmasi klinis'], ['stok', 'Stok depo'], ['narkotika', 'Narkotika & psikotropika'], ['formularium', 'Formularium']]],
      ['rekam_medis', 'Rekam Medis', 2, [['pencarian', 'Pencarian & riwayat RM'], ['kelengkapan', 'Kelengkapan RM'], ['pelepasan', 'Pelepasan informasi & akses'], ['arsip', 'Alih media & retensi']]],
    ],
  ],
  [
    'pendapatan',
    'Pendapatan',
    [
      ['kasir', 'Kasir', 2, [['tagihan', 'Tagihan pasien'], ['deposit', 'Deposit & refund'], ['tutup', 'Tutup kasir']]],
      ['casemix', 'Casemix dan Klaim', 3, [['koding', 'Koding'], ['berkas', 'Berkas klaim'], ['verifikasi', 'Hasil verifikasi'], ['piutang', 'Piutang & rekonsiliasi'], ['simulasi', 'Simulasi selama rawat']]],
    ],
  ],
  [
    'tata_kelola',
    'Tata Kelola',
    [
      ['mutu', 'Mutu dan Keselamatan', 4, [['indikator', 'Indikator mutu'], ['insiden', 'Insiden keselamatan'], ['ppi', 'PPI'], ['keluhan', 'Keluhan & kepuasan']]],
      ['sdm', 'SDM', 4, [['jadwal_dinas', 'Jadwal dinas'], ['pegawai', 'Data pegawai'], ['kredensial', 'Izin & kredensial'], ['cuti', 'Cuti & presensi']]],
      ['logistik', 'Logistik', 4, [['gudang', 'Gudang & persediaan'], ['pengadaan', 'Pengadaan'], ['aset', 'Aset tetap'], ['pemeliharaan', 'Pemeliharaan & kalibrasi']]],
      ['integrasi', 'Integrasi dan Laporan', 2, [['satusehat', 'SATUSEHAT'], ['bpjs', 'Bridging BPJS'], ['laporan_wajib', 'Laporan wajib', 4]]],
      ['dasbor', 'Dasbor', 4, [['ringkasan_direksi', 'Ringkasan direksi'], ['keuangan', 'Keuangan'], ['pelayanan', 'Pelayanan']]],
      ['master_data', 'Master Data', 1, [['katalog', 'Data induk'], ['usulan', 'Usulan perubahan'], ['organisasi', 'Organisasi, unit & KSM']]],
      ['pengaturan', 'Pengaturan Sistem', 1, [['peran', 'Peran & izin'], ['akun', 'Akun & akses'], ['audit', 'Jejak audit'], ['menu', 'Pengaturan menu'], ['integrasi', 'Konfigurasi integrasi'], ['profil_rs', 'Profil rumah sakit']]],
    ],
  ],
]

/** Contoh aksi khusus dan bagian (lihat keterangan berkas). */
const AKSI_KHUSUS: Record<string, [JenisAksi, boolean][]> = {
  'kasir.tagihan': [['batal', true], ['cetak', false], ['lihat_rupiah', true]],
  'rawat_inap.cppt': [['sahkan', false], ['verifikasi', false]],
  'rekam_medis.pelepasan': [['setujui', true], ['ekspor', true]],
}
const BAGIAN: Record<string, [kode: string, label: string, ikutInduk: boolean][]> = {
  'kasir.tagihan': [['panel_diskon', 'Panel diskon', false], ['panel_bantuan', 'Panel bantuan', true]],
  'rawat_inap.cppt': [['konfirmasi_lisan', 'Konfirmasi instruksi lisan', false]],
}

export const TERSEDIA = new Set(['beranda', 'pengaturan.peran', 'pengaturan.akun', 'pengaturan.profil_rs', 'master_data.organisasi'])
/** Modul yang dimatikan di instalasi contoh, agar penanda "modul nonaktif" ikut teruji. */
const MODUL_NONAKTIF = new Set(['kamar_operasi'])

/** Pengelola akses (input/perbaiki peran dan akun) sensitif sejak 1.2c-be: pemberiannya butuh persetujuan (1.2d). */
const SUB_PENGELOLA_AKSES = new Set(['pengaturan.peran', 'pengaturan.akun'])
const izinDasar = (kode: string, aksi: JenisAksi[] = ['lihat', 'input', 'perbaiki']): IzinNode[] =>
  aksi.map((a) => ({ kode: `${kode}.${a}`, jenis_aksi: a, sensitif: SUB_PENGELOLA_AKSES.has(kode) && a !== 'lihat' }))

export function buatPohon(): PohonMenu {
  let n = 1000
  const node = (kode: string, label: string, tingkat: NodeMenu['tingkat'], isi: Partial<NodeMenu> = {}): NodeMenu => ({
    id: uid(++n),
    kode,
    label,
    tingkat,
    ikon: null,
    fase: null,
    tersedia: TERSEDIA.has(kode),
    modul_aktif: !MODUL_NONAKTIF.has(kode.split('.')[0] ?? ''),
    ikut_induk: false,
    izin: [],
    anak: [],
    ...isi,
  })

  return {
    grup: POHON.map(([kode, label, menu]) => ({
      kode,
      label,
      menu: menu.map(([kodeMenu, labelMenu, fase, sub]) =>
        node(kodeMenu, labelMenu, 'menu', {
          ikon: kodeMenu,
          fase,
          // Beranda selalu tampil (akses.menu.selalu_tampil), tidak punya izin.
          anak: sub.map(([kodeSub, labelSub, faseSub]) => {
            const k = `${kodeMenu}.${kodeSub}`
            return node(k, labelSub, 'sub_menu', {
              fase: faseSub ?? fase,
              izin: [...izinDasar(k), ...(AKSI_KHUSUS[k] ?? []).map(([a, sensitif]) => ({ kode: `${k}.${a}`, jenis_aksi: a, sensitif }))],
              anak: (BAGIAN[k] ?? []).map(([kodeBagian, labelBagian, ikutInduk]) =>
                node(`${k}.${kodeBagian}`, labelBagian, 'bagian', {
                  ikut_induk: ikutInduk,
                  izin: ikutInduk ? [] : izinDasar(`${k}.${kodeBagian}`, ['lihat', 'perbaiki']),
                }),
              ),
            })
          }),
        }),
      ),
    })),
  }
}

/** Semua izin dasar sub-menu dari daftar menu (seperti PERAN_CONTOH di AkunSintetisSeeder). */
export function izinMenu(pohon: PohonMenu, daftarMenu: string[]): string[] {
  const hasil: string[] = []
  for (const g of pohon.grup) {
    for (const m of g.menu) {
      if (!daftarMenu.includes(m.kode)) continue
      for (const s of m.anak) hasil.push(...s.izin.filter((i) => ['lihat', 'input', 'perbaiki'].includes(i.jenis_aksi)).map((i) => i.kode))
    }
  }
  return hasil
}
