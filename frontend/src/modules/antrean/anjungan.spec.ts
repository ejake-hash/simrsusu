import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { kelompokPerPoli, pesanAnjungan, pilihanTanggal } from './anjungan'
import AnjunganPage from './pages/AnjunganPage.vue'
import type { AnjunganPerangkat, JadwalAnjungan, TiketLoket } from './tipe'

const jadwal = (poli: string, nama: string, dokter: string, jam: string, status: JadwalAnjungan['status'] = 'tersedia'): JadwalAnjungan => ({
  poliklinik: { id: poli, nama },
  dokter,
  jam_mulai: jam,
  jam_selesai: '12:00',
  status,
})

describe('pembantu anjungan', () => {
  it('jadwal dikelompokkan per poli, urut nama poli, urutan jadwal dari server tetap', () => {
    const k = kelompokPerPoli([jadwal('p2', 'Poli Penyakit Dalam', 'dr. B', '08:00'), jadwal('p1', 'Klinik 7', 'dr. A', '09:00'), jadwal('p2', 'Poli Penyakit Dalam', 'dr. C', '10:00')])
    expect(k.map((x) => x.poli.nama)).toEqual(['Klinik 7', 'Poli Penyakit Dalam'])
    expect(k[1]!.jadwal.map((j) => j.dokter)).toEqual(['dr. B', 'dr. C'])
  })
  it('pilihan tanggal: hari ini sampai 6 hari ke depan (batas server), lintas bulan', () => {
    const t = pilihanTanggal(new Date(2026, 10, 28))
    expect(t).toHaveLength(7)
    expect(t[0]!.nilai).toBe('2026-11-28')
    expect(t[0]!.label).toMatch(/^Hari ini, /)
    expect(t[1]!.label).toMatch(/^Besok, /)
    expect(t[6]!.nilai).toBe('2026-12-04')
  })
  it('pesan penolakan memakai bahasa pasien, tanpa istilah teknis', () => {
    expect(pesanAnjungan({ jenis: 'galat', status: 429, kode: 'terlalu_banyak_permintaan' })).toContain('ramai')
    expect(pesanAnjungan({ jenis: 'galat', status: 409, kode: 'mode_belum_didukung' })).toContain('belum tersedia')
    expect(pesanAnjungan({ jenis: 'ditolak', status: 401 })).toContain('petugas')
    expect(pesanAnjungan({ jenis: 'gagal' })).toContain('tidak terhubung')
    for (const h of [{ jenis: 'ditolak', status: 403 } as const, { jenis: 'galat', status: 422, kode: 'validasi_gagal' } as const]) {
      expect(pesanAnjungan(h)).not.toMatch(/token|422|validasi/i)
    }
  })
})

