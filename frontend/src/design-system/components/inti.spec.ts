import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick, ref } from 'vue'
import IsianSandi from '../form/IsianSandi.vue'
import KolomIsian from '../form/KolomIsian.vue'
import DataTable from './DataTable.vue'
import FormulirBertahap from './FormulirBertahap.vue'
import KeadaanKosong from './KeadaanKosong.vue'
import KpiCard from './KpiCard.vue'
import Lencana from './Lencana.vue'
import NotifikasiToast from './NotifikasiToast.vue'
import Pesan from './Pesan.vue'
import PohonData from './PohonData.vue'
import PopoverPencarian from './PopoverPencarian.vue'
import Tombol from './Tombol.vue'
import { daftarToast, tampilkanToast, tutupToast } from './toast'
import type { SimpulPohon } from './pohon'

const terpasang: VueWrapper[] = []
function pasang<T>(k: T, opsi: Record<string, unknown> = {}) {
  const w = mount(k as never, { attachTo: document.body, ...opsi })
  terpasang.push(w)
  return w
}
afterEach(() => {
  terpasang.splice(0).forEach((w) => w.unmount())
  daftarToast.value.forEach((t) => tutupToast(t.id))
  document.body.innerHTML = ''
  vi.useRealTimers()
})
const tekan = (el: Element, key: string) => el.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }))

describe('Tombol', () => {
  it('varian, ukuran, penuh; sibuk = pemutar + aria-busy; atribut diteruskan', () => {
    const w = pasang(Tombol, { props: { varian: 'utama', ukuran: 'besar', penuh: true, sibuk: true, type: 'submit' }, attrs: { disabled: true, form: 'f' }, slots: { default: 'Masuk' } })
    const b = w.get('button')
    expect(b.classes()).toEqual(expect.arrayContaining(['tombol', 'tombol-utama', 'tombol-besar', 'tombol-penuh', 'tombol-sibuk']))
    expect(b.attributes()).toMatchObject({ type: 'submit', 'aria-busy': 'true', form: 'f' })
    expect(b.attributes('disabled')).toBeDefined()
    expect(b.find('.tombol-pemutar').exists()).toBe(true)
    expect(pasang(Tombol, { slots: { default: 'Kedua' } }).get('button').classes()).toEqual(['tombol'])
  })
})

describe('Tombol sibuk tanpa disabled', () => {
  it('menahan klik dan kirim formulir, tetap bisa difokus (aria-disabled)', async () => {
    const klik = vi.fn()
    const kirim = vi.fn((e: Event) => e.preventDefault())
    const w = pasang(
      defineComponent({
        setup: () => () => h('form', { onSubmit: kirim }, [h(Tombol, { type: 'submit', varian: 'utama', sibuk: true, onClick: klik }, () => 'Simpan')]),
      }),
    )
    const b = w.get('button')
    expect(b.attributes('disabled')).toBeUndefined()
    expect(b.attributes('aria-disabled')).toBe('true')
    ;(b.element as HTMLButtonElement).focus()
    await b.trigger('click')
    expect(klik).not.toHaveBeenCalled()
    expect(kirim).not.toHaveBeenCalled()
    expect(document.activeElement).toBe(b.element)
  })
  it('tidak sibuk: klik diteruskan ke pemakai', async () => {
    const klik = vi.fn()
    await pasang(Tombol, { attrs: { onClick: klik }, slots: { default: 'Ok' } }).get('button').trigger('click')
    expect(klik).toHaveBeenCalledTimes(1)
  })
})

describe('Pesan dan Lencana', () => {
  it('Pesan: jenis jadi kelas, ikon dekoratif, teks dari slot', () => {
    const w = pasang(Pesan, { props: { jenis: 'error' }, slots: { default: 'Sandi salah.' } })
    expect(w.classes()).toContain('pesan-error')
    expect(w.get('svg').attributes('aria-hidden')).toBe('true')
    expect(w.text()).toBe('Sandi salah.')
  })
  it('Lencana: ikon per jenis (status tidak hanya warna), tanpaIkon untuk tabel padat', () => {
    expect(pasang(Lencana, { props: { jenis: 'bahaya' }, slots: { default: 'Terkunci' } }).find('svg').exists()).toBe(true)
    expect(pasang(Lencana, { props: { jenis: 'ok', tanpaIkon: true }, slots: { default: 'Aktif' } }).find('svg').exists()).toBe(false)
    expect(pasang(Lencana, { slots: { default: 'Nonaktif' } }).classes()).toContain('pil-netral')
  })
})

