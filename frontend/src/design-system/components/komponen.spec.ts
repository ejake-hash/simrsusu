import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { defineComponent, nextTick, ref } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import Dialog from './Dialog.vue'
import NavigasiSubMenu from './NavigasiSubMenu.vue'
import Sakelar from './Sakelar.vue'
import Tabs from './Tabs.vue'

afterEach(() => {
  document.body.innerHTML = ''
})

const tekan = (el: Element, key: string) => el.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }))

describe('Dialog', () => {
  function pasang(sibuk = false, peringatan = false) {
    return mount(
      defineComponent({
        components: { Dialog },
        setup: () => ({ buka: ref(false), sibuk, peringatan }),
        template: `
          <button id="pemicu" @click="buka = true">Akhiri</button>
          <Dialog v-model:open="buka" judul="Akhiri pencabutan?" uraian="Akses kembali sesuai peran." :sibuk="sibuk" :peringatan="peringatan">
            <label>Alasan <input id="alasan" /></label>
            <template #aksi><button id="ya">Akhiri</button></template>
          </Dialog>`,
      }),
      { attachTo: document.body },
    )
  }

  it('membuka dengan judul, uraian, dan fokus di dalam dialog; Esc menutup', async () => {
    const w = pasang()
    const pemicu = document.getElementById('pemicu') as HTMLButtonElement
    pemicu.focus()
    await w.get('#pemicu').trigger('click')
    await flushPromises()

    const dialog = document.querySelector('[role="dialog"]') as HTMLElement
    expect(dialog).not.toBeNull()
    expect(dialog.getAttribute('aria-labelledby')).toBeTruthy()
    expect(document.getElementById(dialog.getAttribute('aria-labelledby')!)?.textContent).toBe('Akhiri pencabutan?')
    expect(dialog.contains(document.activeElement)).toBe(true)

    tekan(document.activeElement!, 'Escape')
    await flushPromises()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(w.vm.buka).toBe(false)
  })

  it('saat sibuk: Esc tidak menutup dan tombol tutup nonaktif', async () => {
    const w = pasang(true)
    await w.get('#pemicu').trigger('click')
    await flushPromises()

    tekan(document.activeElement!, 'Escape')
    await flushPromises()
    expect(document.querySelector('[role="dialog"]')).not.toBeNull()
    expect((document.querySelector('button[aria-label="Tutup"]') as HTMLButtonElement).disabled).toBe(true)
  })

  it('peringatan = role alertdialog', async () => {
    const w = pasang(false, true)
    await w.get('#pemicu').trigger('click')
    await flushPromises()
    expect(document.querySelector('[role="alertdialog"]')).not.toBeNull()
  })
})

describe('Tabs', () => {
  // Navigasi panah (pola WAI-ARIA) milik Reka UI; jsdom tidak memindah fokus, jadi diuji di e2e.
  it('slot per nilai; memilih tab menampilkan isinya', async () => {
    const w = mount(
      defineComponent({
        components: { Tabs },
        setup: () => ({ aktif: ref('penugasan') }),
        template: `
          <Tabs v-model="aktif" label="Rincian akun" :daftar="[{ nilai: 'penugasan', label: 'Penugasan' }, { nilai: 'mfa', label: 'MFA' }]">
            <template #penugasan><p>isi penugasan</p></template>
            <template #mfa><p>isi mfa</p></template>
          </Tabs>`,
      }),
      { attachTo: document.body },
    )
    expect(w.get('[role="tablist"]').attributes('aria-label')).toBe('Rincian akun')
    expect(w.text()).toContain('isi penugasan')
    expect(w.text()).not.toContain('isi mfa')

    await w.findAll('[role="tab"]')[1]!.trigger('mousedown', { button: 0 })
    await flushPromises()
    expect(w.vm.aktif).toBe('mfa')
    expect(w.text()).toContain('isi mfa')
  })
})

describe('Sakelar', () => {
  it('status sebagai teks, bukan warna saja; terkunci menampilkan alasan dan tidak bisa diubah', async () => {
    const nilai = ref(true)
    const w = mount(
      defineComponent({
        components: { Sakelar },
        setup: () => ({ nilai }),
        template: `<Sakelar v-model="nilai" label="Wajib MFA" terkunci alasan-terkunci="Peran superadmin selalu wajib MFA." />`,
      }),
    )
    const tombol = w.get('[role="switch"]')
    expect(tombol.attributes('aria-checked')).toBe('true')
    expect(w.text()).toContain('Nyala')
    expect(tombol.attributes('disabled')).toBeDefined()
    expect(tombol.attributes('aria-describedby')).toBeTruthy()
    await tombol.trigger('click')
    expect(nilai.value).toBe(true)
  })

  it('bisa diubah bila tidak terkunci', async () => {
    const nilai = ref(false)
    const w = mount(defineComponent({ components: { Sakelar }, setup: () => ({ nilai }), template: `<Sakelar v-model="nilai" label="Wajib MFA" />` }))
    await w.get('[role="switch"]').trigger('click')
    await nextTick()
    expect(nilai.value).toBe(true)
    expect(w.text()).toContain('Nyala')
  })
})

describe('NavigasiSubMenu', () => {
  const daftar = [
    { kode: 'pengaturan.peran', label: 'Peran & izin', rute: '/pengaturan/peran' },
    { kode: 'pengaturan.akun', label: 'Akun & akses', rute: '/pengaturan/akun' },
    { kode: 'pengaturan.audit', label: 'Jejak audit', rute: '/pengaturan/audit', status: 'segera' as const },
  ]

  async function pasang(jalur: string, bentuk: 'tab' | 'kartu') {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { template: '<p />' } }] })
    await router.push(jalur)
    return mount(NavigasiSubMenu, { props: { daftar, label: 'Sub-menu Pengaturan Sistem', bentuk }, global: { plugins: [router] } })
  }

  it('tab: item aktif mengikuti rute (termasuk halaman di bawahnya), penanda Segera hadir', async () => {
    const w = await pasang('/pengaturan/akun/0190-abc', 'tab')
    expect(w.get('nav').attributes('aria-label')).toBe('Sub-menu Pengaturan Sistem')
    const tautan = w.findAll('a')
    expect(tautan.map((a) => a.attributes('aria-current'))).toEqual([undefined, 'page', undefined])
    expect(tautan[2]!.text()).toMatch(/^Jejak audit\s*Segera hadir$/)
  })

  it('kartu: semua sub-menu sebagai tautan, tanpa yang aktif di halaman induk', async () => {
    const w = await pasang('/pengaturan', 'kartu')
    expect(w.find('.navsub-kartu').exists()).toBe(true)
    expect(w.findAll('a').map((a) => a.attributes('href'))).toEqual(['/pengaturan/peran', '/pengaturan/akun', '/pengaturan/audit'])
    expect(w.findAll('[aria-current]')).toHaveLength(0)
  })
})
