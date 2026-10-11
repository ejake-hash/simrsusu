import type { RincianError } from '@/shared/api/klien'
import type { GrupPohon, IzinNode, JenisAksi, NodeMenu, PohonMenu } from './tipePengaturan'

/**
 * Logika matriks izin satu peran (PRD 9.3 "Aturan layar", MD-2, MD-3). Yang disimpan selalu himpunan kode
 * izin aksi; tingkat lihat → input → perbaiki hanya jalan pintas di layar. Aturan yang sama ditegakkan server
 * (422 lihat_wajib / induk_belum_diberikan); di sini agar layar tidak pernah membentuk himpunan yang pasti ditolak.
 */

export type Tingkat = 'tidak' | 'lihat' | 'input' | 'perbaiki'

export const AKSI_DASAR = ['lihat', 'input', 'perbaiki'] as const satisfies readonly JenisAksi[]

export const LABEL_AKSI: Record<JenisAksi, string> = {
  lihat: 'Lihat',
  input: 'Input',
  perbaiki: 'Perbaiki',
  sahkan: 'Sahkan',
  verifikasi: 'Verifikasi',
  setujui: 'Setujui',
  batal: 'Batal',
  ekspor: 'Ekspor',
  cetak: 'Cetak',
  lihat_rupiah: 'Lihat rupiah',
}

export const LABEL_TINGKAT: Record<Tingkat, string> = { tidak: 'Tidak ada', lihat: 'Lihat', input: 'Input', perbaiki: 'Perbaiki' }

export interface BarisMatriks {
  node: NodeMenu
  grup: GrupPohon
  /** 0 = menu, 1 = sub-menu, 2+ = bagian. */
  kedalaman: number
  /** Kode node leluhur, dari menu sampai induk langsung. */
  leluhur: NodeMenu[]
  /** Sub-menu terdekat di atas bagian; null untuk menu dan sub-menu. */
  subInduk: NodeMenu | null
  /** Jalur label untuk ringkasan dan pesan, mis. "Kasir → Tagihan pasien". */
  jalur: string
}

export interface InfoIzin {
  izin: IzinNode
  baris: BarisMatriks
  /** Mis. "Kasir → Tagihan pasien → Batal". */
  label: string
}

export interface IndeksMatriks {
  baris: BarisMatriks[]
  perKode: Map<string, InfoIzin>
  perNode: Map<string, BarisMatriks>
  /** Kode izin per node beserta semua turunannya, untuk pencabutan berantai. */
  turunan: Map<string, string[]>
}

/** Pohon /menu → baris berurutan (urutan tampil) dan peta kode izin → node. */
export function indeksPohon(pohon: PohonMenu): IndeksMatriks {
  const baris: BarisMatriks[] = []
  const perKode = new Map<string, InfoIzin>()
  const perNode = new Map<string, BarisMatriks>()
  const turunan = new Map<string, string[]>()

  function jalan(grup: GrupPohon, node: NodeMenu, leluhur: NodeMenu[]): string[] {
    const subInduk = node.tingkat === 'bagian' ? ([...leluhur].reverse().find((n) => n.tingkat === 'sub_menu') ?? null) : null
    const jalur = [...leluhur, node].map((n) => n.label).join(' → ')
    const b: BarisMatriks = { node, grup, kedalaman: leluhur.length, leluhur, subInduk, jalur }
    baris.push(b)
    perNode.set(node.kode, b)
    for (const izin of node.izin) {
      perKode.set(izin.kode, { izin, baris: b, label: `${jalur} → ${LABEL_AKSI[izin.jenis_aksi]}` })
    }
    const semua = node.izin.map((i) => i.kode)
    for (const anak of node.anak) semua.push(...jalan(grup, anak, [...leluhur, node]))
    turunan.set(node.kode, semua)
    return semua
  }

  for (const grup of pohon.grup) {
    for (const menu of grup.menu) jalan(grup, menu, [])
  }
  return { baris, perKode, perNode, turunan }
}

export function kodeAksi(node: NodeMenu, aksi: JenisAksi): string | undefined {
  return node.izin.find((i) => i.jenis_aksi === aksi)?.kode
}

export function aksiKhusus(node: NodeMenu): IzinNode[] {
  return node.izin.filter((i) => !(AKSI_DASAR as readonly string[]).includes(i.jenis_aksi))
}

/** Tingkat yang bisa dipilih untuk node: hanya sampai aksi dasar yang terdaftar di node itu. */
export function pilihanTingkat(node: NodeMenu): Tingkat[] {
  const ada = AKSI_DASAR.filter((a) => kodeAksi(node, a))
  if (!ada.includes('lihat')) return []
  return ['tidak', ...ada]
}

/** Tingkat tertinggi yang utuh: input dihitung hanya bila lihat juga ada, dan seterusnya. */
export function tingkatDari(node: NodeMenu, izin: ReadonlySet<string>): Tingkat {
  let hasil: Tingkat = 'tidak'
  for (const aksi of AKSI_DASAR) {
    const kode = kodeAksi(node, aksi)
    if (!kode) continue
    if (!izin.has(kode)) break
    hasil = aksi
  }
  return hasil
}

/**
 * Tingkat untuk ditampilkan: 'campuran' bila ada aksi dasar di atas tingkat utuh (mis. lihat + perbaiki tanpa
 * input), agar pilihan "Lihat" tidak menyembunyikan perbaiki yang tetap diberikan.
 */
