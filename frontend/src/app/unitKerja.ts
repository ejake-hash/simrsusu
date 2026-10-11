import { readonly, ref } from 'vue'

/**
 * Unit kerja aktif di header (utang 1.2b, tiket 1.8a): konteks tampilan bagi akun yang penugasannya ber-cakupan unit,
 * mis. perawat yang bertugas di dua ruang. Hanya di memori (hilang saat muat ulang atau keluar), bukan izin: server
 * tetap memeriksa cakupan setiap permintaan (PRD 9.2).
 */
const aktif = ref<string | null>(null)

export function useUnitKerja() {
  return readonly(aktif)
}
export function pilihUnitKerja(id: string | null): void {
  aktif.value = id
}