describe('AnjunganPage — layar', () => {
  const TOKEN = '01HZX0000000000000000000AA|rahasiaPerangkat'
  const PERANGKAT: AnjunganPerangkat = { kode: 'apm-contoh', lokasi: 'Lobi Contoh', mode: 'pendaftaran', batas_diam_detik: 60, layanan: ['nomor_loket', 'jadwal_praktik'] }
  const TIKET: TiketLoket = { nomor_tampil: 'P-004', prioritas: true, tanggal: '2026-11-09', diambil_pada: '2026-11-09T02:00:00Z', menunggu_di_depan: 0, lokasi: 'Loket Pendaftaran Contoh' }
  type Panggilan = { url: string; init: RequestInit }
  let panggilan: Panggilan[]
  let jawabNomor: () => Response

  const json = (status: number, isi: unknown) => new Response(JSON.stringify(isi), { status, headers: { 'Content-Type': 'application/json' } })
  beforeEach(() => {
    panggilan = []
    jawabNomor = () => json(201, { data: TIKET })
    localStorage.clear()
    history.replaceState(null, '', `/anjungan#token=${encodeURIComponent(TOKEN)}`)
    vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit) => {
      panggilan.push({ url, init })
      if (url === '/api/v1/anjungan') return json(200, { data: PERANGKAT })
      if (url === '/api/v1/anjungan/nomor-loket') return jawabNomor()
      if (url.startsWith('/api/v1/anjungan/jadwal-praktik')) return json(200, { data: [jadwal('p1', 'Klinik 7', 'dr. Contoh', '08:00', 'penuh')], meta: { kursor_berikutnya: null } })
      return json(404, { error: { kode: 'tidak_ditemukan', pesan: 'x' } })
    }))
  })
  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
    document.body.innerHTML = ''
  })

  async function pasang() {
    const w = mount(AnjunganPage, { attachTo: document.body })
    await flushPromises()
    return w
  }
  const tombol = (nama: RegExp) => [...document.querySelectorAll('button')].find((b) => nama.test(b.textContent ?? '')) as HTMLButtonElement | undefined

  it('token dari fragmen disimpan, fragmen dihapus, permintaan tanpa cookie dan token tidak di URL', async () => {
    const w = await pasang()
    expect(window.location.hash).toBe('')
    expect(localStorage.getItem('karsitara.anjungan.token')).toBe(TOKEN)
    expect(document.body.textContent).toContain('Lobi Contoh')
    expect(panggilan[0]!.init.credentials).toBe('omit')
    expect((panggilan[0]!.init.headers as Record<string, string>).Authorization).toBe(`Bearer ${TOKEN}`)
    expect(panggilan.every((p) => !p.url.includes('rahasiaPerangkat'))).toBe(true)
    w.unmount()
  })

  it('nomor prioritas: alasan terkirim, struk tampil dengan lokasi loket', async () => {
    const w = await pasang()
    tombol(/Ambil nomor antrean/)!.click()
    await flushPromises()
    tombol(/Antrean prioritas/)!.click()
    await flushPromises()
    tombol(/^Ibu hamil$/)!.click()
    await flushPromises()
    const kirim = panggilan.find((p) => p.url.endsWith('/nomor-loket'))!
    expect(JSON.parse(String(kirim.init.body))).toEqual({ prioritas: true, alasan_prioritas: 'ibu_hamil' })
    expect(document.querySelector('[data-testid="nomor-loket"]')?.textContent).toBe('P-004')
    expect(document.body.textContent).toContain('Loket Pendaftaran Contoh')
    expect(document.body.textContent).toContain('Tidak ada nomor yang menunggu di depan Anda.')
    w.unmount()
  })

  it('sesi layar berakhir sendiri sesudah batas diam: kembali ke awal dan struk dibuang', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval'] })
    const w = await pasang()
    tombol(/Ambil nomor antrean/)!.click()
    await flushPromises()
    tombol(/Antrean biasa/)!.click()
    await flushPromises()
    expect(document.querySelector('[data-testid="nomor-loket"]')).not.toBeNull()
    vi.advanceTimersByTime(59_000)
    await flushPromises()
    expect(document.querySelector('[data-testid="nomor-loket"]')).not.toBeNull()
    vi.advanceTimersByTime(1_000)
    await flushPromises()
    expect(document.querySelector('[data-testid="nomor-loket"]')).toBeNull()
    expect(document.body.textContent).toContain('Silakan pilih layanan')
    w.unmount()
  })

  it('penolakan batas laju tampil sebagai pesan, layar tetap bisa dipakai', async () => {
    jawabNomor = () => json(429, { error: { kode: 'terlalu_banyak_permintaan', pesan: 'x' } })
    const w = await pasang()
    tombol(/Ambil nomor antrean/)!.click()
    await flushPromises()
    tombol(/Antrean biasa/)!.click()
    await flushPromises()
    expect(document.querySelector('[role="alert"]')?.textContent).toContain('ramai')
    expect(tombol(/Antrean biasa/)!.disabled).toBe(false)
    w.unmount()
  })

  it('token ditolak: layar meminta bantuan petugas dan token tersimpan dibuang', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => json(401, { error: { kode: 'tidak_terautentikasi', pesan: 'x' } })))
    const w = await pasang()
    expect(document.body.textContent).toContain('Anjungan tidak dapat dipakai')
    expect(localStorage.getItem('karsitara.anjungan.token')).toBeNull()
    w.unmount()
  })

  it('jadwal dokter: status tampil per poli tanpa data pasien', async () => {
    const w = await pasang()
    tombol(/Lihat jadwal dokter/)!.click()
    await flushPromises()
    expect(panggilan.some((p) => /jadwal-praktik\?tanggal=\d{4}-\d{2}-\d{2}/.test(p.url))).toBe(true)
    expect(document.body.textContent).toContain('Klinik 7')
    expect(document.body.textContent).toContain('dr. Contoh')
    expect(document.body.textContent).toContain('Penuh')
    w.unmount()
  })
})
