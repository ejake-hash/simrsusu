import { SANDI_TIRUAN, type PermintaanTiruan, type ResponsTiruan } from '../../akses/tiruan/serverTiruan'
import type { StatusTtd, TandaTangan } from '../tipe'

/**
 * Server tiruan kontrak v0.8.0 (tiket 1.6): /saya/ttd/*, /ttd/{pengguna}/reset, /ttd/tandatangani, /ttd/verifikasi.
 * Tanpa kriptografi sungguhan (hash dan sidik dibuat dari isi teks); aturan dari kontrak: aturan PIN, 5 PIN salah →
 * terkunci, sesi tanda tangan 15 menit, hanya pemutus yang boleh menandatangani persetujuan, `baru: false` untuk kirim
 * ulang. Dev dan unit test; tidak masuk build produksi. PIN dan sandi tiruan bukan rahasia sungguhan.
 */
const error = (status: number, kode: string, pesan: string, rincian: Record<string, unknown>[] = [], header?: Record<string, string>): ResponsTiruan => ({
  status,
  isi: { error: { kode, pesan, rincian } },
  header,
})
const ok = (isi: unknown, status = 200): ResponsTiruan => ({ status, isi, header: { 'Cache-Control': 'no-store' } })
type Isi = Record<string, unknown>

/** PIN sintetis akun tiruan yang sudah aktif (contoh.superadmin.dua). */
export const PIN_TIRUAN = '482916'
const BATAS_SALAH = 5
const MENIT_KUNCI = 15
const MENIT_SESI = 15

/** Aturan PIN kontrak: 6 angka; bukan semua sama, pola 2/3 angka berulang, atau naik/turun tepat 1 tanpa melingkar. */
export function pinLemah(pin: string): boolean {
  if (!/^[0-9]{6}$/.test(pin)) return true
  if (/^(\d)\1{5}$/.test(pin) || /^(\d\d)\1\1$/.test(pin) || /^(\d{3})\1$/.test(pin)) return true
  const d = [...pin].map(Number)
  const naik = d.every((x, i) => i === 0 || x === d[i - 1]! + 1)
  const turun = d.every((x, i) => i === 0 || x === d[i - 1]! - 1)
  return naik || turun
}
const hex = (teks: string, panjang: number) => {
  let h = 0x811c9dc5
  let hasil = ''
  while (hasil.length < panjang) {
    for (const c of teks + hasil.length) {
      h ^= c.charCodeAt(0)
      h = Math.imul(h, 0x01000193) >>> 0
    }
    hasil += h.toString(16).padStart(8, '0')
  }
  return hasil.slice(0, panjang)
}

export interface OpsiServerTtd {
  penggunaSaatIni: () => string
  namaAkun: (id: string) => string | null
  persetujuan: (id: string) => { status: string; diputuskanOleh: string | null; nomor: string; ringkasan: string } | null
  ajukanReset: (akunId: string, isi: { alasan: string; dasar: string }, terapkan: () => void) => ResponsTiruan
  bolehSaya: (kode: string) => boolean
  /** Usulan data induk (1.8c): pihak putaran ini boleh menandatangani sesudah tindakannya. */
  usulan?: (id: string) => { nomor: string; tahap: string; pihak: string[]; putaran: number } | null
  sekarang?: () => Date
}

interface KredensialT {
  aktif: boolean
  pin: string | null
  diaktifkanPada: string | null
  dicabutPada: string | null
  salah: number
  terkunciSampai: number | null
  sesiSampai: number | null
  sidik: string
  resetMenunggu: boolean
}

