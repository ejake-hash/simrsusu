import { ref } from 'vue'

/**
 * Antrean notifikasi singkat (toast) untuk hasil aksi: "Izin tersimpan", "Gagal menyimpan". Bukan pengganti
 * pesan di dekat formulir untuk error yang harus diperbaiki pengguna (PRD 5.1 butir 13).
 * Berhasil/info hilang sendiri setelah `durasiMs`; error tetap sampai ditutup.
 */
export interface Toast {
  id: number
  jenis: 'ok' | 'info' | 'peringatan' | 'error'
  pesan: string
}

export const daftarToast = ref<Toast[]>([])
let nomor = 0
const pewaktu = new Map<number, ReturnType<typeof setTimeout>>()

export function tutupToast(id: number): void {
  clearTimeout(pewaktu.get(id))
  pewaktu.delete(id)
  daftarToast.value = daftarToast.value.filter((t) => t.id !== id)
}

/** Menahan hilang otomatis selama pengguna membaca (hover/fokus), lalu melanjutkan. */
export function tahanToast(id: number): void {
  clearTimeout(pewaktu.get(id))
  pewaktu.delete(id)
}
export function lanjutkanToast(id: number, durasiMs = 5000): void {
  const t = daftarToast.value.find((x) => x.id === id)
  if (t && t.jenis !== 'error') pewaktu.set(id, setTimeout(() => tutupToast(id), durasiMs))
}

export function tampilkanToast(pesan: string, jenis: Toast['jenis'] = 'ok', durasiMs = 5000): number {
  const id = ++nomor
  // Paling banyak 3 sekaligus; yang lama keluar lebih dulu.
  daftarToast.value = [...daftarToast.value.slice(-2), { id, jenis, pesan }]
  lanjutkanToast(id, durasiMs)
  return id
}
