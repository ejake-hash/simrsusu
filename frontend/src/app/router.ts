import { createRouter, createWebHistory } from 'vue-router'
import { tujuanSetelahMasuk } from '@/modules/akses/alurMasuk'
import { useSesiStore } from './sesi'

declare module 'vue-router' {
  interface RouteMeta {
    /** Bisa dibuka tanpa sesi. Hanya /masuk; rute lain wajib sesi. */
    publik?: boolean
    /** Layar perangkat (display antrean): tanpa cek sesi sama sekali, supaya 401 /saya tidak mengalihkan ke /masuk. */
    tanpaSesi?: boolean
    judul?: string
  }
}

// Katalog komponen (tiket 1.11) hanya di mode pengembangan; cabang ini dan halamannya dibuang dari build produksi.
const ruteDev = import.meta.env.DEV
  ? [{ path: '/katalog', name: 'katalog', component: () => import('@/design-system/katalog/KatalogPage.vue'), meta: { publik: true, judul: 'Katalog komponen' } }]
  : []

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    ...ruteDev,
    { path: '/display/:area', name: 'display-antrean', component: () => import('@/modules/antrean/pages/DisplayPage.vue'), meta: { publik: true, tanpaSesi: true, judul: 'Display antrean' } },
    { path: '/anjungan', name: 'anjungan', component: () => import('@/modules/antrean/pages/AnjunganPage.vue'), meta: { publik: true, tanpaSesi: true, judul: 'Anjungan' } },
    {
      path: '/masuk',
      component: () => import('@/layouts/AuthLayout.vue'),
      children: [
        {
          path: '',
          name: 'masuk',
          component: () => import('@/modules/akses/pages/MasukPage.vue'),
          meta: { publik: true, judul: 'Masuk' },
        },
        // Butuh sesi; di luar AppShell karena selama sandi awal belum diganti menu dan data lain ditolak server (1.8d).
        { path: '/ganti-sandi', name: 'ganti-sandi', component: () => import('@/modules/akses/pages/GantiSandiPage.vue'), meta: { judul: 'Ganti sandi' } },
      ],
    },
    {
      path: '/',
      component: () => import('@/layouts/AppShell.vue'),
      children: [
        { path: '', name: 'beranda', component: () => import('@/modules/beranda/pages/BerandaPage.vue'), meta: { judul: 'Beranda' } },
        { path: 'pendaftaran/pasien', name: 'pendaftaran-pasien', component: () => import('@/modules/pendaftaran/pages/PasienPage.vue'), meta: { judul: 'Pendaftaran pasien' } },
        { path: 'pendaftaran/master-pasien', name: 'pendaftaran-master-pasien', component: () => import('@/modules/pendaftaran/pages/MasterPasienPage.vue'), meta: { judul: 'Master pasien' } },
        { path: 'pendaftaran/admisi', name: 'pendaftaran-admisi', component: () => import('@/modules/rawat-inap/pages/AdmisiRawatInapPage.vue'), meta: { judul: 'Admisi rawat inap' } },
        { path: 'pendaftaran/antrean', name: 'pendaftaran-antrean', component: () => import('@/modules/antrean/pages/AntreanPage.vue'), meta: { judul: 'Antrean & display' } },
        { path: 'rawat-jalan/worklist', name: 'rawat-jalan-worklist', component: () => import('@/modules/rawat-jalan/pages/WorklistPage.vue'), props: { mode: 'worklist' }, meta: { judul: 'Worklist poliklinik' } },
        { path: 'rawat-jalan/pemeriksaan', name: 'rawat-jalan-pemeriksaan', component: () => import('@/modules/rawat-jalan/pages/WorklistPage.vue'), props: { mode: 'pemeriksaan' }, meta: { judul: 'Pemeriksaan' } },
        { path: 'rawat-jalan/cppt', name: 'rawat-jalan-cppt', component: () => import('@/modules/rawat-jalan/pages/WorklistPage.vue'), props: { mode: 'cppt' }, meta: { judul: 'CPPT episode' } },
        { path: 'rawat-jalan/asesmen-perawat', name: 'rawat-jalan-asesmen-perawat', component: () => import('@/modules/rawat-jalan/pages/WorklistPage.vue'), props: { mode: 'asesmen' }, meta: { judul: 'Asesmen perawat' } },
        { path: 'pendaftaran/kunjungan', name: 'pendaftaran-kunjungan', component: () => import('@/modules/pendaftaran/pages/KunjunganPage.vue'), meta: { judul: 'Pendaftaran kunjungan poli' } },
        { path: 'igd/pendaftaran', name: 'igd-pendaftaran', component: () => import('@/modules/igd/pages/PendaftaranIgdPage.vue'), meta: { judul: 'Pendaftaran IGD' } },
        { path: 'master-data/instrumen-triase', name: 'master-data-instrumen-triase', component: () => import('@/modules/instrumen-triase/pages/InstrumenTriasePage.vue'), meta: { judul: 'Instrumen triase' } },
        { path: 'igd/triase', name: 'igd-triase', component: () => import('@/modules/igd/pages/TriasePage.vue'), meta: { judul: 'Triase' } },
        { path: 'igd/disposisi', name: 'igd-disposisi', component: () => import('@/modules/igd/pages/DisposisiPage.vue'), meta: { judul: 'Disposisi' } },
        { path: 'master-data/matriks-perpindahan', name: 'master-data-matriks-perpindahan', component: () => import('@/modules/matriks-perpindahan/pages/MatriksPerpindahanPage.vue'), meta: { judul: 'Matriks perpindahan' } },
        { path: 'igd/papan', name: 'igd-papan', component: () => import('@/modules/igd/pages/PapanIgdPage.vue'), meta: { judul: 'Papan pasien IGD' } },
        { path: 'master-data/papan-igd', name: 'master-data-papan-igd', component: () => import('@/modules/papan-igd/pages/AreaPapanIgdPage.vue'), meta: { judul: 'Area & batas papan IGD' } },
        { path: 'rawat-inap/peta-tempat-tidur', name: 'rawat-inap-peta-tempat-tidur', component: () => import('@/modules/rawat-inap/pages/PetaTempatTidurPage.vue'), meta: { judul: 'Peta tempat tidur' } },
        // 2.40: satu layar untuk dua sub-menu; gerbang = izin lihat sub-menu rutenya, bagian lain menurut izinnya sendiri.
        { path: 'rawat-inap/pemulangan', name: 'rawat-inap-pemulangan', component: () => import('@/modules/rawat-inap/pages/PemulanganPage.vue'), props: { izin: 'rawat_inap.pemulangan.lihat', judul: 'Pemulangan' }, meta: { judul: 'Pemulangan' } },
        { path: 'rawat-inap/rencana-pulang', name: 'rawat-inap-rencana-pulang', component: () => import('@/modules/rawat-inap/pages/PemulanganPage.vue'), props: { izin: 'rawat_inap.rencana_pulang.lihat', judul: 'Rencana pulang' }, meta: { judul: 'Rencana pulang' } },
        { path: 'master-data/daftar-periksa-pulang', name: 'master-data-daftar-periksa-pulang', component: () => import('@/modules/rawat-inap/pages/DaftarPeriksaPulangPage.vue'), meta: { judul: 'Daftar periksa pulang' } },
        // Gerbang izin di HalamanModul (tanpa <menu>.<sub>.lihat → Akses ditolak + Minta akses, tiket 1.2d).
        { path: 'pengaturan/peran', name: 'pengaturan-peran', component: () => import('@/modules/akses/pages/PeranPage.vue'), meta: { judul: 'Peran & izin' } },
        {
          path: 'pengaturan/akun',
          name: 'pengaturan-akun',
          component: () => import('@/modules/akses/pages/AkunPage.vue'),
          meta: { judul: 'Akun & akses' },
          // Tautan tugas PERMINTAAN_AKSES (1.7): permintaan ditindaklanjuti di Persetujuan akses → tab Permintaan.
          beforeEnter: (ke) => (typeof ke.query.permintaan === 'string' ? { path: '/pengaturan/persetujuan', query: { tab: 'permintaan' } } : true),
        },
        // Tautan tugas dan notifikasi (1.7) berbentuk jalur; layar memakai query (?id=, ?pegawai=).
        { path: 'pengaturan/persetujuan/:id', redirect: (ke) => ({ path: '/pengaturan/persetujuan', query: { ...ke.query, id: String(ke.params.id) } }) },
        { path: 'sdm/pegawai/:pegawai', redirect: (ke) => ({ path: '/sdm/pegawai', query: { pegawai: String(ke.params.pegawai) } }) },
        { path: 'pengaturan/persetujuan', name: 'pengaturan-persetujuan', component: () => import('@/modules/akses/pages/PersetujuanPage.vue'), meta: { judul: 'Persetujuan akses' } },
        { path: 'pengaturan/integrasi', name: 'pengaturan-integrasi', component: () => import('@/modules/integrasi/pages/KonfigurasiIntegrasiPage.vue'), meta: { judul: 'Konfigurasi integrasi' } },
        { path: 'integrasi/satusehat', name: 'integrasi-satusehat', component: () => import('@/modules/integrasi/pages/PemantauanIntegrasiPage.vue'), props: { tujuan: 'satusehat' }, meta: { judul: 'SATUSEHAT' } },
        { path: 'integrasi/bpjs', name: 'integrasi-bpjs', component: () => import('@/modules/integrasi/pages/PemantauanIntegrasiPage.vue'), props: { tujuan: 'bpjs' }, meta: { judul: 'Bridging BPJS' } },
        { path: 'integrasi/log', name: 'integrasi-log', component: () => import('@/modules/integrasi/pages/LogIntegrasiPage.vue'), meta: { judul: 'Log integrasi' } },
        { path: 'pengaturan/penyiapan', name: 'pengaturan-penyiapan', component: () => import('@/modules/penyiapan/pages/PenyiapanPage.vue'), meta: { judul: 'Penyiapan instalasi' } },
        { path: 'pengaturan/keamanan', name: 'pengaturan-keamanan', component: () => import('@/modules/akses/pages/KeamananPage.vue'), meta: { judul: 'Keamanan' } },
        { path: 'pengaturan/profil-rs', name: 'pengaturan-profil-rs', component: () => import('@/modules/organisasi/pages/ProfilRsPage.vue'), meta: { judul: 'Profil rumah sakit' } },
        { path: 'master-data/organisasi', name: 'master-data-organisasi', component: () => import('@/modules/organisasi/pages/OrganisasiPage.vue'), meta: { judul: 'Organisasi, Unit, Ruang & Tempat Tidur' } },
        { path: 'master-data/penjamin', name: 'master-data-penjamin', component: () => import('@/modules/penjamin/pages/PenjaminPage.vue'), meta: { judul: 'Penjamin' } },
        { path: 'farmasi/formularium', name: 'farmasi-formularium', component: () => import('@/modules/formularium/pages/FormulariumPage.vue'), meta: { judul: 'Formularium' } },
        { path: 'penunjang/laboratorium', name: 'penunjang-laboratorium', component: () => import('@/modules/lab/pages/DaftarKerjaLabPage.vue'), meta: { judul: 'Daftar kerja laboratorium' } },
        { path: 'farmasi/resep', name: 'farmasi-resep', component: () => import('@/modules/farmasi/pages/AntreanResepPage.vue'), meta: { judul: 'Antrean resep' } },
        { path: 'rekam-medis/akses-darurat', name: 'rekam-medis-akses-darurat', component: () => import('@/modules/rekam-medis/pages/TinjauanAksesDaruratPage.vue'), meta: { judul: 'Tinjauan akses darurat' } },
        { path: 'master-data/jadwal-praktik', name: 'master-data-jadwal-praktik', component: () => import('@/modules/jadwal-praktik/pages/JadwalPraktikPage.vue'), meta: { judul: 'Jadwal praktik & kuota poli' } },
        { path: 'master-data/tarif', name: 'master-data-tarif', component: () => import('@/modules/tarif/pages/BukuTarifPage.vue'), meta: { judul: 'Buku tarif' } },
        { path: 'sdm/pegawai', name: 'sdm-pegawai', component: () => import('@/modules/sdm/pages/PegawaiPage.vue'), meta: { judul: 'Data pegawai' } },
        { path: 'master-data/usulan', name: 'master-data-usulan', component: () => import('@/modules/usulan/pages/UsulanPage.vue'), meta: { judul: 'Usulan perubahan' } },
        { path: 'master-data/jenis-tugas', name: 'master-data-jenis-tugas', component: () => import('@/modules/notifikasi/pages/JenisTugasPage.vue'), meta: { judul: 'Jenis Tugas, Tenggat & Eskalasi' } },
        // Halaman akun sendiri: tanpa izin khusus (/saya/*), bukan halaman menu.
        { path: 'tanda-tangan', name: 'tanda-tangan', component: () => import('@/modules/ttd/pages/TandaTanganPage.vue'), meta: { judul: 'Tanda tangan elektronik' } },
        { path: 'perangkat', name: 'perangkat', component: () => import('@/modules/akses/pages/PerangkatPage.vue'), meta: { judul: 'Perangkat tepercaya' } },
        { path: 'notifikasi', name: 'notifikasi', component: () => import('@/modules/notifikasi/pages/NotifikasiPage.vue'), meta: { judul: 'Notifikasi' } },
        // Halaman modul didaftarkan di atas baris ini. Jalur lain: Segera hadir bila ada di menu, selain itu tidak ditemukan.
        { path: ':jalur(.*)*', name: 'menu', component: () => import('@/layouts/HalamanMenu.vue') },
      ],
    },
  ],
})

router.beforeEach(async (ke) => {
  if (ke.meta.tanpaSesi) return true
  const sesi = useSesiStore()
  await sesi.pastikan()

  if (ke.meta.publik) {
    return ke.name === 'masuk' && sesi.pengguna ? tujuanSetelahMasuk(ke.query.kembali) : true
  }
  if (!sesi.pengguna) {
    return { name: 'masuk', query: ke.fullPath === '/' ? {} : { kembali: ke.fullPath } }
  }
  if (sesi.pengguna.wajib_ganti_sandi && ke.name !== 'ganti-sandi') {
    return { name: 'ganti-sandi', query: ke.fullPath === '/' ? {} : { kembali: ke.fullPath } }
  }
  return true
})

router.afterEach((ke) => {
  document.title = ke.meta.judul ? `${ke.meta.judul} · Karsitara` : 'Karsitara'
})
