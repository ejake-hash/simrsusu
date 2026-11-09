import type { PermintaanTiruan, ResponsTiruan } from '../../akses/tiruan/serverTiruan'
import type { RingkasAreaIgd } from '../../igd/tipePapan'
import type { StatusAreaIgd } from '../tipe'

/**
 * Server tiruan kontrak v0.34.0 (tiket 2.25): /data-induk/area-igd (daftar berkursor urut kode, tambah, ubah dengan
 * If-Match) dan /data-induk/aturan-papan-igd (baca, ubah dengan If-Match). `areaAktif()`, `area()`, dan `aturan()` dipakai
 * server kunjungan untuk GET /igd/papan dan PUT area-igd. Keadaan di memori; area SINTETIS berlabel contoh, angka awal
 * aturan = bawaan instalasi (batas tanggap 5 menit, ambang mendekati 6 jam kosong). Bukan sumber aturan.
 */
const error = (status: number, kode: string, pesan: string, rincian: Record<string, unknown>[] = []): ResponsTiruan => ({ status, isi: { error: { kode, pesan, rincian } } })
const ok = (isi: unknown, status = 200, header?: Record<string, string>): ResponsTiruan => ({ status, isi, header })
const salahKolom = (kolom: string, pesan: string) => error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom, pesan }])
const etag = (v: number) => ({ ETag: `"${v}"` })
type Isi = Record<string, unknown>

const KODE_DATA = /^[A-Z0-9][A-Z0-9._-]{0,29}$/
const bulat = (x: unknown, min: number, maks: number) => typeof x === 'number' && Number.isInteger(x) && x >= min && x <= maks

interface AreaT {
  id: string
  kode: string
  nama: string
  urutan: number
  status: StatusAreaIgd
  v: number
  diubah_pada: string
}

/** Area SINTETIS, sama dengan `lokal:kunjungan-contoh` (nama area FR-03-03, bukan data induk RS). */
export const AREA_IGD_CONTOH = [
  { id: '00000000-0000-7000-a100-000000000001', kode: 'CONTOH-RESUSITASI', nama: 'Resusitasi (contoh)', urutan: 1 },
  { id: '00000000-0000-7000-a100-000000000002', kode: 'CONTOH-OBSERVASI', nama: 'Observasi (contoh)', urutan: 2 },
  { id: '00000000-0000-7000-a100-000000000003', kode: 'CONTOH-HIJAU', nama: 'Hijau (contoh)', urutan: 3 },
] as const

export interface OpsiServerPapanIgd {
  boleh: (izin: string) => boolean
  sekarang?: () => Date
}

