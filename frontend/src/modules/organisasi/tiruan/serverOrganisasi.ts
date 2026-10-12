import type { PermintaanTiruan, ResponsTiruan } from '../../akses/tiruan/serverTiruan'
import type { JenisUnit, Ksm, ProfilRsApi, RingkasPegawai, UnitOrganisasi } from '../tipe'
import { profilRsTiruan } from './profilRs'

/**
 * Server tiruan kontrak v0.3.0 (tiket 1.8a): /profil-rs, /unit-organisasi, /ksm. Keadaan di memori, aturan dari
 * kontrak (If-Match, kontras, siklus, unit masih dipakai, kode unik). Dev (VITE_API_TIRUAN=true), unit test, e2e;
 * tidak masuk build produksi. Nama unit dan KSM sintetis.
 */
const error = (status: number, kode: string, pesan: string, rincian: Record<string, unknown>[] = []): ResponsTiruan => ({ status, isi: { error: { kode, pesan, rincian } } })
const ok = (isi: unknown, status = 200, header?: Record<string, string>): ResponsTiruan => ({ status, isi, header })
const id = (n: number) => `00000000-0000-7000-9000-${n.toString(16).padStart(12, '0')}`

// Kontras WCAG 2.1 (sama dengan aturan server di kontrak).
function rgb(hex: string): [number, number, number] | null {
  const m = /^#([0-9a-f]{6})$/i.exec(hex)
  if (!m) return null
  const n = Number.parseInt(m[1]!, 16)
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255]
}
function lum(c: [number, number, number]): number {
  const k = (v: number) => {
    const s = v / 255
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
  }
  return 0.2126 * k(c[0]) + 0.7152 * k(c[1]) + 0.0722 * k(c[2])
}
function rasio(a: [number, number, number], b: [number, number, number]): number {
  const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p) as [number, number]
  return Math.round(((x + 0.05) / (y + 0.05)) * 100) / 100
}
const PUTIH: [number, number, number] = [255, 255, 255]
const TINTA: [number, number, number] = [29, 29, 27]

export interface OpsiServerOrganisasi {
  /** Akun yang masuk boleh melihat `rinci` profil RS dan mengubahnya (pengaturan.profil_rs.*). */
  bolehProfil?: () => boolean
  /** Unit dari penugasan ber-cakupan unit milik akun yang masuk (milik_saya). */
  unitMilikSaya?: () => string[]
  sekarang?: () => Date
  /** Pegawai dari server tiruan SDM (1.8d): kepala unit dan ketua KSM; null = tidak ada. */
  pegawai?: (id: string) => RingkasPegawai | null
}

