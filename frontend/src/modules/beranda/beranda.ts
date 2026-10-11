import type { GrupMenu, Menu, SubMenu } from '@/modules/akses/api'

/** Salam menurut jam perangkat: pagi 04.00–10.59, siang 11.00–14.59, sore 15.00–17.59, malam selebihnya. */
export function salam(jam: number): string {
  if (jam >= 4 && jam < 11) return 'Selamat pagi'
  if (jam >= 11 && jam < 15) return 'Selamat siang'
  if (jam >= 15 && jam < 18) return 'Selamat sore'
  return 'Selamat malam'
}

/** Kartu "Modul saya": semua menu milik akun kecuali Beranda, urut grup sidebar. */
export function modulSaya(grup: GrupMenu[]): Menu[] {
  return grup.flatMap((g) => g.menu).filter((m) => m.rute !== '/')
}

/** Ringkasan kartu: tiga sub-menu pertama, sisanya "dan N lainnya". */
export function ringkasanSubMenu(sub: SubMenu[], tampil = 3): { tampil: SubMenu[]; lainnya: number } {
  return { tampil: sub.slice(0, tampil), lainnya: Math.max(0, sub.length - tampil) }
}

export interface ItemPencarian {
  kode: string
  label: string
  /** Mis. "Pengaturan Sistem" untuk sub-menu; null untuk menu tanpa induk. */
  induk: string | null
  rute: string
  segera: boolean
}

/** Indeks pencarian menu (Ctrl/Cmd+K): menu dan sub-menu yang dikirim /saya/menu, yaitu yang diizinkan saja. */
export function indeksPencarian(grup: GrupMenu[]): ItemPencarian[] {
  const hasil: ItemPencarian[] = []
  for (const m of grup.flatMap((g) => g.menu)) {
    hasil.push({ kode: m.kode, label: m.label, induk: null, rute: m.rute, segera: m.status === 'segera' })
    for (const s of m.sub_menu) hasil.push({ kode: s.kode, label: s.label, induk: m.label, rute: s.rute, segera: s.status === 'segera' })
  }
  return hasil
}

/**
 * Cocok bila setiap kata kueri ada di label atau induknya (tanpa beda huruf besar/kecil). Awalan label didahulukan.
 * `total` = semua yang cocok, `hasil` = paling banyak `batas` teratas.
 */
export function cariMenu(indeks: ItemPencarian[], kueri: string, batas = 8): { hasil: ItemPencarian[]; total: number } {
  const kata = kueri.toLowerCase().split(/\s+/).filter(Boolean)
  if (kata.length === 0) return { hasil: [], total: 0 }
  const cocok = indeks.filter((i) => {
    const teks = `${i.label} ${i.induk ?? ''}`.toLowerCase()
    return kata.every((k) => teks.includes(k))
  })
  const awalan = (i: ItemPencarian) => (i.label.toLowerCase().startsWith(kata[0]!) ? 0 : 1)
  return { hasil: cocok.sort((a, b) => awalan(a) - awalan(b)).slice(0, batas), total: cocok.length }
}