describe('KeadaanKosong dan KpiCard', () => {
  it('KeadaanKosong: error = alert, memuat = status + aria-busy, aksi lewat slot', () => {
    expect(pasang(KeadaanKosong, { props: { judul: 'Gagal', jenis: 'error' } }).attributes('role')).toBe('alert')
    const memuat = pasang(KeadaanKosong, { props: { judul: 'Memuat…', jenis: 'memuat' } })
    expect(memuat.attributes()).toMatchObject({ role: 'status', 'aria-busy': 'true' })
    const kosong = pasang(KeadaanKosong, { props: { judul: 'Belum ada lokasi', uraian: 'Tambahkan gedung.' }, slots: { aksi: '<button>Tambah</button>' } })
    expect(kosong.attributes('role')).toBeUndefined()
    expect(kosong.text()).toContain('Tambahkan gedung.')
    expect(kosong.find('button').exists()).toBe(true)
  })
  it('KpiCard: angka format Indonesia; null = "—" dan "Data belum tersedia", tidak pernah angka contoh', () => {
    expect(pasang(KpiCard, { props: { label: 'Akun', nilai: 2418, satuan: 'akun' } }).get('.kpi-nilai').element.textContent?.replace(/\s+/g, ' ').trim()).toBe('2.418 akun')
    const kosong = pasang(KpiCard, { props: { label: 'Akun', nilai: null, satuan: 'akun' } })
    expect(kosong.get('.kpi-nilai').text()).toBe('—')
    expect(kosong.text()).toContain('Data belum tersedia')
    expect(pasang(KpiCard, { props: { label: 'Akun', nilai: null, memuat: true } }).find('[role="status"]').exists()).toBe(true)
    const kritis = pasang(KpiCard, { props: { label: 'Antrean', nilai: 9, status: 'bahaya' } })
    expect(kritis.get('.kpi-label').text()).toContain('(kritis)')
    expect(pasang(KpiCard, { props: { label: 'Antrean', nilai: 3, status: 'peringatan' } }).get('.kpi-label').text()).toContain('(perlu perhatian)')
  })
})

describe('NotifikasiToast', () => {
  beforeEach(() => vi.useFakeTimers())
  it('berhasil di wilayah status dan hilang sendiri; error di wilayah alert dan tetap sampai ditutup; maks 3', async () => {
    const w = pasang(NotifikasiToast)
    tampilkanToast('Tersimpan.')
    tampilkanToast('Gagal menyimpan.', 'error')
    await nextTick()
    expect(w.get('[role="status"]').text()).toContain('Tersimpan.')
    expect(w.get('[role="alert"]').text()).toContain('Gagal menyimpan.')
    vi.advanceTimersByTime(5100)
    await nextTick()
    expect(w.get('[role="status"]').text()).not.toContain('Tersimpan.')
    expect(w.get('[role="alert"]').text()).toContain('Gagal menyimpan.')
    await w.get('[role="alert"] button').trigger('click')
    expect(w.get('[role="alert"]').text()).toBe('')
    tampilkanToast('Error penting.', 'error')
    ;['a', 'b', 'c', 'd'].forEach((p) => tampilkanToast(p, 'info'))
    // Batas 3 hanya untuk non-error; error tetap sampai ditutup.
    expect(daftarToast.value.map((t) => t.pesan)).toEqual(['Error penting.', 'b', 'c', 'd'])
  })
})

describe('IsianSandi dalam KolomIsian', () => {
  it('tampilkan sandi (aria-pressed), id dan error dari KolomIsian, Caps Lock ikut aria-describedby', async () => {
    const nilai = ref('rahasia')
    const w = pasang(
      defineComponent({
        setup: () => () => h(KolomIsian, { id: 'sandi', label: 'Kata sandi', error: 'Wajib.' }, { default: (a: Record<string, unknown>) => h(IsianSandi, { ...a, modelValue: nilai.value, 'onUpdate:modelValue': (v: string) => (nilai.value = v) }) }),
      }),
    )
    const input = w.get('#sandi')
    expect(input.attributes()).toMatchObject({ type: 'password', 'aria-invalid': 'true', 'aria-describedby': 'sandi-error' })
    await w.get('button[aria-label="Tampilkan kata sandi"]').trigger('click')
    expect(input.attributes('type')).toBe('text')
    expect(w.get('button').attributes('aria-pressed')).toBe('true')
    input.element.dispatchEvent(new KeyboardEvent('keydown', { key: 'A', modifierCapsLock: true }))
    await nextTick()
    expect(input.attributes('aria-describedby')).toBe('sandi-error sandi-caps')
  })
})

