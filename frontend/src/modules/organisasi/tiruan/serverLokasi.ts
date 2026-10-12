import type { PermintaanTiruan, ResponsTiruan } from '../../akses/tiruan/serverTiruan'
import type {
  AtributKamar,
  AturanJenisKelamin,
  FungsiRuang,
  JenisLokasi,
  KesalahanBarisImpor,
  RingkasUnit,
  StatusLokasi,
  StatusOperasionalTt,
} from '../tipeLokasi'

/**
 * Server tiruan kontrak v0.5.0 (tiket 1.8b, termasuk perbaikan #46): /lokasi (pohon, pindah induk, status), /lokasi/impor
 * (CSV pratinjau/terapkan), /bangsal, /kamar, /tempat-tidur, /kelas-rawat, /poliklinik, /kapasitas-tempat-tidur.
 * Keadaan di memori; dev (VITE_API_TIRUAN=true) dan unit test, tidak masuk build produksi. Bukan sumber aturan.
 * Nama gedung, ruang, dan bangsal sintetis.
 */
const error = (status: number, kode: string, pesan: string, rincian: Record<string, unknown>[] = []): ResponsTiruan => ({ status, isi: { error: { kode, pesan, rincian } } })
const ok = (isi: unknown, status = 200, header?: Record<string, string>): ResponsTiruan => ({ status, isi, header })
const id = (n: number) => `00000000-0000-7000-a000-${n.toString(16).padStart(12, '0')}`
type Isi = Record<string, unknown>

const KODE_LOKASI = /^[A-Z0-9]{1,10}$/
const KODE_DATA = /^[A-Z0-9][A-Z0-9._-]{0,29}$/
/** Jenis anak yang boleh per jenis induk (null = akar). */
const ANAK: Record<string, JenisLokasi[]> = {
  akar: ['situs', 'gedung'],
  situs: ['gedung'],
  gedung: ['sayap', 'lantai'],
  sayap: ['lantai'],
  lantai: ['ruang'],
  ruang: ['tempat_tidur'],
  tempat_tidur: [],
}
const JUDUL_IMPOR = 'jenis,kode,induk,nama,fungsi,unit,bangsal,kelas_rawat,kapasitas_maksimum,ekstra'
const FUNGSI: FungsiRuang[] = ['rawat_inap', 'rawat_jalan', 'igd', 'kamar_operasi', 'intensif', 'penunjang', 'administrasi', 'gudang', 'lainnya']

interface LokasiT {
  id: string
  induk_id: string | null
  jenis: JenisLokasi
  kode: string
  nama: string
  fungsi: FungsiRuang | null
  unit_id: string | null
  status: StatusLokasi
  status_berlaku_dari: string
  v: number
  diubah_pada: string
}
interface BangsalT { id: string; kode: string; nama: string; unit_id: string; status: 'aktif' | 'nonaktif'; v: number; diubah_pada: string }
interface KamarT { id: string; lokasi_id: string; bangsal_id: string; kapasitas_maksimum: number | null; atribut_standar: AtributKamar; v: number; diubah_pada: string }
interface TtT {
  id: string
  kode: string
  kamar_id: string
  lokasi_id: string
  kelas_id: string
  aturan_jenis_kelamin: AturanJenisKelamin
  isolasi: boolean
  ekstra: boolean
  berlaku_dari: string
  berlaku_sampai: string | null
  alasan_tutup: string | null
  v: number
  diubah_pada: string
}
interface KelasT { id: string; kode: string; nama: string; kode_aplicares: string | null; berlaku_dari: string; status: 'aktif' | 'nonaktif'; v: number; diubah_pada: string }
interface PoliT { id: string; kode: string; nama: string; unit_id: string; lokasi_id: string | null; kode_poli_bpjs: string | null; status: 'aktif' | 'nonaktif'; v: number; diubah_pada: string }

export interface OpsiServerLokasi {
  /** Unit organisasi dari server tiruan 1.8a (untuk `unit` dan `unit_tidak_valid`). */
  unit?: (id: string) => (RingkasUnit & { aktif: boolean }) | null
  /** Unit aktif pertama untuk data contoh. */
  unitContoh?: string | null
  sekarang?: () => Date
}

