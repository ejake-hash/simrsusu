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
      ],
    },
    {
      path: '/',
      component: () => import('@/layouts/AppShell.vue'),
      children: [
        { path: '', name: 'beranda', component: () => import('@/modules/beranda/pages/BerandaPage.vue'), meta: { judul: 'Beranda' } },
        // Gerbang izin di HalamanModul (tanpa pengaturan.<sub>.lihat → Halaman tidak ditemukan).
        { path: 'pengaturan/peran', name: 'pengaturan-peran', component: () => import('@/modules/akses/pages/PeranPage.vue'), meta: { judul: 'Peran & izin' } },
        { path: 'pengaturan/akun', name: 'pengaturan-akun', component: () => import('@/modules/akses/pages/AkunPage.vue'), meta: { judul: 'Akun & akses' } },
        { path: 'pengaturan/persetujuan', name: 'pengaturan-persetujuan', component: () => import('@/modules/akses/pages/PersetujuanPage.vue'), meta: { judul: 'Persetujuan akses' } },
        { path: 'pengaturan/profil-rs', name: 'pengaturan-profil-rs', component: () => import('@/modules/organisasi/pages/ProfilRsPage.vue'), meta: { judul: 'Profil rumah sakit' } },
        { path: 'master-data/organisasi', name: 'master-data-organisasi', component: () => import('@/modules/organisasi/pages/OrganisasiPage.vue'), meta: { judul: 'Organisasi, unit & KSM' } },
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
  return true
})

router.afterEach((ke) => {
  document.title = ke.meta.judul ? `${ke.meta.judul} · Karsitara` : 'Karsitara'
})
