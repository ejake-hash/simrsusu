import type { PermintaanTiruan, ResponsTiruan } from '../../akses/tiruan/serverTiruan'
import { INSTRUMEN_TRIASE_CONTOH } from '../../pendaftaran/tiruan/serverKunjungan'
import type { InstrumenTriase, SkalaTriase } from '../../pendaftaran/tipeKunjungan'
import type { StatusInstrumenTriase } from '../tipe'

/**
 * Server tiruan kontrak v0.32.0 (tiket 2.24): /data-induk/instrumen-triase (daftar berkursor urut kode, rincian, tambah,
 * ubah dengan If-Match). `aktif()` dipakai server kunjungan untuk GET /instrumen-triase dan validasi triase. Keadaan di
 * memori; instrumen SINTETIS berlabel contoh. Bukan sumber aturan.
 */
const error = (status: number, kode: string, pesan: string, rincian: Record<string, unknown>[] = []): ResponsTiruan => ({ status, isi: { error: { kode, pesan, rincian } } })
const ok = (isi: unknown, status = 200, header?: Record<string, string>): ResponsTiruan => ({ status, isi, header })
const salahKolom = (kolom: string, pesan: string) => error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom, pesan }])
const etag = (v: number) => ({ ETag: `"${v}"` })
type Isi = Record<string, unknown>

const KODE_DATA = /^[A-Z0-9][A-Z0-9._-]{0,29}$/
const KODE_SKALA = /^[A-Za-z0-9][A-Za-z0-9._-]{0,19}$/

interface InstrumenT {
  id: string
  kode: string
  nama: string
  skala: SkalaTriase[]
  status: StatusInstrumenTriase
  v: number
  diubah_pada: string
}

export interface OpsiServerInstrumenTriase {
  boleh: (izin: string) => boolean
  sekarang?: () => Date
}

