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

export const router = createRouter({
  history: createWebHistory(),
  routes: [
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