export function tingkatTampil(node: NodeMenu, izin: ReadonlySet<string>): Tingkat | 'campuran' {
  const tingkat = tingkatDari(node, izin)
  const batas = tingkat === 'tidak' ? -1 : AKSI_DASAR.indexOf(tingkat)
  const lebih = AKSI_DASAR.some((aksi, i) => i > batas && izin.has(kodeAksi(node, aksi) ?? ''))
  return lebih ? 'campuran' : tingkat
}

/** Bagian dinonaktifkan selama sub-menu induknya belum diberi lihat (MD-3). */
export function terkunciInduk(b: BarisMatriks, izin: ReadonlySet<string>): boolean {
  if (!b.subInduk) return false
  const lihat = kodeAksi(b.subInduk, 'lihat')
  return lihat !== undefined && !izin.has(lihat)
}

/** Menu (tanpa izin sendiri) tampil di sidebar bila minimal satu turunannya boleh dilihat. */
export function menuTampil(indeks: IndeksMatriks, node: NodeMenu, izin: ReadonlySet<string>): boolean {
  return (indeks.turunan.get(node.kode) ?? []).some((k) => izin.has(k) && indeks.perKode.get(k)?.izin.jenis_aksi === 'lihat')
}

/** Mencabut lihat sebuah node mencabut semua aksi di node itu dan semua turunannya (lihat_wajib, induk_belum_diberikan). */
function cabutLihat(indeks: IndeksMatriks, node: NodeMenu, izin: Set<string>): void {
  for (const k of indeks.turunan.get(node.kode) ?? []) izin.delete(k)
}

export function aturTingkat(indeks: IndeksMatriks, node: NodeMenu, izin: ReadonlySet<string>, tingkat: Tingkat): Set<string> {
  const hasil = new Set(izin)
  if (tingkat === 'tidak') {
    cabutLihat(indeks, node, hasil)
    return hasil
  }
  const batas = AKSI_DASAR.indexOf(tingkat)
  AKSI_DASAR.forEach((aksi, i) => {
    const kode = kodeAksi(node, aksi)
    if (!kode) return
    if (i <= batas) hasil.add(kode)
    else hasil.delete(kode)
  })
  return hasil
}

/** Menyalakan aksi apa pun ikut menyalakan lihat di node itu; mematikan lihat mencabut berantai. */
export function aturAksi(indeks: IndeksMatriks, node: NodeMenu, kode: string, nyala: boolean, izin: ReadonlySet<string>): Set<string> {
  const hasil = new Set(izin)
  const lihat = kodeAksi(node, 'lihat')
  if (nyala) {
    hasil.add(kode)
    if (lihat) hasil.add(lihat)
  } else if (kode === lihat) {
    cabutLihat(indeks, node, hasil)
  } else {
    hasil.delete(kode)
  }
  return hasil
}

/** Selisih berurutan sesuai pohon (bukan abjad), agar ringkasan terbaca seperti matriksnya. */
export function bandingkan(indeks: IndeksMatriks, lama: readonly string[], baru: ReadonlySet<string>): { ditambah: string[]; dicabut: string[] } {
  const asal = new Set(lama)
  const urutan = [...indeks.perKode.keys()]
  const posisi = new Map(urutan.map((k, i) => [k, i]))
  const urut = (a: string, b: string) => (posisi.get(a) ?? Infinity) - (posisi.get(b) ?? Infinity) || a.localeCompare(b)
  return {
    ditambah: [...baru].filter((k) => !asal.has(k)).sort(urut),
    dicabut: [...asal].filter((k) => !baru.has(k)).sort(urut),
  }
}

export type MasalahIzin = 'lihat_wajib' | 'induk_belum_diberikan' | 'izin_tidak_dikenal'

/** Cermin validasi server; dipakai untuk menyorot node bila himpunan dari server sendiri sudah tidak utuh. */
export function periksa(indeks: IndeksMatriks, izin: ReadonlySet<string>): { kode: string; masalah: MasalahIzin }[] {
  const hasil: { kode: string; masalah: MasalahIzin }[] = []
  for (const kode of izin) {
    const info = indeks.perKode.get(kode)
    if (!info) {
      hasil.push({ kode, masalah: 'izin_tidak_dikenal' })
      continue
    }
    const lihat = kodeAksi(info.baris.node, 'lihat')
    if (lihat && !izin.has(lihat)) hasil.push({ kode, masalah: 'lihat_wajib' })
    else if (terkunciInduk(info.baris, izin)) hasil.push({ kode, masalah: 'induk_belum_diberikan' })
  }
  return hasil
}

/**
 * Kode izin yang disebut `rincian` error (kontrak v0.2.2): `{kolom: "izin", kode, pesan}` untuk 422 matriks dan
 * `{kode, pesan}` untuk 409 butuh_persetujuan. Validasi kolom biasa (`{kolom, pesan}` tanpa kode) diabaikan.
 */
export function kodeDariRincian(rincian: readonly RincianError[]): string[] {
  const hasil: string[] = []
  for (const r of rincian) {
    const kode = r.kode
    if (typeof kode === 'string' && kode) hasil.push(kode)
  }
  return hasil
}

export function izinSensitif(indeks: IndeksMatriks, kode: string): boolean {
  return indeks.perKode.get(kode)?.izin.sensitif ?? false
}

export function labelIzin(indeks: IndeksMatriks, kode: string): string {
  return indeks.perKode.get(kode)?.label ?? indeks.perNode.get(kode)?.jalur ?? kode
}
