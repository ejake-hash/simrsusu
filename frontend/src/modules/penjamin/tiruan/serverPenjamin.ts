import type { PermintaanTiruan, ResponsTiruan } from '../../akses/tiruan/serverTiruan'
import type { JenisPenjamin, StatusPenjamin } from '../tipe'

/**
 * Server tiruan kontrak v0.17.0 (tiket 2.1): /data-induk/penjamin (daftar berkursor, rincian, tambah, ubah dengan
 * If-Match). Keadaan di memori; dev (VITE_API_TIRUAN=true) dan unit test, tidak masuk build produksi. Bukan sumber
 * aturan. Nama penjamin sintetis.
 */
const error = (status: number, kode: string, pesan: string, rincian: Record<string, unknown>[] = []): ResponsTiruan => ({ status, isi: { error: { kode, pesan, rincian } } })
const ok = (isi: unknown, status = 200, header?: Record<string, string>): ResponsTiruan => ({ status, isi, header })
const salahKolom = (kolom: string, pesan: string) => error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom, pesan }])
const etag = (v: number) => ({ ETag: `"${v}"` })
type Isi = Record<string, unknown>

const KODE_DATA = /^[A-Z0-9][A-Z0-9._-]{0,29}$/
const PLAFON = /^[0-9]{1,15}(\.[0-9]{1,2})?$/
const JENIS: JenisPenjamin[] = ['bpjs', 'umum', 'asuransi', 'perusahaan', 'jasa_raharja', 'jamkesda', 'sponsor_riset']

interface PenjaminT {
  id: string
  kode: string
  nama: string
  jenis: JenisPenjamin
  status: StatusPenjamin
  masa_berlaku_gl_hari: number | null
  plafon: string | null
  jatuh_tempo_hari: number | null
  /** Sudah dipakai kunjungan: `jenis` terkunci. */
  dipakai: boolean
  v: number
  diubah_pada: string
}

export interface OpsiServerPenjamin {
  boleh: (izin: string) => boolean
  sekarang?: () => Date
}