export function buatServerLokasi(opsi: OpsiServerLokasi = {}) {
  const sekarang = opsi.sekarang ?? (() => new Date())
  const iso = () => sekarang().toISOString()
  // Tanggal zona RS (kontrak), bukan UTC: antara 00.00–07.00 WIB tanggal UTC masih kemarin.
  const hariIni = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit' }).format(sekarang())
  const unitDari = opsi.unit ?? (() => null)
  let nomor = 100

  const lokasi = new Map<string, LokasiT>()
  const bangsal = new Map<string, BangsalT>()
  const kamar = new Map<string, KamarT>()
  const tt = new Map<string, TtT>()
  const kelas = new Map<string, KelasT>()
  const poli = new Map<string, PoliT>()
  const kodeTtTerpakai = new Set<string>()

  // ── Turunan ──
  const kodeLengkap = (l: LokasiT): string => (l.induk_id ? `${kodeLengkap(lokasi.get(l.induk_id)!)}-${l.kode}` : l.kode)
  const jalur = (l: LokasiT) => {
    const hasil: { id: string; kode_lengkap: string; nama: string }[] = []
    for (let i = l.induk_id ? lokasi.get(l.induk_id) : undefined; i; i = i.induk_id ? lokasi.get(i.induk_id) : undefined) hasil.unshift({ id: i.id, kode_lengkap: kodeLengkap(i), nama: i.nama })
    return hasil
  }
  const anak = (indukId: string) => [...lokasi.values()].filter((x) => x.induk_id === indukId)
  const turunan = (lid: string): LokasiT[] => anak(lid).flatMap((a) => [a, ...turunan(a.id)])
  const statusTt = (t: TtT): StatusOperasionalTt =>
    t.berlaku_sampai !== null && t.berlaku_sampai < hariIni() ? 'ditutup' : t.berlaku_sampai !== null ? 'akan_ditutup' : t.berlaku_dari > hariIni() ? 'belum_berlaku' : 'aktif'
  const dihitung = (t: TtT) => ['aktif', 'akan_ditutup'].includes(statusTt(t))
  const ttDiLokasi = (lid: string) => [...tt.values()].find((t) => t.lokasi_id === lid)
  const unitRingkas = (uid: string | null) => {
    const u = uid ? unitDari(uid) : null
    return u ? { id: u.id, kode: u.kode, nama: u.nama } : null
  }
  const ringkasLokasi = (l: LokasiT) => ({ id: l.id, kode_lengkap: kodeLengkap(l), nama: l.nama })
  const ringkasBangsal = (b: BangsalT) => ({ id: b.id, kode: b.kode, nama: b.nama })
  const ringkasKelas = (k: KelasT) => ({ id: k.id, kode: k.kode, nama: k.nama })

  function bentukLokasi(l: LokasiT) {
    const t = l.jenis === 'tempat_tidur' ? ttDiLokasi(l.id) : undefined
    const status: StatusLokasi = t ? (statusTt(t) === 'ditutup' ? 'ditutup' : 'aktif') : l.status
    return {
      id: l.id, induk_id: l.induk_id, jenis: l.jenis, kode: l.kode, kode_lengkap: kodeLengkap(l), nama: l.nama, fungsi: l.fungsi,
      unit: unitRingkas(l.unit_id), status, status_berlaku_dari: l.status_berlaku_dari, satusehat_lokasi_id: null,
      kamar_id: [...kamar.values()].find((k) => k.lokasi_id === l.id)?.id ?? null, tempat_tidur_id: t?.id ?? null,
      jumlah_anak: anak(l.id).length, jalur: jalur(l), versi: String(l.v), diubah_pada: l.diubah_pada,
    }
  }
  function bentukBangsal(b: BangsalT) {
    const ttBangsal = [...tt.values()].filter((t) => kamar.get(t.kamar_id)?.bangsal_id === b.id)
    const perKelas = new Map<string, { jumlah_tersedia: number; jumlah_ekstra: number; akan_ditutup: number; belum_berlaku: number; ditutup: number }>()
    for (const t of ttBangsal) {
      const r = perKelas.get(t.kelas_id) ?? { jumlah_tersedia: 0, jumlah_ekstra: 0, akan_ditutup: 0, belum_berlaku: 0, ditutup: 0 }
      const s = statusTt(t)
      if (s === 'ditutup') r.ditutup++
      else if (s === 'belum_berlaku') r.belum_berlaku++
      else {
        if (t.ekstra) r.jumlah_ekstra++
        else r.jumlah_tersedia++
        if (s === 'akan_ditutup') r.akan_ditutup++
      }
      perKelas.set(t.kelas_id, r)
    }
    return {
      id: b.id, kode: b.kode, nama: b.nama, unit: unitRingkas(b.unit_id)!, status: b.status,
      jumlah_kamar: [...kamar.values()].filter((k) => k.bangsal_id === b.id).length,
      ringkasan_tt: [...perKelas.entries()].map(([kid, r]) => ({ kelas_rawat: ringkasKelas(kelas.get(kid)!), ...r })).sort((x, y) => x.kelas_rawat.kode.localeCompare(y.kelas_rawat.kode)),
      versi: String(b.v), diubah_pada: b.diubah_pada,
    }
  }
  function bentukKamar(k: KamarT) {
    const ruang = lokasi.get(k.lokasi_id)!
    const jumlah = [...tt.values()].filter((t) => t.kamar_id === k.id && dihitung(t)).length
    return {
      id: k.id, nomor: ruang.kode, bangsal: ringkasBangsal(bangsal.get(k.bangsal_id)!), lokasi: ringkasLokasi(ruang),
      kapasitas_maksimum: k.kapasitas_maksimum, atribut_standar: k.atribut_standar, jumlah_tempat_tidur_aktif: jumlah,
      melebihi_kapasitas: k.kapasitas_maksimum !== null && jumlah > k.kapasitas_maksimum, versi: String(k.v), diubah_pada: k.diubah_pada,
    }
  }
  function bentukTt(t: TtT) {
    const k = kamar.get(t.kamar_id)!
    const ruang = lokasi.get(k.lokasi_id)!
    return {
      id: t.id, kode: t.kode, kamar: { id: k.id, nomor: ruang.kode, kode_lengkap: kodeLengkap(ruang), nama: ruang.nama },
      bangsal: ringkasBangsal(bangsal.get(k.bangsal_id)!), lokasi_id: t.lokasi_id, kelas_rawat: ringkasKelas(kelas.get(t.kelas_id)!),
      aturan_jenis_kelamin: t.aturan_jenis_kelamin, isolasi: t.isolasi, ekstra: t.ekstra, status_operasional: statusTt(t),
      berlaku_dari: t.berlaku_dari, berlaku_sampai: t.berlaku_sampai, alasan_tutup: t.alasan_tutup, satusehat_lokasi_id: null,
      versi: String(t.v), diubah_pada: t.diubah_pada,
    }
  }
  const bentukKelasRawat = (k: KelasT) => ({ id: k.id, kode: k.kode, nama: k.nama, kode_aplicares: k.kode_aplicares, berlaku_dari: k.berlaku_dari, status: k.status, versi: String(k.v), diubah_pada: k.diubah_pada })
  const bentukPoli = (p: PoliT) => ({
    id: p.id, kode: p.kode, nama: p.nama, unit: unitRingkas(p.unit_id)!, lokasi: p.lokasi_id ? ringkasLokasi(lokasi.get(p.lokasi_id)!) : null,
    kode_poli_bpjs: p.kode_poli_bpjs, status: p.status, versi: String(p.v), diubah_pada: p.diubah_pada,
  })

  function halaman<T>(daftar: T[], kueri: URLSearchParams): ResponsTiruan {
    const batas = Number(kueri.get('batas') ?? 50)
    if (batas > 200) return error(422, 'ukuran_halaman_melebihi_batas', 'Ukuran halaman maksimum 200.')
    const mulai = kueri.get('kursor') ? Number(atob(kueri.get('kursor')!)) : 0
    return ok({ data: daftar.slice(mulai, mulai + batas), meta: { kursor_berikutnya: mulai + batas < daftar.length ? btoa(String(mulai + batas)) : null } })
  }
  function cocokVersi(v: number, header: Record<string, string | undefined>): ResponsTiruan | null {
    const im = header['if-match']
    if (!im) return error(428, 'if_match_wajib', 'Versi data tidak dikirim. Muat ulang halaman lalu coba lagi.')
    return im.replace(/^W\//, '').replace(/"/g, '').replace(/-[a-z]+$/, '') === String(v) ? null : error(412, 'versi_berubah', 'Data ini diubah orang lain sejak Anda membukanya. Muat ulang untuk melihat perubahan terbaru.')
  }
  const dasar = (isi: Isi) =>
    typeof isi.dasar_perubahan === 'string' && isi.dasar_perubahan.trim().length >= 3 ? null : error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'dasar_perubahan', pesan: 'Isi dasar perubahan, minimal 3 karakter.' }])
  const salahKolom = (status: number, kode: string, pesan: string, kolom: string) => error(status, kode, pesan, [{ kolom, pesan }])
  const etag = (v: number) => ({ ETag: `"${v}"` })

  // ── Data contoh sintetis ──
  const tambahLokasi = (indukId: string | null, jenis: JenisLokasi, kode: string, nama: string, fungsi: FungsiRuang | null = null) => {
    const l: LokasiT = { id: id(++nomor), induk_id: indukId, jenis, kode, nama, fungsi, unit_id: indukId ? (lokasi.get(indukId)!.unit_id) : (opsi.unitContoh ?? null), status: 'aktif', status_berlaku_dari: '2026-01-01', v: 1, diubah_pada: '2026-10-01T02:00:00.000Z' }
    lokasi.set(l.id, l)
    return l
  }
  const tambahKelas = (kode: string, nama: string) => {
    const k: KelasT = { id: id(++nomor), kode, nama, kode_aplicares: null, berlaku_dari: '2026-01-01', status: 'aktif', v: 1, diubah_pada: '2026-10-01T02:00:00.000Z' }
    kelas.set(k.id, k)
    return k
  }
  const tambahTt = (k: KamarT, kode: string, kelasId: string, isi: Partial<TtT> = {}) => {
    const ruang = lokasi.get(k.lokasi_id)!
    const node = tambahLokasi(ruang.id, 'tempat_tidur', kode, `TT ${kode}`)
    const t: TtT = { id: id(++nomor), kode: `${kodeLengkap(ruang)}-${kode}`, kamar_id: k.id, lokasi_id: node.id, kelas_id: kelasId, aturan_jenis_kelamin: 'campur', isolasi: false, ekstra: false, berlaku_dari: '2026-01-01', berlaku_sampai: null, alasan_tutup: null, v: 1, diubah_pada: '2026-10-01T02:00:00.000Z', ...isi }
    tt.set(t.id, t)
    kodeTtTerpakai.add(t.kode)
    return t
  }
  const gedungA = tambahLokasi(null, 'gedung', 'A', 'Gedung A (contoh)')
  const lantai1 = tambahLokasi(gedungA.id, 'lantai', '1', 'Lantai 1')
  const lantai2 = tambahLokasi(gedungA.id, 'lantai', '2', 'Lantai 2')
  tambahLokasi(gedungA.id, 'lantai', '10', 'Lantai 10')
  const r101 = tambahLokasi(lantai1.id, 'ruang', '101', 'Ruang Melati 101', 'rawat_inap')
  const r102 = tambahLokasi(lantai1.id, 'ruang', '102', 'Ruang Melati 102', 'rawat_inap')
  const poliRuang = tambahLokasi(lantai2.id, 'ruang', '201', 'Ruang Poli Anak', 'rawat_jalan')
  tambahLokasi(null, 'gedung', 'B', 'Gedung B (contoh)')
  const k3 = tambahKelas('K3', 'Kelas 3')
  const k1 = tambahKelas('K1', 'Kelas 1')
  const melati: BangsalT = { id: id(++nomor), kode: 'MELATI', nama: 'Bangsal Melati (contoh)', unit_id: opsi.unitContoh ?? '', status: 'aktif', v: 1, diubah_pada: '2026-10-01T02:00:00.000Z' }
  bangsal.set(melati.id, melati)
  const km101: KamarT = { id: id(++nomor), lokasi_id: r101.id, bangsal_id: melati.id, kapasitas_maksimum: 2, atribut_standar: { kamar_mandi_dalam: true }, v: 1, diubah_pada: '2026-10-01T02:00:00.000Z' }
  const km102: KamarT = { id: id(++nomor), lokasi_id: r102.id, bangsal_id: melati.id, kapasitas_maksimum: 4, atribut_standar: {}, v: 1, diubah_pada: '2026-10-01T02:00:00.000Z' }
  kamar.set(km101.id, km101)
  kamar.set(km102.id, km102)
  tambahTt(km101, '1', k3.id)
  tambahTt(km101, '2', k3.id)
  tambahTt(km101, '3', k3.id, { ekstra: true })
  tambahTt(km102, '1', k1.id)
  tambahTt(km102, '2', k1.id, { berlaku_dari: '2025-01-01', berlaku_sampai: '2025-12-31', alasan_tutup: 'Renovasi permanen' })
  const poliAnak: PoliT = { id: id(++nomor), kode: 'ANAK', nama: 'Poli Anak (contoh)', unit_id: opsi.unitContoh ?? '', lokasi_id: poliRuang.id, kode_poli_bpjs: null, status: 'aktif', v: 1, diubah_pada: '2026-10-01T02:00:00.000Z' }
  poli.set(poliAnak.id, poliAnak)

  const unitValid = (uid: unknown) => typeof uid === 'string' && (unitDari(uid)?.aktif ?? false)

  /** Syarat status lokasi (blok aturan kontrak sesudah #46). */
  function syaratStatus(l: LokasiT, ke: StatusLokasi): ResponsTiruan | null {
    if (ke === l.status) return null
    const sub = [l, ...turunan(l.id)]
    const ttBelumTutup = [...tt.values()].filter((t) => sub.some((s) => s.id === t.lokasi_id) && statusTt(t) !== 'ditutup').length
    const poliAktif = [...poli.values()].filter((p) => p.status === 'aktif' && p.lokasi_id && sub.some((s) => s.id === p.lokasi_id)).length
    const anakBelumTutup = turunan(l.id).filter((x) => x.jenis !== 'tempat_tidur' && x.status !== 'ditutup').length
    if (ke === 'aktif' || ke === 'renovasi') {
      const induk = l.induk_id ? lokasi.get(l.induk_id) : undefined
      if (l.status === 'ditutup' && induk?.status === 'ditutup') return salahKolom(422, 'induk_tidak_aktif', 'Induk lokasi ini ditutup. Buka induknya lebih dulu.', 'status')
    }
    if ((ke === 'renovasi' || ke === 'ditutup') && (ttBelumTutup || poliAktif || (ke === 'ditutup' && anakBelumTutup))) {
      return error(409, 'lokasi_masih_dipakai', 'Lokasi masih dipakai: tutup TT, pindahkan poliklinik, atau tutup turunannya lebih dulu.', [
        { jumlah_anak_aktif: ke === 'ditutup' ? anakBelumTutup : 0, jumlah_tempat_tidur: ttBelumTutup, jumlah_poliklinik: poliAktif },
      ])
    }
    return null
  }

  /** Impor CSV (sinkron). Mengembalikan kesalahan per baris; `terapkan` menyimpan bila tanpa kesalahan. */
  function impor(teks: string, mode: 'pratinjau' | 'terapkan'): ResponsTiruan {
    const bersih = teks.replace(/^﻿/, '')
    const baris = bersih.split(/\r?\n/).filter((x) => x.trim() !== '')
    const pemisah = baris[0]?.includes(';') && !baris[0]?.includes(',') ? ';' : ','
    if (!baris.length || baris[0]!.split(pemisah).join(',') !== JUDUL_IMPOR) {
      return error(422, 'berkas_tidak_valid', 'Judul kolom tidak sesuai templat.', [{ kolom: 'berkas', pesan: `Baris pertama harus: ${JUDUL_IMPOR}` }])
    }
    if (baris.length - 1 > 5000) return error(422, 'berkas_tidak_valid', 'Berkas melebihi 5.000 baris data.', [{ kolom: 'berkas', pesan: 'Pecah berkas menjadi beberapa bagian.' }])
    const data = baris.slice(1).map((b, i) => {
      const [jenis = '', kode = '', induk = '', nama = '', fungsi = '', unit = '', bangsalKode = '', kelasKode = '', kapasitas = '', ekstra = ''] = b.split(pemisah).map((x) => x.trim())
      return { no: i + 2, jenis, kode, induk, nama, fungsi, unit, bangsalKode, kelasKode, kapasitas, ekstra }
    })
    const kesalahan: KesalahanBarisImpor[] = []
    const salah = (no: number, kolom: string | null, kode: string, pesan: string) => kesalahan.push({ baris: no, kolom, kode, pesan })
    const lengkapDb = new Map([...lokasi.values()].map((l) => [kodeLengkap(l), l]))
    const lengkapBerkas = new Map<string, (typeof data)[number]>()
    for (const d of data) {
      const kl = d.induk ? `${d.induk}-${d.kode}` : d.kode
      if (lengkapBerkas.has(kl)) salah(d.no, 'kode', 'kode_ganda', `Kode ${kl} muncul lebih dari sekali di berkas.`)
      else lengkapBerkas.set(kl, d)
    }
    const jenisInduk = (d: (typeof data)[number]): string | null => (d.induk ? (lengkapDb.get(d.induk)?.jenis ?? lengkapBerkas.get(d.induk)?.jenis ?? null) : 'akar')
    for (const d of data) {
      const kl = d.induk ? `${d.induk}-${d.kode}` : d.kode
      if (!['situs', 'gedung', 'sayap', 'lantai', 'ruang', 'tempat_tidur'].includes(d.jenis)) { salah(d.no, 'jenis', 'jenis_tidak_dikenal', `Jenis "${d.jenis}" tidak dikenal.`); continue }
      if (!KODE_LOKASI.test(d.kode)) { salah(d.no, 'kode', 'kode_tidak_valid', 'Kode 1–10 huruf besar atau angka, tanpa tanda hubung.'); continue }
      if (lengkapDb.has(kl) || kodeTtTerpakai.has(kl)) { salah(d.no, 'kode', 'kode_sudah_dipakai', `Kode ${kl} sudah dipakai.`); continue }
      const ji = jenisInduk(d)
      if (ji === null) { salah(d.no, 'induk', 'induk_tidak_ditemukan', `Induk ${d.induk} tidak ada di basis data maupun berkas.`); continue }
      if (!ANAK[ji]!.includes(d.jenis as JenisLokasi)) { salah(d.no, 'induk', 'jenis_induk_tidak_sesuai', `${d.jenis} tidak boleh di bawah ${ji === 'akar' ? 'akar' : ji}.`); continue }
      if (d.induk && lengkapDb.get(d.induk)?.status === 'ditutup') { salah(d.no, 'induk', 'induk_tidak_aktif', `Induk ${d.induk} ditutup.`); continue }
      if (d.jenis !== 'tempat_tidur' && !d.nama) { salah(d.no, 'nama', 'kolom_wajib', 'Nama wajib diisi.'); continue }
      if (d.jenis === 'ruang' && !FUNGSI.includes(d.fungsi as FungsiRuang)) { salah(d.no, 'fungsi', 'fungsi_wajib', 'Fungsi ruang wajib diisi.'); continue }
      if (d.jenis !== 'ruang' && d.fungsi) { salah(d.no, 'fungsi', 'fungsi_hanya_ruang', 'Fungsi hanya untuk ruang.'); continue }
      if (d.bangsalKode && ![...bangsal.values()].some((b) => b.kode === d.bangsalKode && b.status === 'aktif')) { salah(d.no, 'bangsal', 'bangsal_tidak_valid', `Bangsal ${d.bangsalKode} tidak ada atau nonaktif.`); continue }
      if (d.jenis === 'tempat_tidur') {
        if (!d.kelasKode || ![...kelas.values()].some((k) => k.kode === d.kelasKode && k.status === 'aktif')) { salah(d.no, 'kelas_rawat', 'kelas_rawat_tidak_valid', `Kelas rawat ${d.kelasKode || '(kosong)'} tidak ada atau nonaktif.`); continue }
        const indukDb = lengkapDb.get(d.induk)
        const jadiKamar = indukDb ? [...kamar.values()].some((k) => k.lokasi_id === indukDb.id) : !!lengkapBerkas.get(d.induk)?.bangsalKode
        if (!jadiKamar) { salah(d.no, 'induk', 'bukan_kamar', `Ruang ${d.induk} belum menjadi kamar bangsal.`); continue }
      }
      if (d.ekstra && !['ya', 'tidak'].includes(d.ekstra)) salah(d.no, 'ekstra', 'nilai_tidak_valid', 'Isi "ya" atau "tidak".')
    }
    const per_jenis = { situs: 0, gedung: 0, sayap: 0, lantai: 0, ruang: 0, tempat_tidur: 0, kamar: 0 }
    for (const d of data) {
      if (d.jenis in per_jenis) per_jenis[d.jenis as keyof typeof per_jenis]++
      if (d.jenis === 'ruang' && d.bangsalKode) per_jenis.kamar++
    }
    kesalahan.sort((x, y) => x.baris - y.baris)
    const hasil = { mode, diterapkan: false, jumlah_baris: data.length, jumlah_salah: new Set(kesalahan.map((k) => k.baris)).size, per_jenis, kesalahan: kesalahan.slice(0, 500), kesalahan_terpotong: kesalahan.length > 500 }
    if (mode === 'pratinjau') return ok({ data: hasil })
    if (kesalahan.length) return error(422, 'impor_tidak_valid', 'Ada baris yang salah; tidak ada yang disimpan.', kesalahan.slice(0, 500) as unknown as Record<string, unknown>[])
    // Terapkan: urut dari akar (induk dulu).
    const sisa = [...data]
    while (sisa.length) {
      const i = sisa.findIndex((d) => !d.induk || [...lokasi.values()].some((l) => kodeLengkap(l) === d.induk))
      const d = sisa.splice(i, 1)[0]!
      const induk = d.induk ? [...lokasi.values()].find((l) => kodeLengkap(l) === d.induk)! : null
      if (d.jenis === 'tempat_tidur') {
        const k = [...kamar.values()].find((x) => x.lokasi_id === induk!.id)!
        tambahTt(k, d.kode, [...kelas.values()].find((x) => x.kode === d.kelasKode)!.id, { ekstra: d.ekstra === 'ya', berlaku_dari: hariIni() })
        continue
      }
      const l = tambahLokasi(induk?.id ?? null, d.jenis as JenisLokasi, d.kode, d.nama, (d.fungsi || null) as FungsiRuang | null)
      if (d.bangsalKode) {
        const b = [...bangsal.values()].find((x) => x.kode === d.bangsalKode)!
        kamar.set(id(++nomor), { id: id(nomor), lokasi_id: l.id, bangsal_id: b.id, kapasitas_maksimum: d.kapasitas ? Number(d.kapasitas) : null, atribut_standar: {}, v: 1, diubah_pada: iso() })
      }
    }
    return ok({ data: { ...hasil, diterapkan: true } })
  }

  function tangani(r: PermintaanTiruan): ResponsTiruan | Promise<ResponsTiruan> | null {
    const isi = (r.isi ?? {}) as Isi
    const [a0, a1, a2] = r.jalur.replace(/\/+$/, '').split('/').filter(Boolean)
    const m = r.metode.toUpperCase()
    const q = r.kueri

    if (a0 === 'lokasi' && a1 === 'impor' && m === 'POST') {
      const fd = r.isi instanceof FormData ? r.isi : null
      const berkas = fd?.get('berkas')
      const mode = fd?.get('mode') === 'terapkan' ? 'terapkan' : 'pratinjau'
      const d = String(fd?.get('dasar_perubahan') ?? '')
      if (d.trim().length < 3) return salahKolom(422, 'validasi_gagal', 'Isi dasar perubahan, minimal 3 karakter.', 'dasar_perubahan')
      if (!(berkas instanceof Blob)) return salahKolom(422, 'validasi_gagal', 'Pilih berkas CSV.', 'berkas')
      return berkas.text().then((teks) => impor(teks, mode))
    }

    if (a0 === 'lokasi') {
      if (m === 'GET' && !a1) {
        const cari = (q.get('cari') ?? '').toLowerCase()
        const induk = q.get('induk_id')
        let daftar = [...lokasi.values()]
        if (cari) daftar = daftar.filter((l) => kodeLengkap(l).toLowerCase().includes(cari) || l.nama.toLowerCase().includes(cari) || (ttDiLokasi(l.id)?.kode.toLowerCase().includes(cari) ?? false))
        else if (induk) daftar = daftar.filter((l) => l.induk_id === induk)
        else if (q.get('akar') === 'true') daftar = daftar.filter((l) => l.induk_id === null)
        const bentuk = daftar.map(bentukLokasi)
          .filter((l) => !q.get('jenis') || l.jenis === q.get('jenis'))
          .filter((l) => !q.get('status') || l.status === q.get('status'))
          .sort((x, y) => x.kode_lengkap.localeCompare(y.kode_lengkap))
        return halaman(bentuk, q)
      }
      if (m === 'POST' && !a1) {
        const dd = dasar(isi)
        if (dd) return dd
        const jenis = isi.jenis as JenisLokasi
        if (jenis === 'tempat_tidur') return error(422, 'kelola_lewat_tempat_tidur', 'Tempat tidur dibuat lewat menu tempat tidur.')
        const kode = String(isi.kode ?? '')
        if (!KODE_LOKASI.test(kode)) return salahKolom(422, 'validasi_gagal', 'Kode 1–10 huruf besar atau angka, tanpa tanda hubung.', 'kode')
        const nama = String(isi.nama ?? '').trim()
        if (nama.length < 2) return salahKolom(422, 'validasi_gagal', 'Nama minimal 2 karakter.', 'nama')
        const indukId = (isi.induk_id as string | null) ?? null
        const induk = indukId ? lokasi.get(indukId) : null
        if (indukId && !induk) return salahKolom(422, 'validasi_gagal', 'Induk tidak ada.', 'induk_id')
        if (!ANAK[induk?.jenis ?? 'akar']!.includes(jenis)) return salahKolom(422, 'jenis_induk_tidak_sesuai', `${jenis} tidak boleh di bawah ${induk?.jenis ?? 'akar'}.`, 'induk_id')
        if (induk?.status === 'ditutup') return salahKolom(422, 'induk_tidak_aktif', 'Induk ditutup.', 'induk_id')
        if (jenis === 'ruang' && !isi.fungsi) return salahKolom(422, 'fungsi_wajib', 'Fungsi ruang wajib diisi.', 'fungsi')
        if (jenis !== 'ruang' && isi.fungsi) return salahKolom(422, 'fungsi_hanya_ruang', 'Fungsi hanya untuk ruang.', 'fungsi')
        if (isi.unit_organisasi_id && !unitValid(isi.unit_organisasi_id)) return salahKolom(422, 'unit_tidak_valid', 'Unit tidak ada atau nonaktif.', 'unit_organisasi_id')
        const kl = induk ? `${kodeLengkap(induk)}-${kode}` : kode
        if ([...lokasi.values()].some((l) => kodeLengkap(l) === kl) || kodeTtTerpakai.has(kl)) return salahKolom(409, 'kode_sudah_dipakai', `Kode ${kl} sudah dipakai.`, 'kode')
        const l = tambahLokasi(indukId, jenis, kode, nama, (isi.fungsi as FungsiRuang | null) ?? null)
        if (isi.unit_organisasi_id) l.unit_id = isi.unit_organisasi_id as string
        l.status_berlaku_dari = hariIni()
        l.diubah_pada = iso()
        return ok({ data: bentukLokasi(l) }, 201, etag(l.v))
      }
      const l = a1 ? lokasi.get(a1) : undefined
      if (!l) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
      if (m === 'GET') return ok({ data: bentukLokasi(l) }, 200, etag(l.v))
      if (m === 'PATCH') {
        const v = cocokVersi(l.v, r.header)
        if (v) return v
        const dd = dasar(isi)
        if (dd) return dd
        if (l.jenis === 'tempat_tidur') return error(422, 'kelola_lewat_tempat_tidur', 'Tempat tidur diubah lewat menu tempat tidur.')
        if ('induk_id' in isi && isi.induk_id !== l.induk_id) {
          const baru = (isi.induk_id as string | null) ?? null
          const ib = baru ? lokasi.get(baru) : null
          if (baru && (baru === l.id || turunan(l.id).some((t) => t.id === baru))) return salahKolom(422, 'siklus', 'Induk baru tidak boleh lokasi ini atau turunannya.', 'induk_id')
          if (!ANAK[ib?.jenis ?? 'akar']!.includes(l.jenis)) return salahKolom(422, 'jenis_induk_tidak_sesuai', `${l.jenis} tidak boleh di bawah ${ib?.jenis ?? 'akar'}.`, 'induk_id')
          if (ib?.status === 'ditutup') return salahKolom(422, 'induk_tidak_aktif', 'Induk ditutup.', 'induk_id')
          l.induk_id = baru
        }
        if (typeof isi.kode === 'string' && isi.kode !== l.kode) {
          const kl = l.induk_id ? `${kodeLengkap(lokasi.get(l.induk_id)!)}-${isi.kode}` : isi.kode
          if ([...lokasi.values()].some((x) => x.id !== l.id && kodeLengkap(x) === kl)) return salahKolom(409, 'kode_sudah_dipakai', `Kode ${kl} sudah dipakai.`, 'kode')
          l.kode = isi.kode
        }
        if (typeof isi.nama === 'string') l.nama = isi.nama
        if ('fungsi' in isi) l.fungsi = (isi.fungsi as FungsiRuang | null) ?? null
        if ('unit_organisasi_id' in isi) {
          if (isi.unit_organisasi_id && !unitValid(isi.unit_organisasi_id)) return salahKolom(422, 'unit_tidak_valid', 'Unit tidak ada atau nonaktif.', 'unit_organisasi_id')
          l.unit_id = (isi.unit_organisasi_id as string | null) ?? null
        }
        if (isi.status === 'aktif' || isi.status === 'renovasi' || isi.status === 'ditutup') {
          const tgl = (isi.status_berlaku_dari as string | null) ?? hariIni()
          if (tgl > hariIni()) return salahKolom(422, 'tanggal_tidak_valid', 'Tanggal berlaku tidak boleh di masa depan (penjadwalan lewat usulan).', 'status_berlaku_dari')
          const syarat = syaratStatus(l, isi.status)
          if (syarat) return syarat
          if (isi.status !== l.status) {
            l.status = isi.status
            l.status_berlaku_dari = tgl
          }
        }
        l.v++
        l.diubah_pada = iso()
        return ok({ data: bentukLokasi(l) }, 200, etag(l.v))
      }
    }

    if (a0 === 'bangsal') {
      if (m === 'GET' && !a1) {
        const cari = (q.get('cari') ?? '').toLowerCase()
        const daftar = [...bangsal.values()]
          .filter((b) => (!cari || b.kode.toLowerCase().includes(cari) || b.nama.toLowerCase().includes(cari)) && (!q.get('status') || b.status === q.get('status')))
          .sort((x, y) => x.kode.localeCompare(y.kode))
          .map(bentukBangsal)
        return halaman(daftar, q)
      }
      if (m === 'POST' && !a1) {
        const dd = dasar(isi)
        if (dd) return dd
        const kode = String(isi.kode ?? '')
        if (!KODE_DATA.test(kode)) return salahKolom(422, 'validasi_gagal', 'Kode huruf besar/angka, maks. 30.', 'kode')
        if (!unitValid(isi.unit_organisasi_id)) return salahKolom(422, 'unit_tidak_valid', 'Unit tidak ada atau nonaktif.', 'unit_organisasi_id')
        if ([...bangsal.values()].some((b) => b.kode === kode)) return salahKolom(409, 'kode_sudah_dipakai', `Kode ${kode} sudah dipakai.`, 'kode')
        const b: BangsalT = { id: id(++nomor), kode, nama: String(isi.nama ?? '').trim(), unit_id: isi.unit_organisasi_id as string, status: 'aktif', v: 1, diubah_pada: iso() }
        bangsal.set(b.id, b)
        return ok({ data: bentukBangsal(b) }, 201, etag(b.v))
      }
      const b = a1 ? bangsal.get(a1) : undefined
      if (!b) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
      if (m === 'GET') return ok({ data: bentukBangsal(b) }, 200, etag(b.v))
      if (m === 'PATCH') {
        const v = cocokVersi(b.v, r.header)
        if (v) return v
        const dd = dasar(isi)
        if (dd) return dd
        if (typeof isi.kode === 'string' && isi.kode !== b.kode) {
          if ([...bangsal.values()].some((x) => x.kode === isi.kode)) return salahKolom(409, 'kode_sudah_dipakai', `Kode ${isi.kode} sudah dipakai.`, 'kode')
          b.kode = isi.kode
        }
        if (typeof isi.nama === 'string') b.nama = isi.nama
        if (typeof isi.unit_organisasi_id === 'string') {
          if (!unitValid(isi.unit_organisasi_id)) return salahKolom(422, 'unit_tidak_valid', 'Unit tidak ada atau nonaktif.', 'unit_organisasi_id')
          b.unit_id = isi.unit_organisasi_id
        }
        if (isi.status === 'nonaktif' && b.status === 'aktif') {
          const n = [...tt.values()].filter((t) => kamar.get(t.kamar_id)?.bangsal_id === b.id && statusTt(t) !== 'ditutup').length
          if (n) return error(409, 'bangsal_masih_dipakai', `Bangsal masih punya ${n} TT yang belum ditutup.`, [{ jumlah_tempat_tidur: n }])
        }
        if (isi.status === 'aktif' || isi.status === 'nonaktif') b.status = isi.status
        b.v++
        b.diubah_pada = iso()
        return ok({ data: bentukBangsal(b) }, 200, etag(b.v))
      }
    }

    if (a0 === 'kamar') {
      if (m === 'GET' && !a1) {
        const cari = (q.get('cari') ?? '').toLowerCase()
        const daftar = [...kamar.values()]
          .map(bentukKamar)
          .filter((k) => (!q.get('bangsal_id') || k.bangsal.id === q.get('bangsal_id')) && (q.get('melebihi_kapasitas') !== 'true' || k.melebihi_kapasitas))
          .filter((k) => !cari || k.lokasi.kode_lengkap.toLowerCase().includes(cari) || k.lokasi.nama.toLowerCase().includes(cari))
          .sort((x, y) => x.lokasi.kode_lengkap.localeCompare(y.lokasi.kode_lengkap))
        return halaman(daftar, q)
      }
      if (m === 'POST' && !a1) {
        const dd = dasar(isi)
        if (dd) return dd
        const ruang = typeof isi.lokasi_id === 'string' ? lokasi.get(isi.lokasi_id) : undefined
        if (!ruang || ruang.jenis !== 'ruang' || ruang.status !== 'aktif') return salahKolom(422, 'bukan_ruang', 'Pilih ruang yang aktif.', 'lokasi_id')
        if ([...kamar.values()].some((k) => k.lokasi_id === ruang.id)) return salahKolom(409, 'ruang_sudah_kamar', 'Ruang ini sudah menjadi kamar.', 'lokasi_id')
        const b = typeof isi.bangsal_id === 'string' ? bangsal.get(isi.bangsal_id) : undefined
        if (!b || b.status !== 'aktif') return salahKolom(422, 'bangsal_tidak_valid', 'Bangsal tidak ada atau nonaktif.', 'bangsal_id')
        const k: KamarT = { id: id(++nomor), lokasi_id: ruang.id, bangsal_id: b.id, kapasitas_maksimum: (isi.kapasitas_maksimum as number | null) ?? null, atribut_standar: (isi.atribut_standar as AtributKamar) ?? {}, v: 1, diubah_pada: iso() }
        kamar.set(k.id, k)
        return ok({ data: bentukKamar(k) }, 201, etag(k.v))
      }
      const k = a1 ? kamar.get(a1) : undefined
      if (!k) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
      if (m === 'GET') return ok({ data: bentukKamar(k) }, 200, etag(k.v))
      if (m === 'PATCH') {
        const v = cocokVersi(k.v, r.header)
        if (v) return v
        const dd = dasar(isi)
        if (dd) return dd
        if (typeof isi.bangsal_id === 'string') {
          const b = bangsal.get(isi.bangsal_id)
          if (!b || b.status !== 'aktif') return salahKolom(422, 'bangsal_tidak_valid', 'Bangsal tidak ada atau nonaktif.', 'bangsal_id')
          k.bangsal_id = b.id
        }
        if ('kapasitas_maksimum' in isi) k.kapasitas_maksimum = (isi.kapasitas_maksimum as number | null) ?? null
        if (isi.atribut_standar && typeof isi.atribut_standar === 'object') k.atribut_standar = isi.atribut_standar as AtributKamar
        k.v++
        k.diubah_pada = iso()
        return ok({ data: bentukKamar(k) }, 200, etag(k.v))
      }
    }

    if (a0 === 'tempat-tidur') {
      if (m === 'GET' && !a1) {
        const cari = (q.get('cari') ?? '').toLowerCase()
        const lid = q.get('lokasi_id')
        const sub = lid ? new Set([lid, ...turunan(lid).map((x) => x.id)]) : null
        const daftar = [...tt.values()]
          .filter((t) => (!q.get('kamar_id') || t.kamar_id === q.get('kamar_id')) && (!q.get('bangsal_id') || kamar.get(t.kamar_id)?.bangsal_id === q.get('bangsal_id')))
          .filter((t) => (!sub || sub.has(t.lokasi_id)) && (!q.get('kelas_rawat_id') || t.kelas_id === q.get('kelas_rawat_id')))
          .filter((t) => (!q.get('status_operasional') || statusTt(t) === q.get('status_operasional')) && (!cari || t.kode.toLowerCase().includes(cari)))
          .sort((x, y) => x.kode.localeCompare(y.kode))
          .map(bentukTt)
        return halaman(daftar, q)
      }
      if (m === 'POST' && !a1) {
        const dd = dasar(isi)
        if (dd) return dd
        const k = typeof isi.kamar_id === 'string' ? kamar.get(isi.kamar_id) : undefined
        const ruang = k ? lokasi.get(k.lokasi_id) : undefined
        if (!k || !ruang || ruang.status !== 'aktif' || bangsal.get(k.bangsal_id)?.status !== 'aktif') return salahKolom(422, 'kamar_tidak_valid', 'Ruang kamar tidak aktif atau bangsalnya nonaktif.', 'kamar_id')
        const kode = String(isi.kode ?? '')
        if (!KODE_LOKASI.test(kode)) return salahKolom(422, 'validasi_gagal', 'Kode 1–10 huruf besar atau angka.', 'kode')
        const kodeTt = `${kodeLengkap(ruang)}-${kode}`
        if (kodeTtTerpakai.has(kodeTt) || [...lokasi.values()].some((l) => kodeLengkap(l) === kodeTt)) return salahKolom(409, 'kode_sudah_dipakai', `Kode TT ${kodeTt} pernah dipakai.`, 'kode')
        const kl = typeof isi.kelas_rawat_id === 'string' ? kelas.get(isi.kelas_rawat_id) : undefined
        if (!kl || kl.status !== 'aktif') return salahKolom(422, 'kelas_rawat_tidak_valid', 'Kelas rawat tidak ada atau nonaktif.', 'kelas_rawat_id')
        const dari = (isi.berlaku_dari as string | null) ?? hariIni()
        if (dari < hariIni()) return salahKolom(422, 'tanggal_tidak_valid', 'Berlaku dari tidak boleh sebelum hari ini.', 'berlaku_dari')
        const t = tambahTt(k, kode, kl.id, { aturan_jenis_kelamin: (isi.aturan_jenis_kelamin as AturanJenisKelamin) ?? 'campur', isolasi: isi.isolasi === true, ekstra: isi.ekstra === true, berlaku_dari: dari, diubah_pada: iso() })
        const km = bentukKamar(k)
        return ok({ data: bentukTt(t), peringatan: km.melebihi_kapasitas ? ['melebihi_kapasitas'] : [] }, 201, etag(t.v))
      }
      const t = a1 ? tt.get(a1) : undefined
      if (!t) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
      if (m === 'GET' && !a2) return ok({ data: bentukTt(t) }, 200, etag(t.v))
      if (m === 'PATCH' && !a2) {
        const v = cocokVersi(t.v, r.header)
        if (v) return v
        const dd = dasar(isi)
        if (dd) return dd
        if (statusTt(t) === 'ditutup' || statusTt(t) === 'akan_ditutup') return error(409, 'sudah_ditutup', 'TT yang ditutup atau dijadwalkan tutup tidak bisa diubah.')
        if (typeof isi.kelas_rawat_id === 'string') {
          const kl = kelas.get(isi.kelas_rawat_id)
          if (!kl || kl.status !== 'aktif') return salahKolom(422, 'kelas_rawat_tidak_valid', 'Kelas rawat tidak ada atau nonaktif.', 'kelas_rawat_id')
          t.kelas_id = kl.id
        }
        if (typeof isi.aturan_jenis_kelamin === 'string') t.aturan_jenis_kelamin = isi.aturan_jenis_kelamin as AturanJenisKelamin
        if (typeof isi.isolasi === 'boolean') t.isolasi = isi.isolasi
        if (typeof isi.ekstra === 'boolean') t.ekstra = isi.ekstra
        t.v++
        t.diubah_pada = iso()
        return ok({ data: bentukTt(t) }, 200, etag(t.v))
      }
      if (m === 'POST' && a2 === 'tutup') {
        const v = cocokVersi(t.v, r.header)
        if (v) return v
        const dd = dasar(isi)
        if (dd) return dd
        const alasan = String(isi.alasan ?? '').trim()
        if (alasan.length < 5) return salahKolom(422, 'validasi_gagal', 'Tulis alasan, minimal 5 karakter.', 'alasan')
        if (statusTt(t) === 'ditutup' || statusTt(t) === 'akan_ditutup') return error(409, 'sudah_ditutup', 'TT sudah ditutup atau dijadwalkan tutup.')
        const mulai = String(isi.berlaku_dari ?? '')
        if (!/^\d{4}-\d{2}-\d{2}$/.test(mulai) || mulai < hariIni() || mulai < t.berlaku_dari) return salahKolom(422, 'tanggal_tidak_valid', 'Tanggal mulai tidak dihitung tidak boleh sebelum hari ini.', 'berlaku_dari')
        // berlaku_sampai = hari terakhir dihitung = sehari sebelum tanggal mulai tidak dihitung.
        const sampai = new Date(`${mulai}T00:00:00Z`)
        sampai.setUTCDate(sampai.getUTCDate() - 1)
        t.berlaku_sampai = sampai.toISOString().slice(0, 10)
        t.alasan_tutup = alasan
        t.v++
        t.diubah_pada = iso()
        return ok({ data: bentukTt(t) }, 200, etag(t.v))
      }
    }

    if (a0 === 'kelas-rawat') {
      if (m === 'GET' && !a1) return halaman([...kelas.values()].filter((k) => !q.get('status') || k.status === q.get('status')).sort((x, y) => x.kode.localeCompare(y.kode)).map(bentukKelasRawat), q)
      if (m === 'POST' && !a1) {
        const dd = dasar(isi)
        if (dd) return dd
        const kode = String(isi.kode ?? '')
        if (!KODE_DATA.test(kode)) return salahKolom(422, 'validasi_gagal', 'Kode huruf besar/angka, maks. 30.', 'kode')
        if ([...kelas.values()].some((k) => k.kode === kode)) return salahKolom(409, 'kode_sudah_dipakai', `Kode ${kode} sudah dipakai.`, 'kode')
        const k: KelasT = { id: id(++nomor), kode, nama: String(isi.nama ?? '').trim(), kode_aplicares: (isi.kode_aplicares as string | null) ?? null, berlaku_dari: (isi.berlaku_dari as string | null) ?? hariIni(), status: 'aktif', v: 1, diubah_pada: iso() }
        kelas.set(k.id, k)
        return ok({ data: bentukKelasRawat(k) }, 201, etag(k.v))
      }
      const k = a1 ? kelas.get(a1) : undefined
      if (!k) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
      if (m === 'GET') return ok({ data: bentukKelasRawat(k) }, 200, etag(k.v))
      if (m === 'PATCH') {
        const v = cocokVersi(k.v, r.header)
        if (v) return v
        const dd = dasar(isi)
        if (dd) return dd
        if (typeof isi.kode === 'string' && isi.kode !== k.kode) {
          if ([...kelas.values()].some((x) => x.kode === isi.kode)) return salahKolom(409, 'kode_sudah_dipakai', `Kode ${isi.kode} sudah dipakai.`, 'kode')
          k.kode = isi.kode
        }
        if (typeof isi.nama === 'string') k.nama = isi.nama
        if ('kode_aplicares' in isi) k.kode_aplicares = (isi.kode_aplicares as string | null) ?? null
        if (isi.status === 'nonaktif' && k.status === 'aktif') {
          const n = [...tt.values()].filter((t) => t.kelas_id === k.id && statusTt(t) !== 'ditutup').length
          if (n) return error(409, 'kelas_masih_dipakai', `Kelas masih dipakai ${n} TT yang belum ditutup.`, [{ jumlah_tempat_tidur: n }])
        }
        if (isi.status === 'aktif' || isi.status === 'nonaktif') k.status = isi.status
        k.v++
        k.diubah_pada = iso()
        return ok({ data: bentukKelasRawat(k) }, 200, etag(k.v))
      }
    }

    if (a0 === 'poliklinik') {
      if (m === 'GET' && !a1) {
        const cari = (q.get('cari') ?? '').toLowerCase()
        const lid = q.get('lokasi_id')
        const sub = lid ? new Set([lid, ...turunan(lid).map((x) => x.id)]) : null
        const daftar = [...poli.values()]
          .filter((p) => (!cari || p.kode.toLowerCase().includes(cari) || p.nama.toLowerCase().includes(cari)) && (!q.get('status') || p.status === q.get('status')))
          .filter((p) => !sub || (p.lokasi_id !== null && sub.has(p.lokasi_id)))
          .sort((x, y) => x.kode.localeCompare(y.kode))
          .map(bentukPoli)
        return halaman(daftar, q)
      }
      const ruangValid = (lid: unknown) => lid === null || lid === undefined || (typeof lid === 'string' && lokasi.get(lid)?.jenis === 'ruang' && lokasi.get(lid)?.status === 'aktif')
      if (m === 'POST' && !a1) {
        const dd = dasar(isi)
        if (dd) return dd
        const kode = String(isi.kode ?? '')
        if (!KODE_DATA.test(kode)) return salahKolom(422, 'validasi_gagal', 'Kode huruf besar/angka, maks. 30.', 'kode')
        if (!unitValid(isi.unit_organisasi_id)) return salahKolom(422, 'unit_tidak_valid', 'Unit tidak ada atau nonaktif.', 'unit_organisasi_id')
        if (!ruangValid(isi.lokasi_id)) return salahKolom(422, 'bukan_ruang', 'Pilih ruang yang aktif.', 'lokasi_id')
        if ([...poli.values()].some((p) => p.kode === kode)) return salahKolom(409, 'kode_sudah_dipakai', `Kode ${kode} sudah dipakai.`, 'kode')
        const p: PoliT = { id: id(++nomor), kode, nama: String(isi.nama ?? '').trim(), unit_id: isi.unit_organisasi_id as string, lokasi_id: (isi.lokasi_id as string | null) ?? null, kode_poli_bpjs: (isi.kode_poli_bpjs as string | null) ?? null, status: 'aktif', v: 1, diubah_pada: iso() }
        poli.set(p.id, p)
        return ok({ data: bentukPoli(p) }, 201, etag(p.v))
      }
      const p = a1 ? poli.get(a1) : undefined
      if (!p) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
      if (m === 'GET') return ok({ data: bentukPoli(p) }, 200, etag(p.v))
      if (m === 'PATCH') {
        const v = cocokVersi(p.v, r.header)
        if (v) return v
        const dd = dasar(isi)
        if (dd) return dd
        if (typeof isi.kode === 'string' && isi.kode !== p.kode) {
          if ([...poli.values()].some((x) => x.kode === isi.kode)) return salahKolom(409, 'kode_sudah_dipakai', `Kode ${isi.kode} sudah dipakai.`, 'kode')
          p.kode = isi.kode
        }
        if (typeof isi.nama === 'string') p.nama = isi.nama
        if (typeof isi.unit_organisasi_id === 'string') {
          if (!unitValid(isi.unit_organisasi_id)) return salahKolom(422, 'unit_tidak_valid', 'Unit tidak ada atau nonaktif.', 'unit_organisasi_id')
          p.unit_id = isi.unit_organisasi_id
        }
        if ('lokasi_id' in isi) {
          if (!ruangValid(isi.lokasi_id)) return salahKolom(422, 'bukan_ruang', 'Pilih ruang yang aktif.', 'lokasi_id')
          p.lokasi_id = (isi.lokasi_id as string | null) ?? null
        }
        if ('kode_poli_bpjs' in isi) p.kode_poli_bpjs = (isi.kode_poli_bpjs as string | null) ?? null
        if (isi.status === 'aktif' || isi.status === 'nonaktif') p.status = isi.status
        p.v++
        p.diubah_pada = iso()
        return ok({ data: bentukPoli(p) }, 200, etag(p.v))
      }
    }

    if (a0 === 'kapasitas-tempat-tidur' && m === 'GET') {
      const tanggal = q.get('tanggal') ?? hariIni()
      const daftar = [...bangsal.values()]
        .filter((b) => !q.get('bangsal_id') || b.id === q.get('bangsal_id'))
        .sort((x, y) => x.kode.localeCompare(y.kode))
        .map((b) => {
          const perKelas = new Map<string, { jumlah_tersedia: number; jumlah_ekstra: number }>()
          for (const t of tt.values()) {
            if (kamar.get(t.kamar_id)?.bangsal_id !== b.id || t.berlaku_dari > tanggal || (t.berlaku_sampai !== null && t.berlaku_sampai < tanggal)) continue
            const r2 = perKelas.get(t.kelas_id) ?? { jumlah_tersedia: 0, jumlah_ekstra: 0 }
            if (t.ekstra) r2.jumlah_ekstra++
            else r2.jumlah_tersedia++
            perKelas.set(t.kelas_id, r2)
          }
          return { tanggal, sumber: tanggal < hariIni() ? 'tersimpan' : 'dihitung', bangsal: ringkasBangsal(b), kelas: [...perKelas.entries()].map(([kid, r2]) => ({ kelas_rawat: ringkasKelas(kelas.get(kid)!), ...r2 })) }
        })
      return halaman(daftar, q)
    }
    return null
  }

  // ── Untuk server tiruan usulan (1.8c): potret nilai objek dan label nilai referensi ──
  function potretObjek(jenis: 'ruang' | 'kamar' | 'tempat_tidur', id: string): { label: string; nilai: Record<string, unknown> } | null {
    if (jenis === 'tempat_tidur') {
      const t = tt.get(id)
      return t ? { label: `TT ${t.kode}`, nilai: { kelas_rawat_id: t.kelas_id, aturan_jenis_kelamin: t.aturan_jenis_kelamin, isolasi: t.isolasi, ekstra: t.ekstra } } : null
    }
    if (jenis === 'kamar') {
      const k = kamar.get(id)
      return k ? { label: `Kamar ${kodeLengkap(lokasi.get(k.lokasi_id)!)}`, nilai: { bangsal_id: k.bangsal_id, kapasitas_maksimum: k.kapasitas_maksimum } } : null
    }
    const l = lokasi.get(id)
    return l && l.jenis === 'ruang' ? { label: `${kodeLengkap(l)} · ${l.nama}`, nilai: { induk_id: l.induk_id, kode: l.kode, nama: l.nama, fungsi: l.fungsi, unit_organisasi_id: l.unit_id, status: l.status } } : null
  }
  function labelNilai(isian: string, nilai: unknown): string | null {
    if (nilai === null || nilai === undefined) return null
    if (typeof nilai === 'boolean') return nilai ? 'Ya' : 'Tidak'
    const teks = String(nilai)
    switch (isian) {
      case 'kelas_rawat_id': return kelas.get(teks)?.nama ?? teks
      case 'bangsal_id': return bangsal.get(teks)?.nama ?? teks
      case 'kamar_id': {
        const k = kamar.get(teks)
        return k ? `Kamar ${kodeLengkap(lokasi.get(k.lokasi_id)!)}` : teks
      }
      case 'lokasi_id':
      case 'induk_id': {
        const l = lokasi.get(teks)
        return l ? `${kodeLengkap(l)} · ${l.nama}` : teks
      }
      case 'unit_organisasi_id': return unitDari(teks)?.nama ?? teks
      default: return teks
    }
  }

  return { tangani, potretObjek, labelNilai, contoh: { gedungA, lantai1, r101, r102, melati, km101, km102, k3, k1 } }
}

export type ServerLokasi = ReturnType<typeof buatServerLokasi>