export function buatServerPapanIgd(opsi: OpsiServerPapanIgd) {
  const sekarang = opsi.sekarang ?? (() => new Date())
  const daftar = new Map<string, AreaT>()
  let nomor = AREA_IGD_CONTOH.length
  for (const a of AREA_IGD_CONTOH) daftar.set(a.id, { ...a, status: 'aktif', v: 0, diubah_pada: '2026-10-01T02:00:00.000Z' })
  const atur = { batas_tanggap_menit: 5, peringatan_lama_igd_menit: null as number | null, v: 0, diubah_pada: '2026-10-01T02:00:00.000Z' }

  const bentukArea = (a: AreaT) => ({ id: a.id, kode: a.kode, nama: a.nama, urutan: a.urutan, status: a.status, versi: String(a.v), diubah_pada: a.diubah_pada })
  const bentukAturan = () => ({ batas_tanggap_menit: atur.batas_tanggap_menit, peringatan_lama_igd_menit: atur.peringatan_lama_igd_menit, versi: String(atur.v), diubah_pada: atur.diubah_pada })
  function cocokVersi(v: number, header: Record<string, string | undefined>): ResponsTiruan | null {
    const im = header['if-match']
    if (!im) return error(428, 'if_match_wajib', 'Versi data tidak dikirim. Muat ulang halaman lalu coba lagi.')
    return im.replace(/^W\//, '').replace(/"/g, '') === String(v) ? null : error(412, 'versi_berubah', 'Data ini diubah orang lain sejak Anda membukanya. Muat ulang untuk melihat perubahan terbaru.')
  }
  const dasarSalah = (isi: Isi) =>
    typeof isi.dasar_perubahan !== 'string' || isi.dasar_perubahan.trim().length < 3 || isi.dasar_perubahan.length > 200 ? salahKolom('dasar_perubahan', 'Isi dasar perubahan, 3 sampai 200 karakter.') : null
  function periksaArea(isi: Isi, sendiri?: AreaT): ResponsTiruan | null {
    const d = dasarSalah(isi)
    if (d) return d
    if ('kode' in isi) {
      if (typeof isi.kode !== 'string' || !KODE_DATA.test(isi.kode)) return salahKolom('kode', 'Kode huruf besar/angka, maks. 30.')
      if ([...daftar.values()].some((x) => x.kode === isi.kode && x !== sendiri)) return salahKolom('kode', 'Kode sudah dipakai area lain.')
    }
    if ('nama' in isi && (typeof isi.nama !== 'string' || isi.nama.trim().length < 2 || isi.nama.length > 100)) return salahKolom('nama', 'Nama 2 sampai 100 karakter.')
    if ('urutan' in isi && !bulat(isi.urutan, 0, 999)) return salahKolom('urutan', 'Urutan 0 sampai 999.')
    if ('status' in isi && isi.status !== 'aktif' && isi.status !== 'nonaktif') return salahKolom('status', 'Status harus aktif atau nonaktif.')
    return null
  }

  function tangani(r: PermintaanTiruan): ResponsTiruan | null {
    const [a0, a1, a2, a3] = r.jalur.replace(/\/+$/, '').split('/').filter(Boolean)
    if (a0 !== 'data-induk' || (a1 !== 'area-igd' && a1 !== 'aturan-papan-igd') || a3) return null
    const m = r.metode.toUpperCase()
    const isi = (r.isi ?? {}) as Isi
    const izin = (aksi: string) => (opsi.boleh(`master_data.papan_igd.${aksi}`) ? null : error(403, 'akses_ditolak', 'Anda tidak punya akses untuk tindakan ini.'))

    if (a1 === 'aturan-papan-igd') {
      if (a2) return null
      if (m === 'GET') return izin('lihat') ?? ok({ data: bentukAturan() }, 200, etag(atur.v))
      if (m !== 'PATCH') return null
      const tolak = izin('perbaiki') ?? cocokVersi(atur.v, r.header)
      if (tolak) return tolak
      if (Object.keys(isi).length < 2) return salahKolom('dasar_perubahan', 'Isi minimal satu perubahan.')
      const d = dasarSalah(isi)
      if (d) return d
      if ('batas_tanggap_menit' in isi && !bulat(isi.batas_tanggap_menit, 1, 1440)) return salahKolom('batas_tanggap_menit', 'Batas tanggap 1 sampai 1.440 menit.')
      if ('peringatan_lama_igd_menit' in isi && isi.peringatan_lama_igd_menit !== null && !bulat(isi.peringatan_lama_igd_menit, 1, 359)) {
        return salahKolom('peringatan_lama_igd_menit', 'Ambang 1 sampai 359 menit, atau kosong.')
      }
      if ('batas_tanggap_menit' in isi) atur.batas_tanggap_menit = isi.batas_tanggap_menit as number
      if ('peringatan_lama_igd_menit' in isi) atur.peringatan_lama_igd_menit = isi.peringatan_lama_igd_menit as number | null
      atur.v++
      atur.diubah_pada = sekarang().toISOString()
      return ok({ data: bentukAturan() }, 200, etag(atur.v))
    }

    if (!a2 && m === 'GET') {
      const tolak = izin('lihat')
      if (tolak) return tolak
      const status = r.kueri.get('status')
      const hasil = [...daftar.values()].filter((a) => !status || a.status === status).sort((x, y) => x.kode.localeCompare(y.kode))
      const batas = Number(r.kueri.get('batas') ?? 50)
      const mulai = r.kueri.get('kursor') ? Number(atob(r.kueri.get('kursor')!)) : 0
      return ok({ data: hasil.slice(mulai, mulai + batas).map(bentukArea), meta: { kursor_berikutnya: mulai + batas < hasil.length ? btoa(String(mulai + batas)) : null } })
    }
    if (!a2 && m === 'POST') {
      const tolak = izin('input')
      if (tolak) return tolak
      for (const k of ['kode', 'nama']) if (!(k in isi)) return salahKolom(k, 'Wajib diisi.')
      const salah = periksaArea(isi)
      if (salah) return salah
      const baru: AreaT = {
        id: `00000000-0000-7000-a100-${(++nomor).toString(16).padStart(12, '0')}`, kode: isi.kode as string, nama: (isi.nama as string).trim(),
        urutan: (isi.urutan as number | undefined) ?? 0, status: 'aktif', v: 0, diubah_pada: sekarang().toISOString(),
      }
      daftar.set(baru.id, baru)
      return ok({ data: bentukArea(baru) }, 201, etag(baru.v))
    }
    if (a2 && m === 'PATCH') {
      const tolak = izin('perbaiki')
      if (tolak) return tolak
      const a = daftar.get(a2)
      if (!a) return error(404, 'tidak_ditemukan', 'Area IGD tidak ditemukan.')
      const versi = cocokVersi(a.v, r.header)
      if (versi) return versi
      if (Object.keys(isi).length < 2) return salahKolom('dasar_perubahan', 'Isi minimal satu perubahan.')
      const salah = periksaArea(isi, a)
      if (salah) return salah
      if ('kode' in isi) a.kode = isi.kode as string
      if ('nama' in isi) a.nama = (isi.nama as string).trim()
      if ('urutan' in isi) a.urutan = isi.urutan as number
      if ('status' in isi) a.status = isi.status as StatusAreaIgd
      a.v++
      a.diubah_pada = sekarang().toISOString()
      return ok({ data: bentukArea(a) }, 200, etag(a.v))
    }
    return null
  }

  const ringkas = (a: AreaT): RingkasAreaIgd => ({ id: a.id, kode: a.kode, nama: a.nama })
  /** Area aktif urut tampil (urutan, lalu kode). */
  const areaAktif = (): RingkasAreaIgd[] => [...daftar.values()].filter((a) => a.status === 'aktif').sort((x, y) => x.urutan - y.urutan || x.kode.localeCompare(y.kode)).map(ringkas)
  /** Area mana pun (termasuk nonaktif) untuk baris yang sudah ditempatkan. */
  const area = (id: string): RingkasAreaIgd | null => {
    const a = daftar.get(id)
    return a ? ringkas(a) : null
  }
  const aturan = () => ({ batas_tanggap_menit: atur.batas_tanggap_menit, peringatan_lama_igd_menit: atur.peringatan_lama_igd_menit })

  return { tangani, areaAktif, area, aturan }
}
