import { describe, expect, it } from 'vitest'
import type { RouteLocationNormalized, RouteRecordRedirectOption } from 'vue-router'
import { router } from './router'

/** Tautan tugas/notifikasi dari backend (tiket 1.7) berbentuk jalur; layar memakai query. */
describe('Rute tautan tugas', () => {
  const alihkan = (jalur: string) => {
    const r = router.resolve(jalur)
    const rekam = r.matched[r.matched.length - 1]!
    const arah = rekam.redirect as Exclude<RouteRecordRedirectOption, string>
    return typeof arah === 'function' ? arah(r as RouteLocationNormalized, r as RouteLocationNormalized) : arah
  }

  it('/pengaturan/persetujuan/{id} → ?id=', () => {
    expect(alihkan('/pengaturan/persetujuan/0198-abc')).toEqual({ path: '/pengaturan/persetujuan', query: { id: '0198-abc' } })
  })

  it('/sdm/pegawai/{id}?tab=kredensial → ?pegawai=', () => {
    expect(alihkan('/sdm/pegawai/0198-def?tab=kredensial')).toEqual({ path: '/sdm/pegawai', query: { pegawai: '0198-def' } })
  })

  it('/pengaturan/akun?permintaan={id} → Persetujuan akses tab Permintaan; tanpa permintaan tetap Akun & akses', () => {
    const rekam = router.getRoutes().find((x) => x.name === 'pengaturan-akun')!
    const jaga = rekam.beforeEnter as (ke: RouteLocationNormalized) => unknown
    expect(jaga(router.resolve('/pengaturan/akun?permintaan=0198-x') as RouteLocationNormalized)).toEqual({ path: '/pengaturan/persetujuan', query: { tab: 'permintaan' } })
    expect(jaga(router.resolve('/pengaturan/akun?akun=1') as RouteLocationNormalized)).toBe(true)
  })
})