export function buatServerTtd(opsi: OpsiServerTtd) {
  const sekarang = opsi.sekarang ?? (() => new Date())
  const ms = () => sekarang().getTime()
  const kredensial = new Map<string, KredensialT>()
  const tandaTangan: (TandaTangan & { isiSaatTtd: string })[] = []
  let nomor = 100

  const ambil = (akunId: string): KredensialT => {
    let k = kredensial.get(akunId)
    if (!k) {
      k = { aktif: false, pin: null, diaktifkanPada: null, dicabutPada: null, salah: 0, terkunciSampai: null, sesiSampai: null, sidik: hex(akunId, 16), resetMenunggu: false }
      kredensial.set(akunId, k)
    }
    return k
  }
  /** Aktifkan TTE sintetis (mis. contoh.superadmin.dua seperti TtdSintetisSeeder). */
  function aktifkan(akunId: string, pin = PIN_TIRUAN) {
    Object.assign(ambil(akunId), { aktif: true, pin, diaktifkanPada: '2026-10-01T02:00:00.000Z', dicabutPada: null })
  }
  const terkunci = (k: KredensialT) => k.terkunciSampai !== null && k.terkunciSampai > ms()
  const iso = (n: number | null) => (n === null ? null : new Date(n).toISOString())
  function status(akunId: string): StatusTtd {
    const k = ambil(akunId)
    return {
      aktif: k.aktif, diaktifkan_pada: k.aktif ? k.diaktifkanPada : null, dicabut_pada: k.aktif ? null : k.dicabutPada,
      terkunci_sampai: terkunci(k) ? iso(k.terkunciSampai) : null, sesi_berlaku_sampai: k.sesiSampai && k.sesiSampai > ms() && !terkunci(k) ? iso(k.sesiSampai) : null,
      reset_menunggu: k.resetMenunggu,
    }
  }
  const tolakTerkunci = (k: KredensialT) =>
    error(403, 'ttd_terkunci', 'Tanda tangan terkunci karena PIN salah 5 kali.', [], { 'Retry-After': String(Math.ceil((k.terkunciSampai! - ms()) / 1000)) })
  /** PIN salah: hitung; ke-5 langsung terkunci (403), selain itu 422 dengan sisa percobaan. */
  function pinSalah(k: KredensialT, kolom: string): ResponsTiruan {
    k.salah++
    if (k.salah >= BATAS_SALAH) {
      k.salah = 0
      k.terkunciSampai = ms() + MENIT_KUNCI * 60_000
      k.sesiSampai = null
      return tolakTerkunci(k)
    }
    return error(422, 'pin_salah', 'PIN salah.', [{ kolom, kode: 'pin_salah', pesan: `PIN salah. Sisa percobaan: ${BATAS_SALAH - k.salah}.`, sisa_percobaan: BATAS_SALAH - k.salah }])
  }
  const isiKanonik = (jenis: string, id: string) => {
    if (jenis === 'usulan_data_induk') {
      const u = opsi.usulan?.(id)
      // Putaran ikut isi: kirim ulang membuat tanda tangan putaran lama `isi_berubah`.
      return u ? JSON.stringify({ id, nomor: u.nomor, putaran: u.putaran }) : ''
    }
    const p = jenis === 'persetujuan_akses' ? opsi.persetujuan(id) : null
    return p ? JSON.stringify({ id, nomor: p.nomor, status: p.status, diputuskan_oleh: p.diputuskanOleh }) : ''
  }
  function bentuk(t: TandaTangan & { isiSaatTtd: string }): TandaTangan {
    const { isiSaatTtd, ...isi } = t
    const kini = isiKanonik(t.objek_jenis, t.objek_id)
    return { ...isi, kredensial_dicabut: !ambil(t.penanda_tangan.id).aktif, verifikasi: { status: kini === isiSaatTtd ? 'sah' : 'isi_berubah', diperiksa_pada: sekarang().toISOString() } }
  }

  function tangani(r: PermintaanTiruan): ResponsTiruan | null {
    const m = r.metode.toUpperCase()
    const isi = (r.isi ?? {}) as Isi
    const [a0, a1, a2, a3] = r.jalur.replace(/\/+$/, '').split('/').filter(Boolean)
    const saya = opsi.penggunaSaatIni()

    if (a0 === 'saya' && a1 === 'ttd') {
      const k = ambil(saya)
      if (m === 'POST' && a2 === 'aktivasi') {
        if (isi.sandi !== SANDI_TIRUAN) return error(422, 'sandi_salah', 'Sandi salah.', [{ kolom: 'sandi', kode: 'sandi_salah', pesan: 'Sandi akun salah.' }])
        if (k.aktif) return error(409, 'ttd_sudah_aktif', 'Tanda tangan elektronik sudah aktif.')
        if (pinLemah(String(isi.pin ?? ''))) return error(422, 'pin_lemah', 'PIN terlalu mudah ditebak.', [{ kolom: 'pin', kode: 'pin_lemah', pesan: 'PIN harus 6 angka, tidak berulang atau berurutan.' }])
        if (isi.pin !== isi.pin_ulang) return error(422, 'pin_tidak_sama', 'Pengulangan PIN tidak sama.', [{ kolom: 'pin_ulang', kode: 'pin_tidak_sama', pesan: 'Pengulangan PIN tidak sama.' }])
        Object.assign(k, { aktif: true, pin: isi.pin, diaktifkanPada: sekarang().toISOString(), dicabutPada: null, salah: 0, resetMenunggu: false, sidik: hex(`${saya}-${ms()}`, 16) })
        return ok({ data: status(saya) }, 201)
      }
      if (m === 'PUT' && a2 === 'pin') {
        if (!k.aktif) return error(409, 'ttd_belum_aktif', 'Aktifkan tanda tangan elektronik lebih dulu.')
        if (terkunci(k)) return tolakTerkunci(k)
        if (pinLemah(String(isi.pin_baru ?? ''))) return error(422, 'pin_lemah', 'PIN terlalu mudah ditebak.', [{ kolom: 'pin_baru', kode: 'pin_lemah', pesan: 'PIN harus 6 angka, tidak berulang atau berurutan.' }])
        if (isi.pin_baru !== isi.pin_ulang) return error(422, 'pin_tidak_sama', 'Pengulangan PIN tidak sama.', [{ kolom: 'pin_ulang', kode: 'pin_tidak_sama', pesan: 'Pengulangan PIN tidak sama.' }])
        if (isi.pin_lama !== k.pin) return pinSalah(k, 'pin_lama')
        Object.assign(k, { pin: isi.pin_baru, salah: 0, sesiSampai: null })
        return ok({ data: status(saya) })
      }
      return null
    }
    if (a0 === 'ttd' && a2 === 'reset' && m === 'POST' && a1) {
      if (!opsi.bolehSaya('sdm.kredensial.perbaiki')) return error(403, 'akses_ditolak', 'Anda tidak punya akses untuk tindakan ini.')
      if (typeof isi.alasan !== 'string' || isi.alasan.trim().length < 5) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'alasan', pesan: 'Isi alasan, minimal 5 karakter.' }])
      if (typeof isi.dasar !== 'string' || isi.dasar.trim().length < 3) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'dasar', pesan: 'Isi dasar, minimal 3 karakter.' }])
      const k = ambil(a1)
      if (a1 !== saya && !k.aktif) return error(409, 'ttd_belum_aktif', 'Akun ini belum mengaktifkan tanda tangan elektronik.')
      const hasil = opsi.ajukanReset(a1, { alasan: isi.alasan.trim(), dasar: isi.dasar.trim() }, () => {
        Object.assign(k, { aktif: false, dicabutPada: sekarang().toISOString(), sesiSampai: null, resetMenunggu: false })
      })
      if (hasil.status === 202) k.resetMenunggu = true
      return hasil
    }
    if (a0 === 'ttd' && a1 === 'tandatangani' && m === 'POST') {
      const daftar = Array.isArray(isi.objek) ? (isi.objek as { jenis: string; id: string }[]) : []
      if (isi.pernyataan_disetujui !== true) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'pernyataan_disetujui', pesan: 'Setujui pernyataan tanda tangan.' }])
      if (!daftar.length || daftar.length > 50) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'objek', pesan: '1–50 objek.' }])
      const ganda = daftar.findIndex((o, i) => daftar.findIndex((x) => x.id === o.id) !== i)
      if (ganda >= 0) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: `objek.${ganda}.id`, pesan: 'Objek ganda.' }])
      const k = ambil(saya)
      if (!k.aktif) return error(409, 'ttd_belum_aktif', 'Aktifkan tanda tangan elektronik lebih dulu.')
      // Objek diperiksa dulu, PIN terakhir: salah ketik objek tidak menghabiskan jatah percobaan.
      const salah = daftar.flatMap((o, i) => {
        if (o.jenis === 'usulan_data_induk') {
          const u = opsi.usulan?.(o.id)
          if (!u) return [{ kolom: `objek.${i}`, kode: 'tidak_ditemukan', pesan: 'Objek tidak ada atau tidak boleh Anda lihat.' }]
          if (!u.pihak.includes(saya) || u.tahap === 'dikembalikan') return [{ kolom: `objek.${i}`, kode: 'bukan_pemutus', pesan: `${u.nomor} hanya ditandatangani pengusul, pemverifikasi, atau penyetujunya.` }]
          return []
        }
        const p = o.jenis === 'persetujuan_akses' ? opsi.persetujuan(o.id) : null
        if (!p) return [{ kolom: `objek.${i}`, kode: 'tidak_ditemukan', pesan: 'Objek tidak ada atau tidak boleh Anda lihat.' }]
        // Seperti backend: hanya keputusan disetujui/ditolak (bukan menunggu, ditarik, atau kedaluwarsa).
        if (p.status !== 'disetujui' && p.status !== 'ditolak') return [{ kolom: `objek.${i}`, kode: 'belum_diputuskan', pesan: `${p.nomor} belum diputuskan.` }]
        if (p.diputuskanOleh !== saya) return [{ kolom: `objek.${i}`, kode: 'bukan_pemutus', pesan: `${p.nomor} hanya ditandatangani pemutusnya.` }]
        return []
      })
      if (salah.length) return error(422, 'objek_tidak_bisa_ditandatangani', 'Ada objek yang tidak bisa Anda tandatangani; tidak ada yang ditandatangani.', salah)
      if (terkunci(k)) return tolakTerkunci(k)
      const sesiAktif = k.sesiSampai !== null && k.sesiSampai > ms()
      if (isi.pin === null || isi.pin === undefined || isi.pin === '') {
        if (!sesiAktif) return error(422, 'pin_wajib', 'Masukkan PIN.', [{ kolom: 'pin', kode: 'pin_wajib', pesan: 'Masukkan PIN 6 angka.' }])
      } else if (isi.pin !== k.pin) return pinSalah(k, 'pin')
      else {
        k.salah = 0
        k.sesiSampai = ms() + MENIT_SESI * 60_000
      }
      const data = daftar.map((o) => {
        const lama = tandaTangan.find((t) => t.objek_id === o.id && t.penanda_tangan.id === saya && t.isiSaatTtd === isiKanonik(o.jenis, o.id))
        if (lama) return { ...bentuk(lama), baru: false }
        const isiSaatTtd = isiKanonik(o.jenis, o.id)
        const t = {
          id: `00000000-0000-7000-c000-${(++nomor).toString(16).padStart(12, '0')}`, objek_jenis: o.jenis as TandaTangan['objek_jenis'], objek_id: o.id,
          penanda_tangan: { id: saya, nama: opsi.namaAkun(saya) ?? 'Akun', nip: null }, tingkat: 'T1' as const, metode: 'pin' as const,
          hash_isi: hex(isiSaatTtd, 64), sidik_kunci: k.sidik, kredensial_dicabut: false, ditandatangani_pada: sekarang().toISOString(),
          verifikasi: { status: 'sah' as const, diperiksa_pada: sekarang().toISOString() }, isiSaatTtd,
        }
        tandaTangan.push(t)
        return { ...bentuk(t), baru: true }
      })
      return ok({ data, meta: { sesi_berlaku_sampai: iso(k.sesiSampai) } })
    }
    if (a0 === 'ttd' && a1 === 'verifikasi' && m === 'GET' && a2 && a3) {
      const ada = a2 === 'persetujuan_akses' ? !!opsi.persetujuan(a3) : a2 === 'usulan_data_induk' ? !!opsi.usulan?.(a3) : false
      if (!ada) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
      return ok({ data: tandaTangan.filter((t) => t.objek_id === a3).sort((x, y) => x.ditandatangani_pada.localeCompare(y.ditandatangani_pada)).map(bentuk) })
    }
    return null
  }

  /** Akun sudah menandatangani isi objek saat ini (untuk `dapat.tandatangani` usulan). */
  const sudahTtd = (objekId: string, akunId: string) =>
    tandaTangan.some((t) => t.objek_id === objekId && t.penanda_tangan.id === akunId && t.isiSaatTtd === isiKanonik(t.objek_jenis, objekId))
  return { tangani, status, aktifkan, sudahTtd, /** Uji: ubah isi tersimpan → verifikasi isi_berubah. */ rusakIsi: (objekId: string) => tandaTangan.filter((t) => t.objek_id === objekId).forEach((t) => (t.isiSaatTtd += '!')) }
}
