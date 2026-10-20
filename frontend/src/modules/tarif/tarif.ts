import type { IsianHargaTarif } from './tipe'

export const KUNCI_TARIF = {
  semua: ['tarif'] as const,
  daftar: (filter: object) => ['tarif', 'daftar', filter] as const,
  versi: (id: string) => ['tarif', 'versi', id] as const,
}

const RUPIAH = /^[0-9]{1,13}(\.[0-9]{1,2})?$/
export const rupiahValid = (v: string) => RUPIAH.test(v.trim())

/** Sen utuh dari string rupiah valid (menghindari float saat menjumlah). */
function sen(v: string): number {
  const [bulat = '0', pecah = ''] = v.trim().split('.')
  return Number(bulat) * 100 + Number(pecah.padEnd(2, '0'))
}
/** Pratinjau jumlah tiga komponen; server tetap yang menghitung `total`. */
export function jumlahKomponen(h: Pick<IsianHargaTarif, 'jasa_sarana' | 'jasa_pelayanan' | 'bhp'>): string | null {
  const bagian = [h.jasa_sarana, h.jasa_pelayanan, h.bhp]
  if (!bagian.every(rupiahValid)) return null
  const t = bagian.reduce((a, b) => a + sen(b), 0)
  return `${Math.floor(t / 100)}${t % 100 ? `.${String(t % 100).padStart(2, '0')}` : ''}`
}
const FORMAT = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0, maximumFractionDigits: 2 })
/** "Rp 150.000" dari string rupiah; string tak valid ditampilkan apa adanya. */
export const formatRupiah = (v: string) => (rupiahValid(v) ? FORMAT.format(Number(v)) : v)

/** Galat per baris harga dari isian klien; null = valid. Aturan kontrak: kelas unik, angka ≥ 0, maks 2 desimal. */
export function periksaHarga(daftar: IsianHargaTarif[]): string | null {
  const lihat = new Set<string>()
  for (const [i, h] of daftar.entries()) {
    if (!h.kelas_rawat_id) return `Baris ${i + 1}: pilih kelas rawat.`
    if (lihat.has(h.kelas_rawat_id)) return `Baris ${i + 1}: kelas rawat sudah ada di baris lain.`
    lihat.add(h.kelas_rawat_id)
    for (const [k, label] of [['jasa_sarana', 'Jasa sarana'], ['jasa_pelayanan', 'Jasa pelayanan'], ['bhp', 'BHP']] as const) {
      if (!rupiahValid(h[k])) return `Baris ${i + 1}: ${label} harus angka nol atau lebih, maksimal dua desimal.`
    }
  }
  return null
}