export function buatServerPenjamin(opsi: OpsiServerPenjamin) {
  const sekarang = opsi.sekarang ?? (() => new Date())
  const penjamin = new Map<string, PenjaminT>()
  let nomor = 0
  const tambah = (kode: string, nama: string, jenis: JenisPenjamin, isi: Partial<PenjaminT> = {}): PenjaminT => {
    const p: PenjaminT = { id: `00000000-0000-7000-b000-${(++nomor).toString(16).padStart(12, '0')}`, kode, nama, jenis, status: 'aktif', masa_berlaku_gl_hari: null, plafon: null, jatuh_tempo_hari: null, dipakai: false, v: 1, diubah_pada: '2026-10-01T02:00:00.000Z', ...isi }
    penjamin.set(p.id, p)
    return p
  }
  tambah('BPJS', 'BPJS Kesehatan', 'bpjs', { dipakai: true, jatuh_tempo_hari: 30 })
  tambah('UMUM', 'Umum (bayar sendiri)', 'umum', { dipakai: true })
  tambah('ASU-CONTOH-A', 'Asuransi Contoh A', 'asuransi', { masa_berlaku_gl_hari: 14, plafon: '25000000', jatuh_tempo_hari: 45 })
  tambah('PRSH-CONTOH-B', 'PT Contoh B', 'perusahaan', { masa_berlaku_gl_hari: 30, jatuh_tempo_hari: 60 })
  tambah('JR', 'Jasa Raharja', 'jasa_raharja', { plafon: '20000000.50' })
  tambah('ASU-LAMA', 'Asuransi Contoh Lama', 'asuransi', { status: 'nonaktif', dipakai: true })

  const bentuk = (p: PenjaminT) => ({
    id: p.id, kode: p.kode, nama: p.nama, jenis: p.jenis, status: p.status, masa_berlaku_gl_hari: p.masa_berlaku_gl_hari,
    plafon: p.plafon, jatuh_tempo_hari: p.jatuh_tempo_hari, versi: String(p.v), diubah_pada: p.diubah_pada,
  })
  function halaman(daftar: PenjaminT[], kueri: URLSearchParams): ResponsTiruan {
    const batas = Number(kueri.get('batas') ?? 50)
    if (batas > 200) return error(422, 'ukuran_halaman_melebihi_batas', 'Ukuran halaman maksimum 200.')
    const mulai = kueri.get('kursor') ? Number(atob(kueri.get('kursor')!)) : 0
    return ok({ data: daftar.slice(mulai, mulai + batas).map(bentuk), meta: { kursor_berikutnya: mulai + batas < daftar.length ? btoa(String(mulai + batas)) : null } })
  }
  function cocokVersi(v: number, header: Record<string, string | undefined>): ResponsTiruan | null {
    const im = header['if-match']
    if (!im) return error(428, 'if_match_wajib', 'Versi data tidak dikirim. Muat ulang halaman lalu coba lagi.')
    return im.replace(/^W\//, '').replace(/"/g, '') === String(v) ? null : error(412, 'versi_berubah', 'Data ini diubah orang lain sejak Anda membukanya. Muat ulang untuk melihat perubahan terbaru.')
  }
  /** Validasi isian yang dikirim (hanya kolom yang ada); null = lolos. */
  function periksa(isi: Isi, sendiri?: PenjaminT): ResponsTiruan | null {
    if (typeof isi.dasar_perubahan !== 'string' || isi.dasar_perubahan.trim().length < 3) return salahKolom('dasar_perubahan', 'Isi dasar perubahan, minimal 3 karakter.')
    if ('kode' in isi) {
      if (typeof isi.kode !== 'string' || !KODE_DATA.test(isi.kode)) return salahKolom('kode', 'Kode huruf besar/angka, maks. 30.')
      if ([...penjamin.values()].some((x) => x.kode === isi.kode && x !== sendiri)) return salahKolom('kode', `Kode ${isi.kode} sudah dipakai penjamin lain.`)
    }
    if ('nama' in isi && (typeof isi.nama !== 'string' || isi.nama.trim().length < 2 || isi.nama.length > 150)) return salahKolom('nama', 'Nama 2–150 karakter.')
    if ('jenis' in isi) {
      if (!JENIS.includes(isi.jenis as JenisPenjamin)) return salahKolom('jenis', 'Jenis penjamin tidak dikenal.')
      if (sendiri?.dipakai && isi.jenis !== sendiri.jenis) return salahKolom('jenis', 'Jenis tidak dapat diubah: penjamin sudah dipakai kunjungan.')
    }
    const hari = (k: string, min: number, maks: number) => (k in isi && isi[k] !== null && (!Number.isInteger(isi[k]) || (isi[k] as number) < min || (isi[k] as number) > maks) ? salahKolom(k, `Isi bilangan bulat ${min}–${maks}.`) : null)
    const salahHari = hari('masa_berlaku_gl_hari', 1, 3650) ?? hari('jatuh_tempo_hari', 0, 365)
    if (salahHari) return salahHari
    if ('plafon' in isi && isi.plafon !== null && (typeof isi.plafon !== 'string' || !PLAFON.test(isi.plafon))) return salahKolom('plafon', 'Plafon berupa angka rupiah, maksimal dua desimal.')
    if ('status' in isi && isi.status !== 'aktif' && isi.status !== 'nonaktif') return salahKolom('status', 'Status harus aktif atau nonaktif.')
    return null
  }

  function tangani(r: PermintaanTiruan): ResponsTiruan | null {
    const [a0, a1, a2, a3] = r.jalur.replace(/\/+$/, '').split('/').filter(Boolean)
    if (a0 !== 'data-induk' || a1 !== 'penjamin' || a3) return null
    const m = r.metode.toUpperCase()
    const isi = (r.isi ?? {}) as Isi
    const izin = (aksi: string) => (opsi.boleh(`master_data.penjamin.${aksi}`) ? null : error(403, 'akses_ditolak', 'Anda tidak punya akses untuk tindakan ini.'))

    if (!a2 && m === 'GET') {
      const tolak = izin('lihat')
      if (tolak) return tolak
      const q = r.kueri
      const cari = (q.get('cari') ?? '').toLowerCase()
      const daftar = [...penjamin.values()]
        .filter((p) => (!cari || p.kode.toLowerCase().includes(cari) || p.nama.toLowerCase().includes(cari)) && (!q.get('status') || p.status === q.get('status')) && (!q.get('jenis') || p.jenis === q.get('jenis')))
        .sort((x, y) => x.kode.localeCompare(y.kode))
      return halaman(daftar, q)
    }
    if (!a2 && m === 'POST') {
      const tolak = izin('input')
      if (tolak) return tolak
      for (const k of ['kode', 'nama', 'jenis']) if (!(k in isi)) return salahKolom(k, 'Wajib diisi.')
      const salah = periksa(isi)
      if (salah) return salah
      const baru = tambah(isi.kode as string, (isi.nama as string).trim(), isi.jenis as JenisPenjamin, {
        masa_berlaku_gl_hari: (isi.masa_berlaku_gl_hari as number | null | undefined) ?? null,
        plafon: (isi.plafon as string | null | undefined) ?? null,
        jatuh_tempo_hari: (isi.jatuh_tempo_hari as number | null | undefined) ?? null,
        diubah_pada: sekarang().toISOString(),
      })
      return ok({ data: bentuk(baru) }, 201, etag(baru.v))
    }
    const p = a2 ? penjamin.get(a2) : undefined
    if (a2 && m === 'GET') {
      const tolak = izin('lihat')
      if (tolak) return tolak
      return p ? ok({ data: bentuk(p) }, 200, etag(p.v)) : error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
    }
    if (a2 && m === 'PATCH') {
      const tolak = izin('perbaiki')
      if (tolak) return tolak
      if (!p) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
      const versi = cocokVersi(p.v, r.header)
      if (versi) return versi
      if (Object.keys(isi).length < 2) return salahKolom('dasar_perubahan', 'Tidak ada perubahan.')
      const salah = periksa(isi, p)
      if (salah) return salah
      for (const k of ['kode', 'nama', 'jenis', 'masa_berlaku_gl_hari', 'plafon', 'jatuh_tempo_hari', 'status'] as const) if (k in isi) (p as unknown as Isi)[k] = isi[k]
      p.v++
      p.diubah_pada = sekarang().toISOString()
      return ok({ data: bentuk(p) }, 200, etag(p.v))
    }
    return null
  }
  return { tangani }
}