describe('PohonData (tree WAI-ARIA)', () => {
  const pohon: SimpulPohon[] = [
    { id: 'a', label: 'Gedung A', anak: [{ id: 'a1', label: 'Lantai 1' }, { id: 'a2', label: 'Lantai 2' }] },
    { id: 'b', label: 'Gedung B' },
  ]
  it('satu tab stop; kanan membuka lalu masuk anak, kiri ke induk lalu melipat; Enter memilih; End/Home', async () => {
    const terbuka = ref<string[]>([])
    const terpilih = ref<string | null>(null)
    const w = pasang(
      defineComponent({
        setup: () => () =>
          h(PohonData, {
            simpul: pohon,
            label: 'Lokasi',
            terbuka: terbuka.value,
            'onUpdate:terbuka': (v: string[]) => (terbuka.value = v),
            terpilih: terpilih.value,
            'onUpdate:terpilih': (v: string | null) => (terpilih.value = v),
          }),
      }),
    )
    const item = () => w.findAll('[role="treeitem"]')
    expect(w.get('[role="tree"]').attributes('aria-label')).toBe('Lokasi')
    expect(item().map((i) => i.attributes('tabindex'))).toEqual(['0', '-1'])
    expect(item()[0]!.attributes()).toMatchObject({ 'aria-level': '1', 'aria-expanded': 'false', 'aria-setsize': '2', 'aria-posinset': '1' })

    tekan(item()[0]!.element, 'ArrowRight')
    await flushPromises()
    expect(terbuka.value).toEqual(['a'])
    tekan(item()[0]!.element, 'ArrowRight')
    await flushPromises()
    expect(document.activeElement?.id).toMatch(/-a1$/)
    tekan(document.activeElement!, 'ArrowDown')
    await flushPromises()
    expect(document.activeElement?.id).toMatch(/-a2$/)
    tekan(document.activeElement!, 'Enter')
    await flushPromises()
    expect(terpilih.value).toBe('a2')
    tekan(document.activeElement!, 'ArrowLeft')
    await flushPromises()
    expect(document.activeElement?.id).toMatch(/-a$/)
    tekan(document.activeElement!, 'ArrowLeft')
    await flushPromises()
    expect(terbuka.value).toEqual([])
    tekan(document.activeElement!, 'End')
    await flushPromises()
    expect(document.activeElement?.id).toMatch(/-b$/)
  })
})

describe('FormulirBertahap', () => {
  it('aria-current pada langkah sekarang; sebelumLanjut menahan; langkah terakhir memancarkan selesai', async () => {
    const aktif = ref('satu')
    let boleh = false
    const selesai = vi.fn()
    const w = pasang(
      defineComponent({
        setup: () => () =>
          h(
            FormulirBertahap,
            {
              modelValue: aktif.value,
              'onUpdate:modelValue': (v: string) => (aktif.value = v),
              langkah: [
                { kode: 'satu', label: 'Satu' },
                { kode: 'dua', label: 'Dua' },
              ],
              label: 'Langkah',
              sebelumLanjut: () => boleh,
              onSelesai: selesai,
            },
            { satu: () => h('p', 'isi satu'), dua: () => h('p', 'isi dua') },
          ),
      }),
    )
    expect(w.get('[aria-current="step"]').text()).toContain('Satu')
    const lanjut = () => w.findAll('button').find((b) => /Lanjut|Selesai/.test(b.text()))!
    await lanjut().trigger('click')
    await flushPromises()
    expect(aktif.value).toBe('satu')
    boleh = true
    await lanjut().trigger('click')
    await flushPromises()
    expect(aktif.value).toBe('dua')
    expect(w.text()).toContain('isi dua')
    expect(document.activeElement?.textContent).toContain('Langkah 2 dari 2')
    await lanjut().trigger('click')
    await flushPromises()
    expect(selesai).toHaveBeenCalled()
  })
})