export function buatServerOrganisasi(opsi: OpsiServerOrganisasi = {}) {
  const sekarang = opsi.sekarang ?? (() => new Date())
  const iso = () => sekarang().toISOString()
  const bolehProfil = opsi.bolehProfil ?? (() => true)
  let nomor = 100

  // ── Profil RS: instalasi kosong seperti seeder backend ──
  let profil: ProfilRsApi = profilRsTiruan({ versi: '1' })
  let rinci: NonNullable<ProfilRsApi['rinci']> = { kode_kemenkes: null, kelas: null, status_blu: null, kode_ppk_bpjs: null, alamat: null, status_penyiapan: 'belum', diubah_pada: null }
  let versiBerkas = 0
  const etagProfil = () => `"${profil.versi}"`
  const bentukProfil = () => (bolehProfil() ? { ...profil, rinci } : profil)

  // ── Unit: pohon 3 tingkat sintetis ──
  const unit = new Map<string, UnitOrganisasi & { v: number }>()
  const tambah = (indukId: string | null, kode: string, nama: string, jenis: JenisUnit, status: 'aktif' | 'nonaktif' = 'aktif') => {
    const u = { id: id(++nomor), induk_id: indukId, kode, nama, jenis, status, berlaku_dari: '2026-01-01', kepala: null, jumlah_anak: 0, jalur: [], versi: '1', diubah_pada: '2026-10-01T02:00:00.000Z', v: 1 }
    unit.set(u.id, u)
    return u.id
  }
  const irna = tambah(null, 'IRNA', 'Instalasi Rawat Inap', 'instalasi')
  const irja = tambah(null, 'IRJA', 'Instalasi Rawat Jalan', 'instalasi')
  const yanmed = tambah(null, 'YANMED', 'Bidang Pelayanan Medik', 'bidang')
  tambah(null, 'LAMA', 'Unit Lama (nonaktif)', 'bagian', 'nonaktif')
  const bpd = tambah(irna, 'IRNA-PD', 'Bangsal Penyakit Dalam', 'ruang')
  tambah(irna, 'IRNA-BEDAH', 'Bangsal Bedah', 'ruang')
  tambah(irna, 'IRNA-ANAK', 'Bangsal Anak', 'ruang')
  tambah(bpd, 'IRNA-PD-ISO', 'Ruang Isolasi Penyakit Dalam', 'ruang')
  tambah(irja, 'POLI-PD', 'Poliklinik Penyakit Dalam', 'poli')
  tambah(irja, 'POLI-ANAK', 'Poliklinik Anak', 'poli')
  tambah(yanmed, 'YANMED-RM', 'Bagian Rekam Medis', 'bagian')

  const anak = (indukId: string | null) => [...unit.values()].filter((u) => u.induk_id === indukId)
  const jalur = (u: UnitOrganisasi) => {
    const hasil: UnitOrganisasi['jalur'] = []
    let p = u.induk_id ? unit.get(u.induk_id) : undefined
    while (p) {
      hasil.unshift({ id: p.id, kode: p.kode, nama: p.nama })
      p = p.induk_id ? unit.get(p.induk_id) : undefined
    }
    return hasil
  }
  /** Kepala/ketua disimpan sebagai id; status pegawai dihitung saat dibaca (yang sudah keluar tetap tampil, ditandai). */
  const ringkasPegawai = (p: RingkasPegawai | null) => (p ? (opsi.pegawai?.(p.id) ?? p) : null)
  const bentukUnit = (u: UnitOrganisasi & { v: number }): UnitOrganisasi => {
    const { v, ...isi } = u
    return { ...isi, kepala: ringkasPegawai(u.kepala), versi: String(v), jumlah_anak: anak(u.id).length, jalur: jalur(u) }
  }
  /** 422 pegawai_tidak_valid bila pegawai tidak ada atau sudah keluar (kontrak v0.6.0). */
  const pegawaiSah = (nilai: unknown, kolom: string): RingkasPegawai | null | ResponsTiruan => {
    if (nilai === null) return null
    const p = typeof nilai === 'string' ? opsi.pegawai?.(nilai) : null
    if (!p || p.status === 'keluar') return error(422, 'pegawai_tidak_valid', 'Pegawai tidak ada atau sudah keluar.', [{ kolom, pesan: 'Pilih pegawai yang masih bekerja.' }])
    return p
  }
  const salah = (v: unknown): v is ResponsTiruan => typeof v === 'object' && v !== null && 'status' in v && 'isi' in v
  const turunan = (uid: string): string[] => anak(uid).flatMap((a) => [a.id, ...turunan(a.id)])

  // ── KSM ──
  const ksm = new Map<string, Ksm & { v: number }>()
  for (const [k, n] of [
    ['KSM-PD', 'KSM Penyakit Dalam'],
    ['KSM-BEDAH', 'KSM Bedah'],
    ['KSM-ANAK', 'KSM Ilmu Kesehatan Anak'],
  ] as const) {
    const kid = id(++nomor)
    ksm.set(kid, { id: kid, kode: k, nama: n, status: 'aktif', ketua: null, versi: '1', diubah_pada: '2026-10-01T02:00:00.000Z', v: 1 })
  }
  const bentukKsm = (k: Ksm & { v: number }): Ksm => {
    const { v, ...isi } = k
    return { ...isi, ketua: ringkasPegawai(k.ketua), versi: String(v) }
  }

  function halaman<T>(daftar: T[], kueri: URLSearchParams): ResponsTiruan {
    const batas = Number(kueri.get('batas') ?? 50)
    if (batas > 200) return error(422, 'ukuran_halaman_melebihi_batas', 'Ukuran halaman maksimum 200.')
    const mulai = kueri.get('kursor') ? Number(atob(kueri.get('kursor')!)) : 0
    return ok({ data: daftar.slice(mulai, mulai + batas), meta: { kursor_berikutnya: mulai + batas < daftar.length ? btoa(String(mulai + batas)) : null } })
  }
  const cocokVersi = (sekarangV: string, header: Record<string, string | undefined>) => {
    const im = header['if-match']
    if (!im) return error(428, 'if_match_wajib', 'Header If-Match wajib.')
    if (im.replace(/^W\//, '').replace(/"/g, '') !== sekarangV) return error(412, 'versi_berubah', 'Data ini diubah orang lain sejak dibuka. Muat ulang untuk melihat perubahan terbaru.')
    return null
  }
  const KODE = /^[A-Z0-9][A-Z0-9._-]{0,29}$/
  const dasar = (isi: Record<string, unknown>) =>
    typeof isi.dasar_perubahan !== 'string' || isi.dasar_perubahan.trim().length < 3
      ? error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'dasar_perubahan', pesan: 'Isi dasar perubahan, minimal 3 karakter.' }])
      : null

  function tangani(r: PermintaanTiruan): ResponsTiruan | null {
    const isi = (r.isi ?? {}) as Record<string, unknown>
    const [a0, a1, a2] = r.jalur.replace(/\/+$/, '').split('/').filter(Boolean)
    const m = r.metode.toUpperCase()

    if (a0 === 'profil-rs' && !a1) {
      if (m === 'GET') return ok({ data: bentukProfil() }, 200, { ETag: etagProfil() })
      if (m === 'PUT') {
        if (!bolehProfil()) return error(403, 'akses_ditolak', 'Anda tidak punya izin untuk tindakan ini.')
        const v = cocokVersi(profil.versi, r.header)
        if (v) return v
        const d = dasar(isi)
        if (d) return d
        if (typeof isi.nama !== 'string' || isi.nama.trim().length < 3) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'nama', pesan: 'Nama RS minimal 3 karakter.' }])
        const warna = (isi.warna ?? {}) as { brand?: string; accent?: string }
        const brand = rgb(warna.brand ?? '')
        const accent = rgb(warna.accent ?? '')
        if (!brand || !accent) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: brand ? 'warna.accent' : 'warna.brand', pesan: 'Warna HEX 6 digit, mis. #006937.' }])
        const kurang: Record<string, unknown>[] = []
        const rb = rasio(brand, PUTIH)
        if (rb < 4.5) kurang.push({ kolom: 'warna.brand', rasio: rb, minimal: 4.5, pesan: `Kontras warna utama dengan putih ${rb.toFixed(2).replace('.', ',')}:1, minimal 4,5:1.` })
        const ra = rasio(accent, TINTA)
        if (ra < 4.5) kurang.push({ kolom: 'warna.accent', rasio: ra, minimal: 4.5, pesan: `Kontras warna aksen dengan teks gelap ${ra.toFixed(2).replace('.', ',')}:1, minimal 4,5:1.` })
        if (kurang.length) return error(422, 'kontras_kurang', 'Kontras warna kurang.', kurang)
        const kontak = (isi.kontak_admin ?? {}) as { unit?: string; kontak?: string | null }
        profil = {
          ...profil,
          versi: String(Number(profil.versi) + 1),
          nama: isi.nama.trim(),
          paket_identitas: { ...profil.paket_identitas, warna: { brand: warna.brand!.toUpperCase(), accent: warna.accent!.toUpperCase() } },
          kontak_admin: { unit: kontak.unit?.trim() || 'admin SIMRS', kontak: kontak.kontak?.trim() || null },
        }
        rinci = {
          kode_kemenkes: (isi.kode_kemenkes as string | null) || null,
          kelas: (isi.kelas as string | null) || null,
          status_blu: (isi.status_blu as boolean | null) ?? null,
          kode_ppk_bpjs: (isi.kode_ppk_bpjs as string | null) || null,
          alamat: (isi.alamat as string | null) || null,
          status_penyiapan: 'sebagian',
          diubah_pada: iso(),
        }
        return ok({ data: bentukProfil() }, 200, { ETag: etagProfil() })
      }
    }
    if (a0 === 'profil-rs' && a1 === 'berkas' && m === 'PUT' && (a2 === 'logo' || a2 === 'motif' || a2 === 'foto')) {
      if (!bolehProfil()) return error(403, 'akses_ditolak', 'Anda tidak punya izin untuk tindakan ini.')
      const v = cocokVersi(profil.versi, r.header)
      if (v) return v
      const berkas = isi instanceof FormData ? isi.get('berkas') : (r.isi as FormData | undefined)?.get?.('berkas')
      if (!(berkas instanceof Blob)) return error(422, 'validasi_gagal', 'Pilih berkas.', [{ kolom: 'berkas', pesan: 'Pilih berkas.' }])
      const tipe = berkas.type
      const boleh = a2 === 'foto' ? ['image/webp', 'image/jpeg', 'image/png'] : ['image/svg+xml', 'image/png']
      if (!boleh.includes(tipe)) return error(422, 'tipe_berkas_tidak_diizinkan', `Tipe berkas tidak diizinkan untuk ${a2}.`)
      const batas = { logo: 200, motif: 500, foto: 2048 }[a2] * 1024
      if (berkas.size > batas) return error(422, 'berkas_terlalu_besar', `Ukuran berkas melebihi ${batas / 1024} KB.`)
      // Tiruan: berkas dilayani dari memori peramban (URL objek); server asli dari GET /profil-rs/berkas/{jenis}.
      const url = typeof URL.createObjectURL === 'function' ? URL.createObjectURL(berkas) : `/api/v1/profil-rs/berkas/${a2}?v=${++versiBerkas}`
      profil = { ...profil, versi: String(Number(profil.versi) + 1), paket_identitas: { ...profil.paket_identitas, [`${a2}_url`]: url } }
      return ok({ data: bentukProfil() }, 200, { ETag: etagProfil() })
    }

    if (a0 === 'unit-organisasi') {
      if (m === 'GET' && !a1) {
        let daftar: (UnitOrganisasi & { v: number })[]
        if (r.kueri.get('milik_saya') === 'true') {
          const milik = new Set(opsi.unitMilikSaya?.() ?? [])
          daftar = [...unit.values()].filter((u) => milik.has(u.id))
        } else {
          const cari = (r.kueri.get('cari') ?? '').toLowerCase()
          const induk = r.kueri.get('induk_id')
          daftar = [...unit.values()]
            .filter((u) => (cari ? u.kode.toLowerCase().includes(cari) || u.nama.toLowerCase().includes(cari) : induk ? u.induk_id === induk : r.kueri.get('akar') === 'true' ? u.induk_id === null : true))
            .filter((u) => !r.kueri.get('status') || u.status === r.kueri.get('status'))
            .filter((u) => !r.kueri.get('jenis') || u.jenis === r.kueri.get('jenis'))
        }
        return halaman(daftar.sort((x, y) => x.kode.localeCompare(y.kode)).map(bentukUnit), r.kueri)
      }
      if (m === 'POST' && !a1) {
        const d = dasar(isi)
        if (d) return d
        const kode = String(isi.kode ?? '')
        if (!KODE.test(kode)) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'kode', pesan: 'Huruf besar, angka, titik, garis bawah, atau strip; maks. 30.' }])
        if (typeof isi.nama !== 'string' || isi.nama.trim().length < 2) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'nama', pesan: 'Nama minimal 2 karakter.' }])
        if ([...unit.values()].some((u) => u.kode === kode)) return error(409, 'kode_sudah_dipakai', `Kode ${kode} sudah dipakai unit lain.`, [{ kolom: 'kode' }])
        const indukId = (isi.induk_id as string | null) ?? null
        if (indukId && unit.get(indukId)?.status !== 'aktif') return error(422, 'induk_tidak_aktif', 'Unit induk tidak aktif.')
        const baru = tambah(indukId, kode, isi.nama.trim(), (isi.jenis as JenisUnit) ?? 'bagian')
        const u = unit.get(baru)!
        u.berlaku_dari = (isi.berlaku_dari as string | null) ?? iso().slice(0, 10)
        return ok({ data: bentukUnit(u) }, 201, { ETag: `"${u.v}"` })
      }
      const u = a1 ? unit.get(a1) : undefined
      if (!u) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
      if (m === 'GET') return ok({ data: bentukUnit(u) }, 200, { ETag: `"${u.v}"` })
      if (m === 'PATCH') {
        const v = cocokVersi(String(u.v), r.header)
        if (v) return v
        const d = dasar(isi)
        if (d) return d
        if ('induk_id' in isi) {
          const indukBaru = (isi.induk_id as string | null) ?? null
          if (indukBaru && (indukBaru === u.id || turunan(u.id).includes(indukBaru))) return error(422, 'siklus', 'Induk baru adalah unit ini sendiri atau turunannya.', [{ kolom: 'induk_id' }])
          u.induk_id = indukBaru
        }
        if (typeof isi.kode === 'string' && isi.kode !== u.kode) {
          if ([...unit.values()].some((x) => x.kode === isi.kode)) return error(409, 'kode_sudah_dipakai', `Kode ${isi.kode} sudah dipakai unit lain.`, [{ kolom: 'kode' }])
          u.kode = isi.kode
        }
        if (isi.status === 'nonaktif' && u.status === 'aktif') {
          const anakAktif = anak(u.id).filter((x) => x.status === 'aktif').length
          if (anakAktif) return error(409, 'unit_masih_dipakai', `Unit masih punya ${anakAktif} unit anak aktif.`, [{ jumlah_anak_aktif: anakAktif, jumlah_penugasan: 0, jumlah_modul_aktif: 0 }])
        }
        if (typeof isi.nama === 'string') u.nama = isi.nama
        if (typeof isi.jenis === 'string') u.jenis = isi.jenis as JenisUnit
        if (isi.status === 'aktif' || isi.status === 'nonaktif') u.status = isi.status
        if (typeof isi.berlaku_dari === 'string') u.berlaku_dari = isi.berlaku_dari
        if ('kepala_pegawai_id' in isi) {
          const p = pegawaiSah(isi.kepala_pegawai_id, 'kepala_pegawai_id')
          if (salah(p)) return p
          u.kepala = p
        }
        u.v++
        u.diubah_pada = iso()
        return ok({ data: bentukUnit(u) }, 200, { ETag: `"${u.v}"` })
      }
    }

    if (a0 === 'ksm') {
      if (m === 'GET' && !a1) {
        const cari = (r.kueri.get('cari') ?? '').toLowerCase()
        const daftar = [...ksm.values()]
          .filter((k) => !cari || k.kode.toLowerCase().includes(cari) || k.nama.toLowerCase().includes(cari))
          .filter((k) => !r.kueri.get('status') || k.status === r.kueri.get('status'))
          .sort((x, y) => x.kode.localeCompare(y.kode))
          .map(bentukKsm)
        return halaman(daftar, r.kueri)
      }
      if (m === 'POST' && !a1) {
        const d = dasar(isi)
        if (d) return d
        const kode = String(isi.kode ?? '')
        if (!KODE.test(kode)) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'kode', pesan: 'Huruf besar, angka, titik, garis bawah, atau strip; maks. 30.' }])
        if ([...ksm.values()].some((k) => k.kode === kode)) return error(409, 'kode_sudah_dipakai', `Kode ${kode} sudah dipakai KSM lain.`, [{ kolom: 'kode' }])
        const kid = id(++nomor)
        const k = { id: kid, kode, nama: String(isi.nama ?? '').trim(), status: 'aktif' as const, ketua: null, versi: '1', diubah_pada: iso(), v: 1 }
        ksm.set(kid, k)
        return ok({ data: bentukKsm(k) }, 201, { ETag: '"1"' })
      }
      const k = a1 ? ksm.get(a1) : undefined
      if (!k) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
      if (m === 'GET') return ok({ data: bentukKsm(k) }, 200, { ETag: `"${k.v}"` })
      if (m === 'PATCH') {
        const v = cocokVersi(String(k.v), r.header)
        if (v) return v
        const d = dasar(isi)
        if (d) return d
        if (typeof isi.kode === 'string' && isi.kode !== k.kode) {
          if ([...ksm.values()].some((x) => x.kode === isi.kode)) return error(409, 'kode_sudah_dipakai', `Kode ${isi.kode} sudah dipakai KSM lain.`, [{ kolom: 'kode' }])
          k.kode = isi.kode
        }
        if (typeof isi.nama === 'string') k.nama = isi.nama
        if (isi.status === 'aktif' || isi.status === 'nonaktif') k.status = isi.status
        if ('ketua_pegawai_id' in isi) {
          const p = pegawaiSah(isi.ketua_pegawai_id, 'ketua_pegawai_id')
          if (salah(p)) return p
          k.ketua = p
        }
        k.v++
        k.diubah_pada = iso()
        return ok({ data: bentukKsm(k) }, 200, { ETag: `"${k.v}"` })
      }
    }
    return null
  }

  /** Label cakupan untuk Penugasan (kontrak v0.3.0 `cakupan_label`). */
  const labelUnit = (uid: string) => {
    const u = unit.get(uid)
    return u ? `${u.kode} · ${u.nama}` : null
  }
  const unitAktif = (uid: string) => unit.get(uid)?.status === 'aktif'
  /** Ringkasan unit untuk server tiruan lokasi (1.8b): unit pengelola lokasi, bangsal, poliklinik. */
  const ringkasUnit = (uid: string) => {
    const u = unit.get(uid)
    return u ? { id: u.id, kode: u.kode, nama: u.nama, aktif: u.status === 'aktif' } : null
  }

  return { tangani, labelUnit, unitAktif, ringkasUnit, akar: { irna, irja, yanmed, bpd } }
}

export type ServerOrganisasi = ReturnType<typeof buatServerOrganisasi>
