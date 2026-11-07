import type { PermintaanTiruan, ResponsTiruan } from '../../akses/tiruan/serverTiruan'
import type { GenerikPaten, JenisBarang, JenisFormularium, StatusAktif } from '../tipe'

/**
 * Server tiruan kontrak v0.23.0 (tiket 2.11): /data-induk/barang (+ impor CSV) dan /data-induk/formularium (+ item, impor
 * item). Keadaan di memori; dev (VITE_API_TIRUAN=true) dan unit test, tidak masuk build produksi. Bukan sumber aturan.
 * `aturSiapMelayani(true)` meniru instalasi yang sudah siap melayani: tulis formularium → 409 `harus_lewat_usulan`.
 * Semua data sintetis.
 */
const error = (status: number, kode: string, pesan: string, rincian: Record<string, unknown>[] = []): ResponsTiruan => ({ status, isi: { error: { kode, pesan, rincian } } })
const ok = (isi: unknown, status = 200, header?: Record<string, string>): ResponsTiruan => ({ status, isi, header })
const salahKolom = (kolom: string, pesan: string) => error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom, pesan }])
const etag = (v: number) => ({ ETag: `"${v}"` })
type Isi = Record<string, unknown>

const KODE_DATA = /^[A-Z0-9][A-Z0-9._-]{0,29}$/
const JENIS: JenisBarang[] = ['obat', 'bmhp', 'reagen', 'gizi', 'umum', 'implan']
const JENIS_F: JenisFormularium[] = ['fornas', 'rs', 'penjamin']
const KOLOM_BARANG = ['kode', 'nama', 'jenis', 'generik_paten', 'zat_aktif', 'kekuatan', 'sediaan', 'satuan_terkecil', 'kode_kfa', 'kewaspadaan_tinggi', 'narkotika', 'lasa']
const KOLOM_ITEM = ['kode_barang', 'restriksi', 'maks_per_resep', 'kronis']

interface BarangT {
  id: string
  kode: string
  nama: string
  jenis: JenisBarang
  generik_paten: GenerikPaten | null
  zat_aktif: string | null
  kekuatan: string | null
  sediaan: string | null
  satuan_terkecil: string
  kode_kfa: string | null
  kewaspadaan_tinggi: boolean
  narkotika: boolean
  lasa: boolean
  status: StatusAktif
  v: number
  diubah_pada: string
}
interface FormulariumT {
  id: string
  kode: string
  nama: string
  jenis: JenisFormularium
  penjamin_id: string | null
  berlaku_dari: string
  berlaku_sampai: string | null
  v: number
  diubah_pada: string
}
interface ItemT {
  id: string
  formularium_id: string
  barang_id: string
  restriksi: string | null
  maks_per_resep: number | null
  kronis: boolean
  status: StatusAktif
  v: number
  diubah_pada: string
}

export interface OpsiServerFormularium {
  boleh: (izin: string) => boolean
  /** Ringkas penjamin (id, kode, nama); null bila tidak ada. */
  penjamin?: (id: string) => { id: string; kode: string; nama: string } | null
  sekarang?: () => Date
}

/** Pecah CSV `;` atau `,` sederhana (tanpa tanda kutip bersarang): cukup untuk tiruan. */
function urai(teks: string): { judul: string[]; baris: string[][] } {
  const [judul = '', ...sisa] = teks.replace(/^﻿/, '').split(/\r?\n/).filter((b) => b.trim())
  const pemisah = judul.includes(';') ? ';' : ','
  return { judul: judul.split(pemisah).map((j) => j.trim()), baris: sisa.map((b) => b.split(pemisah).map((c) => c.trim())) }
}

