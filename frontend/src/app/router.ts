import { createRouter, createWebHistory } from 'vue-router'
import { tujuanSetelahMasuk } from '@/modules/akses/alurMasuk'
import { useSesiStore } from './sesi'

declare module 'vue-router' {
  interface RouteMeta {
    /** Bisa dibuka tanpa sesi. Hanya /masuk; rute lain wajib sesi. */
    publik?: boolean
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
        { path: 'master-data/jadwal-praktik', name: 'master-data-jadwal-praktik', component: () => import('@/modules/jadwal-praktik/pages/JadwalPraktikPage.vue'), meta: { judul: 'Jadwal praktik & kuota poli' } },
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
