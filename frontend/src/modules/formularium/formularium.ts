import { ApiError } from '@/shared/api/klien'
import type { GenerikPaten, JenisBarang, JenisFormularium } from './tipe'

/** Kunci cache dan label bersama layar Katalog obat + formularium (tiket 2.11, kontrak v0.23.0). */
export const KUNCI_FORMULARIUM = {
  semua: ['formularium'] as const,
  barang: (filter: object) => ['formularium', 'barang', filter] as const,
  daftar: ['formularium', 'daftar'] as const,
  item: (id: string, filter: object) => ['formularium', 'item', id, filter] as const,
}

export const LABEL_JENIS_BARANG: Record<JenisBarang, string> = {
  obat: 'Obat',
  bmhp: 'BMHP',
  reagen: 'Reagen',
  gizi: 'Gizi',
  umum: 'Umum',
  implan: 'Implan',
}
export const JENIS_BARANG = Object.keys(LABEL_JENIS_BARANG) as JenisBarang[]
export const LABEL_GENERIK_PATEN: Record<GenerikPaten, string> = { generik: 'Generik', paten: 'Paten' }
export const LABEL_JENIS_FORMULARIUM: Record<JenisFormularium, string> = { fornas: 'Fornas', rs: 'Formularium RS', penjamin: 'Penjamin' }
export const JENIS_FORMULARIUM = Object.keys(LABEL_JENIS_FORMULARIUM) as JenisFormularium[]

/** Judul kolom CSV persis seperti kontrak (impor barang dan impor item). */
export const KOLOM_CSV_BARANG = ['kode', 'nama', 'jenis', 'generik_paten', 'zat_aktif', 'kekuatan', 'sediaan', 'satuan_terkecil', 'kode_kfa', 'kewaspadaan_tinggi', 'narkotika', 'lasa'] as const
export const KOLOM_CSV_ITEM = ['kode_barang', 'restriksi', 'maks_per_resep', 'kronis'] as const
/** Templat berisi judul kolom saja, pemisah `;` (kontrak: sama dengan impor lokasi), dengan BOM agar Excel membacanya UTF-8. */
export const templatCsv = (kolom: readonly string[]) => `﻿${kolom.join(';')}\n`

export const teksRentang = (dari: string, sampai: string | null) => (sampai ? `${dari} s.d. ${sampai}` : `Sejak ${dari}, tanpa batas akhir`)

/** Zat aktif + kekuatan + sediaan sebagai satu baris ringkas; bagian kosong dilewati. */
export const ringkasObat = (b: { zat_aktif: string | null; kekuatan: string | null; sediaan: string | null }) => [b.zat_aktif, b.kekuatan, b.sediaan].filter(Boolean).join(' · ')

/** 409 `harus_lewat_usulan`: formularium sesudah siap melayani diubah lewat Usulan perubahan (FR-54-02). */
export const harusLewatUsulan = (e: unknown) => e instanceof ApiError && e.status === 409 && e.kode === 'harus_lewat_usulan'