export function buatServerFormularium(opsi: OpsiServerFormularium) {
  const sekarang = opsi.sekarang ?? (() => new Date())
  let siapMelayani = false
  const barang = new Map<string, BarangT>()
  const formularium = new Map<string, FormulariumT>()
  const item = new Map<string, ItemT>()
  let nomor = 0
  const id = () => `00000000-0000-7000-c000-${(++nomor).toString(16).padStart(12, '0')}`

  const tambahBarang = (kode: string, nama: string, jenis: JenisBarang, isi: Partial<BarangT> = {}): BarangT => {
    const b: BarangT = { id: id(), kode, nama, jenis, generik_paten: null, zat_aktif: null, kekuatan: null, sediaan: null, satuan_terkecil: 'tablet', kode_kfa: null, kewaspadaan_tinggi: false, narkotika: false, lasa: false, status: 'aktif', v: 1, diubah_pada: '2026-10-01T02:00:00.000Z', ...isi }
    barang.set(b.id, b)
    return b
  }
  const tambahFormularium = (kode: string, nama: string, jenis: JenisFormularium, isi: Partial<FormulariumT> = {}): FormulariumT => {
    const f: FormulariumT = { id: id(), kode, nama, jenis, penjamin_id: null, berlaku_dari: '2026-01-01', berlaku_sampai: null, v: 1, diubah_pada: '2026-10-01T02:00:00.000Z', ...isi }
    formularium.set(f.id, f)
    return f
  }
  const tambahItem = (formulariumId: string, barangId: string, isi: Partial<ItemT> = {}): ItemT => {
    const i: ItemT = { id: id(), formularium_id: formulariumId, barang_id: barangId, restriksi: null, maks_per_resep: null, kronis: false, status: 'aktif', v: 1, diubah_pada: '2026-10-01T02:00:00.000Z', ...isi }
    item.set(i.id, i)
    return i
  }

  const pct = tambahBarang('PCT500', 'Parasetamol 500 mg', 'obat', { generik_paten: 'generik', zat_aktif: 'Parasetamol', kekuatan: '500 mg', sediaan: 'tablet', kode_kfa: 'KFA-CONTOH-1' })
  tambahBarang('PCT500-P', 'Parasetamol Merek Contoh 500 mg', 'obat', { generik_paten: 'paten', zat_aktif: 'Parasetamol', kekuatan: '500 mg', sediaan: 'tablet' })
  const amox = tambahBarang('AMX500', 'Amoksisilin 500 mg', 'obat', { generik_paten: 'generik', zat_aktif: 'Amoksisilin', kekuatan: '500 mg', sediaan: 'kapsul', satuan_terkecil: 'kapsul' })
  const morfin = tambahBarang('MOR10', 'Morfin 10 mg/mL', 'obat', { generik_paten: 'generik', zat_aktif: 'Morfin', kekuatan: '10 mg/mL', sediaan: 'injeksi', satuan_terkecil: 'ampul', narkotika: true, kewaspadaan_tinggi: true, kode_kfa: 'KFA-CONTOH-2' })
  tambahBarang('KCL75', 'Kalium klorida 7,5%', 'obat', { generik_paten: 'generik', zat_aktif: 'Kalium klorida', sediaan: 'infus', satuan_terkecil: 'vial', kewaspadaan_tinggi: true, lasa: true })
  tambahBarang('KASA10', 'Kasa steril 10 x 10 cm', 'bmhp', { satuan_terkecil: 'lembar' })
  tambahBarang('OBT-LAMA', 'Obat Contoh Lama', 'obat', { generik_paten: 'generik', status: 'nonaktif' })
  const rs = tambahFormularium('FRM-RS-2026', 'Formularium RS Contoh 2026', 'rs')
  tambahFormularium('FORNAS-2026', 'Fornas Contoh 2026', 'fornas')
  tambahItem(rs.id, pct.id, { restriksi: 'Maksimal 4 g per hari.', maks_per_resep: 30 })
  tambahItem(rs.id, amox.id, { kronis: false })
  tambahItem(rs.id, morfin.id, { restriksi: 'Hanya dokter spesialis.', maks_per_resep: 10 })

  const ringkasBarang = (b: BarangT) => ({ id: b.id, kode: b.kode, nama: b.nama, generik_paten: b.generik_paten, zat_aktif: b.zat_aktif, kekuatan: b.kekuatan, sediaan: b.sediaan, satuan_terkecil: b.satuan_terkecil, status: b.status })
  const bentukBarang = (b: BarangT) => ({ ...ringkasBarang(b), jenis: b.jenis, kode_kfa: b.kode_kfa, kewaspadaan_tinggi: b.kewaspadaan_tinggi, narkotika: b.narkotika, lasa: b.lasa, versi: String(b.v), diubah_pada: b.diubah_pada })
  const bentukF = (f: FormulariumT) => ({
    id: f.id, kode: f.kode, nama: f.nama, jenis: f.jenis, penjamin: f.penjamin_id ? (opsi.penjamin?.(f.penjamin_id) ?? null) : null,
    berlaku_dari: f.berlaku_dari, berlaku_sampai: f.berlaku_sampai,
    jumlah_item_aktif: [...item.values()].filter((i) => i.formularium_id === f.id && i.status === 'aktif').length, versi: String(f.v), diubah_pada: f.diubah_pada,
  })
  const bentukItem = (i: ItemT) => ({ id: i.id, formularium_id: i.formularium_id, barang: ringkasBarang(barang.get(i.barang_id)!), restriksi: i.restriksi, maks_per_resep: i.maks_per_resep, kronis: i.kronis, status: i.status, versi: String(i.v), diubah_pada: i.diubah_pada })

  function halaman<T, U>(daftar: T[], kueri: URLSearchParams, bentuk: (x: T) => U): ResponsTiruan {
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
  const dasarSalah = (isi: Isi) => (typeof isi.dasar_perubahan !== 'string' || isi.dasar_perubahan.trim().length < 3 ? salahKolom('dasar_perubahan', 'Isi dasar perubahan, minimal 3 karakter.') : null)
  const lewatUsulan = () => (siapMelayani ? error(409, 'harus_lewat_usulan', 'Instalasi sudah siap melayani. Perubahan formularium diajukan lewat Usulan perubahan.') : null)
  const tolak = () => error(403, 'akses_ditolak', 'Anda tidak punya akses untuk tindakan ini.')
  const izin = (aksi: string) => opsi.boleh(`farmasi.formularium.${aksi}`)
  const hasilImpor = (mode: 'pratinjau' | 'terapkan', jumlah: number, kesalahan: { baris: number; kolom: string | null; kode: string; pesan: string }[]): ResponsTiruan => {
    if (kesalahan.length && mode === 'terapkan') return error(422, 'impor_tidak_valid', 'Ada baris yang salah; tidak ada yang disimpan.', kesalahan.slice(0, 500) as unknown as Record<string, unknown>[])
    return ok({ data: { mode, diterapkan: mode === 'terapkan', jumlah_baris: jumlah, jumlah_salah: kesalahan.length, kesalahan: kesalahan.slice(0, 500), kesalahan_terpotong: kesalahan.length > 500 } })
  }
  const bacaImpor = (r: PermintaanTiruan, kolomWajib: string[]) => {
    const fd = r.isi instanceof FormData ? r.isi : null
    const berkas = fd?.get('berkas')
    const mode = fd?.get('mode') === 'terapkan' ? ('terapkan' as const) : ('pratinjau' as const)
    if (String(fd?.get('dasar_perubahan') ?? '').trim().length < 3) return salahKolom('dasar_perubahan', 'Isi dasar perubahan, minimal 3 karakter.')
    if (!(berkas instanceof Blob)) return salahKolom('berkas', 'Pilih berkas CSV.')
    return berkas.text().then((teks) => {
      const { judul, baris } = urai(teks)
      if (judul.join(',') !== kolomWajib.join(',')) return error(422, 'berkas_tidak_valid', `Judul kolom harus persis: ${kolomWajib.join(',')}.`)
      return { mode, baris, judul }
    })
  }
  const ya = (t: string | undefined) => (t ?? '').toLowerCase() === 'ya'

  function imporBarang(r: PermintaanTiruan): ResponsTiruan | Promise<ResponsTiruan> {
    const dibaca = bacaImpor(r, KOLOM_BARANG)
    if ('status' in dibaca) return dibaca
    return dibaca.then((h) => {
      if ('status' in h) return h
      const kesalahan: { baris: number; kolom: string | null; kode: string; pesan: string }[] = []
      const dalamBerkas = new Set<string>()
      const sah: Partial<BarangT>[] = []
      h.baris.forEach((c, n) => {
        const baris = n + 2
        const [kode = '', nama = '', jenis = '', gp = '', zat = '', kuat = '', sed = '', satuan = '', kfa = '', waspada = '', narko = '', lasa = ''] = c
        const salah = (kolom: string | null, kd: string, pesan: string) => kesalahan.push({ baris, kolom, kode: kd, pesan })
        if (!kode || !nama || !jenis || !satuan) return salah(!kode ? 'kode' : !nama ? 'nama' : !jenis ? 'jenis' : 'satuan_terkecil', 'kolom_wajib', 'Kolom wajib kosong.')
        if (!JENIS.includes(jenis as JenisBarang)) return salah('jenis', 'jenis_tidak_dikenal', `Jenis ${jenis} tidak dikenal.`)
        if (!KODE_DATA.test(kode)) return salah('kode', 'kode_tidak_valid', 'Kode huruf besar/angka, maks. 30.')
        if (dalamBerkas.has(kode)) return salah('kode', 'kode_ganda', `Kode ${kode} muncul dua kali di berkas.`)
        dalamBerkas.add(kode)
        if ([...barang.values()].some((b) => b.kode === kode)) return salah('kode', 'kode_sudah_dipakai', `Kode ${kode} sudah dipakai barang lain.`)
        if (jenis === 'obat' && !gp) return salah('generik_paten', 'kolom_wajib', 'Obat wajib ditandai generik atau paten.')
        if (gp && gp !== 'generik' && gp !== 'paten') return salah('generik_paten', 'nilai_tidak_valid', 'Isi generik atau paten.')
        sah.push({ kode, nama, jenis: jenis as JenisBarang, generik_paten: (gp || null) as GenerikPaten | null, zat_aktif: zat || null, kekuatan: kuat || null, sediaan: sed || null, satuan_terkecil: satuan, kode_kfa: kfa || null, kewaspadaan_tinggi: ya(waspada), narkotika: ya(narko), lasa: ya(lasa) })
      })
      if (!kesalahan.length && h.mode === 'terapkan') for (const s of sah) tambahBarang(s.kode!, s.nama!, s.jenis!, { ...s, diubah_pada: sekarang().toISOString() })
      return hasilImpor(h.mode, h.baris.length, kesalahan)
    })
  }
  function imporItem(r: PermintaanTiruan, f: FormulariumT): ResponsTiruan | Promise<ResponsTiruan> {
    const dibaca = bacaImpor(r, KOLOM_ITEM)
    if ('status' in dibaca) return dibaca
    return dibaca.then((h) => {
      if ('status' in h) return h
      const kesalahan: { baris: number; kolom: string | null; kode: string; pesan: string }[] = []
      const dalamBerkas = new Set<string>()
      const sah: { barang: BarangT; restriksi: string | null; maks: number | null; kronis: boolean }[] = []
      h.baris.forEach((c, n) => {
        const baris = n + 2
        const [kode = '', restriksi = '', maks = '', kronis = ''] = c
        const salah = (kolom: string | null, kd: string, pesan: string) => kesalahan.push({ baris, kolom, kode: kd, pesan })
        if (!kode) return salah('kode_barang', 'kolom_wajib', 'Kode barang wajib diisi.')
        const b = [...barang.values()].find((x) => x.kode === kode)
        if (!b) return salah('kode_barang', 'barang_tidak_ditemukan', `Barang ${kode} tidak ada di katalog.`)
        if (b.status !== 'aktif') return salah('kode_barang', 'barang_tidak_aktif', `Barang ${kode} nonaktif.`)
        if (dalamBerkas.has(kode)) return salah('kode_barang', 'kode_ganda', `Barang ${kode} muncul dua kali di berkas.`)
        dalamBerkas.add(kode)
        if ([...item.values()].some((i) => i.formularium_id === f.id && i.barang_id === b.id)) return salah('kode_barang', 'sudah_di_formularium', `Barang ${kode} sudah ada di formularium ini.`)
        if (maks && !(Number.isInteger(Number(maks)) && Number(maks) >= 1)) return salah('maks_per_resep', 'nilai_tidak_valid', 'Isi bilangan bulat minimal 1.')
        sah.push({ barang: b, restriksi: restriksi || null, maks: maks ? Number(maks) : null, kronis: ya(kronis) })
      })
      if (!kesalahan.length && h.mode === 'terapkan') for (const s of sah) tambahItem(f.id, s.barang.id, { restriksi: s.restriksi, maks_per_resep: s.maks, kronis: s.kronis, diubah_pada: sekarang().toISOString() })
      return hasilImpor(h.mode, h.baris.length, kesalahan)
    })
  }

  function periksaBarang(isi: Isi, sendiri?: BarangT): ResponsTiruan | null {
    const dasar = dasarSalah(isi)
    if (dasar) return dasar
    if ('kode' in isi) {
      if (typeof isi.kode !== 'string' || !KODE_DATA.test(isi.kode)) return salahKolom('kode', 'Kode huruf besar/angka, maks. 30.')
      if ([...barang.values()].some((x) => x.kode === isi.kode && x !== sendiri)) return salahKolom('kode', `Kode ${isi.kode} sudah dipakai barang lain.`)
    }
    if ('nama' in isi && (typeof isi.nama !== 'string' || isi.nama.trim().length < 2 || isi.nama.length > 200)) return salahKolom('nama', 'Nama 2–200 karakter.')
    if ('jenis' in isi) {
      if (!JENIS.includes(isi.jenis as JenisBarang)) return salahKolom('jenis', 'Jenis barang tidak dikenal.')
      if (sendiri && isi.jenis !== sendiri.jenis && [...item.values()].some((i) => i.barang_id === sendiri.id)) return salahKolom('jenis', 'Jenis tidak dapat diubah: barang sudah ada di formularium.')
    }
    if ('satuan_terkecil' in isi && (typeof isi.satuan_terkecil !== 'string' || !isi.satuan_terkecil.trim())) return salahKolom('satuan_terkecil', 'Satuan terkecil wajib diisi.')
    const jenis = (isi.jenis ?? sendiri?.jenis) as JenisBarang
    const gp = 'generik_paten' in isi ? isi.generik_paten : sendiri?.generik_paten
    if (jenis === 'obat' && !gp) return salahKolom('generik_paten', 'Obat wajib ditandai generik atau paten.')
    return null
  }
  const SALIN_BARANG = ['kode', 'nama', 'jenis', 'generik_paten', 'zat_aktif', 'kekuatan', 'sediaan', 'satuan_terkecil', 'kode_kfa', 'kewaspadaan_tinggi', 'narkotika', 'lasa', 'status'] as const

  /** Tumpang tindih rentang berlaku dengan formularium lain yang sama (jenis, penjamin). */
  function tumpang(dari: string, sampai: string | null, jenis: JenisFormularium, penjaminId: string | null, sendiri?: FormulariumT) {
    return [...formularium.values()].some((x) => x !== sendiri && x.jenis === jenis && x.penjamin_id === penjaminId && dari <= (x.berlaku_sampai ?? '9999-12-31') && x.berlaku_dari <= (sampai ?? '9999-12-31'))
  }

  function tangani(r: PermintaanTiruan): ResponsTiruan | Promise<ResponsTiruan> | null {
    const [a0, a1, a2, a3, , a5] = r.jalur.replace(/\/+$/, '').split('/').filter(Boolean)
    if (a0 !== 'data-induk' || (a1 !== 'barang' && a1 !== 'formularium')) return null
    const m = r.metode.toUpperCase()
    const isi = (r.isi ?? {}) as Isi
    const q = r.kueri

    if (a1 === 'barang') {
      if (a2 === 'impor' && !a3 && m === 'POST') return izin('input') ? imporBarang(r) : tolak()
      if (a3) return null
      if (!a2 && m === 'GET') {
        if (!izin('lihat')) return tolak()
        const cari = (q.get('cari') ?? '').toLowerCase()
        const daftar = [...barang.values()]
          .filter((b) => !cari || [b.kode, b.nama, b.zat_aktif ?? ''].some((t) => t.toLowerCase().includes(cari)))
          .filter((b) => (!q.get('jenis') || b.jenis === q.get('jenis')) && (!q.get('status') || b.status === q.get('status')))
          .filter((b) => q.get('tanpa_kfa') !== 'true' || (b.status === 'aktif' && b.kode_kfa === null))
          .sort((x, y) => x.nama.localeCompare(y.nama))
        return halaman(daftar, q, bentukBarang)
      }
      if (!a2 && m === 'POST') {
        if (!izin('input')) return tolak()
        for (const k of ['kode', 'nama', 'jenis', 'satuan_terkecil']) if (!(k in isi)) return salahKolom(k, 'Wajib diisi.')
        const salah = periksaBarang(isi)
        if (salah) return salah
        const b = tambahBarang(isi.kode as string, (isi.nama as string).trim(), isi.jenis as JenisBarang, { diubah_pada: sekarang().toISOString() })
        for (const k of SALIN_BARANG) if (k in isi) (b as unknown as Isi)[k] = isi[k]
        return ok({ data: bentukBarang(b) }, 201, etag(b.v))
      }
      const b = a2 ? barang.get(a2) : undefined
      if (a2 && m === 'GET') {
        if (!izin('lihat')) return tolak()
        return b ? ok({ data: bentukBarang(b) }, 200, etag(b.v)) : error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
      }
      if (a2 && m === 'PATCH') {
        if (!izin('perbaiki')) return tolak()
        if (!b) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
        const versi = cocokVersi(b.v, r.header)
        if (versi) return versi
        if (Object.keys(isi).length < 2) return salahKolom('dasar_perubahan', 'Tidak ada perubahan.')
        const salah = periksaBarang(isi, b)
        if (salah) return salah
        for (const k of SALIN_BARANG) if (k in isi) (b as unknown as Isi)[k] = isi[k]
        b.v++
        b.diubah_pada = sekarang().toISOString()
        return ok({ data: bentukBarang(b) }, 200, etag(b.v))
      }
      return null
    }

    // /data-induk/formularium[/{id}[/item[/{itemId}|/impor]]]
    if (a5 || (a3 && a3 !== 'item')) return null
    if (!a2 && m === 'GET') {
      if (!izin('lihat')) return tolak()
      const daftar = [...formularium.values()]
        .filter((f) => (!q.get('jenis') || f.jenis === q.get('jenis')) && (!q.get('penjamin_id') || f.penjamin_id === q.get('penjamin_id')))
        .filter((f) => !q.get('berlaku_pada') || (f.berlaku_dari <= q.get('berlaku_pada')! && q.get('berlaku_pada')! <= (f.berlaku_sampai ?? '9999-12-31')))
        .sort((x, y) => y.berlaku_dari.localeCompare(x.berlaku_dari) || x.id.localeCompare(y.id))
      return halaman(daftar, q, bentukF)
    }
    if (!a2 && m === 'POST') {
      if (!izin('input')) return tolak()
      const usulan = lewatUsulan()
      if (usulan) return usulan
      for (const k of ['kode', 'nama', 'jenis', 'berlaku_dari']) if (!(k in isi)) return salahKolom(k, 'Wajib diisi.')
      const dasar = dasarSalah(isi)
      if (dasar) return dasar
      if (typeof isi.kode !== 'string' || !KODE_DATA.test(isi.kode)) return salahKolom('kode', 'Kode huruf besar/angka, maks. 30.')
      if ([...formularium.values()].some((f) => f.kode === isi.kode)) return salahKolom('kode', `Kode ${isi.kode} sudah dipakai formularium lain.`)
      if (typeof isi.nama !== 'string' || isi.nama.trim().length < 2) return salahKolom('nama', 'Nama 2–150 karakter.')
      if (!JENIS_F.includes(isi.jenis as JenisFormularium)) return salahKolom('jenis', 'Jenis formularium tidak dikenal.')
      const penjaminId = (isi.penjamin_id as string | null | undefined) ?? null
      if (isi.jenis === 'penjamin' ? !penjaminId || !opsi.penjamin?.(penjaminId) : penjaminId) return salahKolom('penjamin_id', isi.jenis === 'penjamin' ? 'Pilih penjamin aktif.' : 'Penjamin hanya untuk jenis penjamin.')
      const sampai = (isi.berlaku_sampai as string | null | undefined) ?? null
      if (tumpang(isi.berlaku_dari as string, sampai, isi.jenis as JenisFormularium, penjaminId)) return salahKolom('berlaku_dari', 'Rentang berlaku tumpang tindih dengan formularium lain yang sejenis.')
      const f = tambahFormularium(isi.kode, isi.nama.trim(), isi.jenis as JenisFormularium, { penjamin_id: penjaminId, berlaku_dari: isi.berlaku_dari as string, berlaku_sampai: sampai, diubah_pada: sekarang().toISOString() })
      return ok({ data: bentukF(f) }, 201, etag(f.v))
    }
    const f = a2 ? formularium.get(a2) : undefined
    if (a2 && !a3) {
      if (m === 'GET') {
        if (!izin('lihat')) return tolak()
        return f ? ok({ data: bentukF(f) }, 200, etag(f.v)) : error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
      }
      if (m === 'PATCH') {
        if (!izin('perbaiki')) return tolak()
        if (!f) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
        const usulan = lewatUsulan()
        if (usulan) return usulan
        const versi = cocokVersi(f.v, r.header)
        if (versi) return versi
        if (Object.keys(isi).length < 2) return salahKolom('dasar_perubahan', 'Tidak ada perubahan.')
        const dasar = dasarSalah(isi)
        if (dasar) return dasar
        if ('nama' in isi && (typeof isi.nama !== 'string' || isi.nama.trim().length < 2)) return salahKolom('nama', 'Nama 2–150 karakter.')
        const dari = (isi.berlaku_dari as string | undefined) ?? f.berlaku_dari
        const sampai = 'berlaku_sampai' in isi ? (isi.berlaku_sampai as string | null) : f.berlaku_sampai
        if (tumpang(dari, sampai, f.jenis, f.penjamin_id, f)) return salahKolom('berlaku_dari', 'Rentang berlaku tumpang tindih dengan formularium lain yang sejenis.')
        if (typeof isi.nama === 'string') f.nama = isi.nama.trim()
        f.berlaku_dari = dari
        f.berlaku_sampai = sampai
        f.v++
        f.diubah_pada = sekarang().toISOString()
        return ok({ data: bentukF(f) }, 200, etag(f.v))
      }
      return null
    }

    // …/{id}/item
    if (!f) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
    return tanganiItem(r, f)
  }

  function tanganiItem(r: PermintaanTiruan, f: FormulariumT): ResponsTiruan | Promise<ResponsTiruan> | null {
    const [, , , , a4, a5] = r.jalur.replace(/\/+$/, '').split('/').filter(Boolean)
    const m = r.metode.toUpperCase()
    const isi = (r.isi ?? {}) as Isi
    const q = r.kueri
    if (a4 === 'impor' && m === 'POST') {
      if (!izin('input')) return tolak()
      return lewatUsulan() ?? imporItem(r, f)
    }
    if (!a4 && m === 'GET') {
      if (!izin('lihat')) return tolak()
      const cari = (q.get('cari') ?? '').toLowerCase()
      const daftar = [...item.values()]
        .filter((i) => i.formularium_id === f.id && (!q.get('status') || i.status === q.get('status')))
        .filter((i) => !cari || [barang.get(i.barang_id)!.kode, barang.get(i.barang_id)!.nama, barang.get(i.barang_id)!.zat_aktif ?? ''].some((t) => t.toLowerCase().includes(cari)))
        .sort((x, y) => barang.get(x.barang_id)!.nama.localeCompare(barang.get(y.barang_id)!.nama))
      return halaman(daftar, q, bentukItem)
    }
    if (!a4 && m === 'POST') {
      if (!izin('input')) return tolak()
      const usulan = lewatUsulan()
      if (usulan) return usulan
      const dasar = dasarSalah(isi)
      if (dasar) return dasar
      const b = typeof isi.barang_id === 'string' ? barang.get(isi.barang_id) : undefined
      if (!b) return salahKolom('barang_id', 'Barang tidak ditemukan.')
      if (b.status !== 'aktif') return salahKolom('barang_id', 'Barang nonaktif tidak bisa ditambahkan.')
      if ([...item.values()].some((i) => i.formularium_id === f.id && i.barang_id === b.id)) return salahKolom('barang_id', 'Barang sudah ada di formularium ini.')
      const maks = (isi.maks_per_resep as number | null | undefined) ?? null
      if (maks !== null && (!Number.isInteger(maks) || maks < 1)) return salahKolom('maks_per_resep', 'Isi bilangan bulat minimal 1.')
      const i = tambahItem(f.id, b.id, { restriksi: (isi.restriksi as string | null | undefined) ?? null, maks_per_resep: maks, kronis: isi.kronis === true, diubah_pada: sekarang().toISOString() })
      return ok({ data: bentukItem(i) }, 201, etag(i.v))
    }
    if (a4 && !a5 && m === 'PATCH') {
      if (!izin('perbaiki')) return tolak()
      const i = item.get(a4)
      if (!i || i.formularium_id !== f.id) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
      const usulan = lewatUsulan()
      if (usulan) return usulan
      const versi = cocokVersi(i.v, r.header)
      if (versi) return versi
      if (Object.keys(isi).length < 2) return salahKolom('dasar_perubahan', 'Tidak ada perubahan.')
      const dasar = dasarSalah(isi)
      if (dasar) return dasar
      if ('maks_per_resep' in isi && isi.maks_per_resep !== null && (!Number.isInteger(isi.maks_per_resep) || (isi.maks_per_resep as number) < 1)) return salahKolom('maks_per_resep', 'Isi bilangan bulat minimal 1.')
      for (const k of ['restriksi', 'maks_per_resep', 'kronis', 'status'] as const) if (k in isi) (i as unknown as Isi)[k] = isi[k]
      i.v++
      i.diubah_pada = sekarang().toISOString()
      return ok({ data: bentukItem(i) }, 200, etag(i.v))
    }
    return null
  }

  return { tangani, aturSiapMelayani: (v: boolean) => (siapMelayani = v) }
}