describe('DataTable', () => {
  const kolom = [
    { kunci: 'kode', label: 'Kode' },
    { kunci: 'nama', label: 'Nama', prioritas: 2 as const },
    { kunci: 'jumlah', label: 'Jumlah', prioritas: 3 as const, rata: 'kanan' as const },
  ]
  const baris = (n: number) => Array.from({ length: n }, (_, i) => ({ id: `r${i}`, kode: `K${i}`, nama: `Nama ${i}`, jumlah: i }))

  it('kelas prioritas kolom, "Muat lagi" saat adaLagi, pilih dengan klik dan Enter', async () => {
    const w = pasang(DataTable, { props: { kolom, baris: baris(3), kunciBaris: (b: { id: string }) => b.id, label: 'Contoh', adaLagi: true, dapatDipilih: true } })
    expect(w.findAll('th').map((t) => t.classes())).toEqual([['p1'], ['p2'], ['p3', 'kanan']])
    await w.findAll('button').find((b) => b.text() === 'Muat lagi')!.trigger('click')
    expect(w.emitted('muatLagi')).toHaveLength(1)
    await w.findAll('tbody tr')[1]!.trigger('keydown', { key: 'Enter' })
    await w.findAll('tbody tr')[2]!.trigger('click')
    expect(w.emitted('pilih')!.map((e) => (e[0] as { id: string }).id)).toEqual(['r1', 'r2'])
  })

  it('baris: satu tab stop, panah memindah fokus, Enter dari tombol di dalam sel tidak memilih', async () => {
    const w = pasang(DataTable, {
      props: { kolom, baris: baris(3), kunciBaris: (b: { id: string }) => b.id, label: 'Contoh', dapatDipilih: true },
      slots: { 'sel-kode': '<button class="dalam">Aksi</button>' },
    })
    const tr = () => w.findAll('tbody tr')
    expect(tr().map((t) => t.attributes('tabindex'))).toEqual(['0', '-1', '-1'])
    tekan(tr()[0]!.element, 'ArrowDown')
    await flushPromises()
    expect(document.activeElement).toBe(tr()[1]!.element)
    expect(tr().map((t) => t.attributes('tabindex'))).toEqual(['-1', '0', '-1'])
    tekan(w.findAll('button.dalam')[1]!.element, 'Enter')
    expect(w.emitted('pilih')).toBeUndefined()
    tekan(tr()[1]!.element, 'Enter')
    expect(w.emitted('pilih')).toHaveLength(1)
  })

  it('kosong, error (dengan Coba lagi), memuat memakai KeadaanKosong', async () => {
    expect(pasang(DataTable, { props: { kolom, baris: [], kunciBaris: () => '', label: 'Contoh', teksKosong: 'Belum ada.' } }).text()).toContain('Belum ada.')
    const err = pasang(DataTable, { props: { kolom, baris: [], kunciBaris: () => '', label: 'Contoh', error: 'Server mati.' } })
    expect(err.get('[role="alert"]').text()).toContain('Server mati.')
    await err.get('button').trigger('click')
    expect(err.emitted('cobaLagi')).toHaveLength(1)
    expect(pasang(DataTable, { props: { kolom, baris: [], kunciBaris: () => '', label: 'Contoh', memuat: true } }).find('[role="status"]').exists()).toBe(true)
  })

  it('virtual: hanya jendela baris yang dirender, sisanya ruang kosong; aria-rowcount penuh', async () => {
    const w = pasang(DataTable, { props: { kolom, baris: baris(2000), kunciBaris: (b: { id: string }) => b.id, label: 'Besar', virtual: true, tinggiBaris: 40, tinggiMaks: 400 } })
    const dirender = () => w.findAll('tbody tr').filter((t) => t.attributes('aria-hidden') !== 'true')
    expect(dirender().length).toBeLessThan(40)
    expect(w.get('table').attributes('aria-rowcount')).toBe('2001')
    const kotak = w.get('[role="region"]').element as HTMLElement
    kotak.scrollTop = 40 * 1000
    kotak.dispatchEvent(new Event('scroll'))
    await nextTick()
    expect(dirender()[0]!.text()).toContain('K992')
  })
})

describe('PopoverPencarian', () => {
  it('Esc mengembalikan fokus ke elemen yang aktif sebelum dibuka (bukan selalu ke tombol pemicu)', async () => {
    const buka = ref(false)
    const kueri = ref('')
    const w = pasang(
      defineComponent({
        setup: () => () => [
          h('button', { id: 'asal' }, 'Tombol lain'),
          h(PopoverPencarian, {
            open: buka.value,
            'onUpdate:open': (v: boolean) => (buka.value = v),
            kueri: kueri.value,
            'onUpdate:kueri': (v: string) => (kueri.value = v),
            label: 'Cari contoh',
            labelIsian: 'Kata kunci',
            item: [],
            status: 'Ketik untuk mencari.',
          }),
        ],
      }),
    )
    ;(document.getElementById('asal') as HTMLButtonElement).focus()
    buka.value = true
    await flushPromises()
    const kolom = document.querySelector<HTMLInputElement>('input[role="combobox"]')!
    expect(kolom).not.toBeNull()
    tekan(kolom, 'Escape')
    await flushPromises()
    expect(buka.value).toBe(false)
    expect(document.activeElement?.id).toBe('asal')
    expect(w.exists()).toBe(true)
  })
})