export function buatServerInstrumenTriase(opsi: OpsiServerInstrumenTriase) {
  const sekarang = opsi.sekarang ?? (() => new Date())
  const daftar = new Map<string, InstrumenT>()
  let nomor = 1
  for (const i of INSTRUMEN_TRIASE_CONTOH) daftar.set(i.id, { ...i, skala: [...i.skala], status: 'aktif', v: 0, diubah_pada: '2026-10-01T02:00:00.000Z' })

  const bentuk = (i: InstrumenT) => ({ id: i.id, kode: i.kode, nama: i.nama, skala: i.skala, status: i.status, versi: String(i.v), diubah_pada: i.diubah_pada })
  function cocokVersi(v: number, header: Record<string, string | undefined>): ResponsTiruan | null {
    const im = header['if-match']
    if (!im) return error(428, 'if_match_wajib', 'Versi data tidak dikirim. Muat ulang halaman lalu coba lagi.')
    return im.replace(/^W\//, '').replace(/"/g, '') === String(v) ? null : error(412, 'versi_berubah', 'Instrumen triase ini diubah orang lain sejak Anda membukanya. Muat ulang untuk melihat perubahan terbaru.')
  }
  function periksa(isi: Isi, sendiri?: InstrumenT): ResponsTiruan | null {
    if (typeof isi.dasar_perubahan !== 'string' || isi.dasar_perubahan.trim().length < 3) return salahKolom('dasar_perubahan', 'Isi dasar perubahan, minimal 3 karakter.')
    if ('kode' in isi) {
      if (typeof isi.kode !== 'string' || !KODE_DATA.test(isi.kode)) return salahKolom('kode', 'Kode huruf besar/angka, maks. 30.')
      if ([...daftar.values()].some((x) => x.kode === isi.kode && x !== sendiri)) return salahKolom('kode', 'Kode sudah dipakai instrumen lain.')
    }
    if ('nama' in isi && (typeof isi.nama !== 'string' || isi.nama.trim().length < 2 || isi.nama.length > 150)) return salahKolom('nama', 'Nama 2–150 karakter.')
    if ('skala' in isi) {
      if (!Array.isArray(isi.skala)) return salahKolom('skala', 'Skala berupa daftar.')
      if (isi.skala.length > 20) return salahKolom('skala', 'Paling banyak 20 tingkat skala.')
      const kode = new Set<string>()
      for (const [n, s] of (isi.skala as Isi[]).entries()) {
        if (typeof s.kode !== 'string' || !KODE_SKALA.test(s.kode)) return salahKolom(`skala.${n}.kode`, 'Kode skala huruf/angka, maks. 20, tanpa spasi.')
        if (kode.has(s.kode)) return salahKolom(`skala.${n}.kode`, 'Kode skala sudah dipakai di instrumen ini.')
        kode.add(s.kode)
        if (typeof s.label !== 'string' || !s.label.trim() || s.label.length > 100) return salahKolom(`skala.${n}.label`, 'Label skala 1–100 karakter.')
      }
    }
    if ('status' in isi && isi.status !== 'aktif' && isi.status !== 'nonaktif') return salahKolom('status', 'Status harus aktif atau nonaktif.')
    return null
  }
  const rapikanSkala = (s: unknown) => ((s ?? []) as SkalaTriase[]).map((x) => ({ kode: x.kode, label: x.label.trim() }))

  function tangani(r: PermintaanTiruan): ResponsTiruan | null {
    const [a0, a1, a2, a3] = r.jalur.replace(/\/+$/, '').split('/').filter(Boolean)
    if (a0 !== 'data-induk' || a1 !== 'instrumen-triase' || a3) return null
    const m = r.metode.toUpperCase()
    const isi = (r.isi ?? {}) as Isi
    const izin = (aksi: string) => (opsi.boleh(`master_data.instrumen_triase.${aksi}`) ? null : error(403, 'akses_ditolak', 'Anda tidak punya akses untuk tindakan ini.'))

    if (!a2 && m === 'GET') {
      const tolak = izin('lihat')
      if (tolak) return tolak
      const q = r.kueri
      const cari = (q.get('cari') ?? '').toLowerCase()
      const hasil = [...daftar.values()]
        .filter((i) => (!cari || i.kode.toLowerCase().includes(cari) || i.nama.toLowerCase().includes(cari)) && (!q.get('status') || i.status === q.get('status')))
        .sort((x, y) => x.kode.localeCompare(y.kode))
      const batas = Number(q.get('batas') ?? 50)
      const mulai = q.get('kursor') ? Number(atob(q.get('kursor')!)) : 0
      return ok({ data: hasil.slice(mulai, mulai + batas).map(bentuk), meta: { kursor_berikutnya: mulai + batas < hasil.length ? btoa(String(mulai + batas)) : null } })
    }
    if (!a2 && m === 'POST') {
      const tolak = izin('input')
      if (tolak) return tolak
      for (const k of ['kode', 'nama']) if (!(k in isi)) return salahKolom(k, 'Wajib diisi.')
      const salah = periksa(isi)
      if (salah) return salah
      const baru: InstrumenT = {
        id: `00000000-0000-7000-a000-${(++nomor).toString(16).padStart(12, '0')}`, kode: isi.kode as string, nama: (isi.nama as string).trim(),
        skala: rapikanSkala(isi.skala), status: 'aktif', v: 0, diubah_pada: sekarang().toISOString(),
      }
      daftar.set(baru.id, baru)
      return ok({ data: bentuk(baru) }, 201, etag(baru.v))
    }
    const i = a2 ? daftar.get(a2) : undefined
    if (a2 && m === 'GET') {
      const tolak = izin('lihat')
      if (tolak) return tolak
      return i ? ok({ data: bentuk(i) }, 200, etag(i.v)) : error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
    }
    if (a2 && m === 'PATCH') {
      const tolak = izin('perbaiki')
      if (tolak) return tolak
      if (!i) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
      const versi = cocokVersi(i.v, r.header)
      if (versi) return versi
      if (Object.keys(isi).length < 2) return salahKolom('dasar_perubahan', 'Isi minimal satu perubahan.')
      const salah = periksa(isi, i)
      if (salah) return salah
      if ('kode' in isi) i.kode = isi.kode as string
      if ('nama' in isi) i.nama = (isi.nama as string).trim()
      if ('skala' in isi) i.skala = rapikanSkala(isi.skala)
      if ('status' in isi) i.status = isi.status as StatusInstrumenTriase
      i.v++
      i.diubah_pada = sekarang().toISOString()
      return ok({ data: bentuk(i) }, 200, etag(i.v))
    }
    return null
  }

  /** Instrumen aktif untuk triase (GET /instrumen-triase). */
  const aktif = (): InstrumenTriase[] => [...daftar.values()].filter((i) => i.status === 'aktif').map((i) => ({ id: i.id, kode: i.kode, nama: i.nama, skala: i.skala }))

  return { tangani, aktif }
}
