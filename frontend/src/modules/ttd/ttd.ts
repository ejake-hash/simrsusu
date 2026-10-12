import { useQueryClient } from '@tanstack/vue-query'
import { useSesiStore } from '@/app/sesi'
import { tandatangani } from './api'
import type { HasilTandaTangan, JenisObjekTtd, StatusTtd } from './tipe'

/** Kunci kueri dan penghubung TtdModal ↔ API TTE (tiket 1.6). */
export const KUNCI_TTD = {
  semua: ['ttd'] as const,
  verifikasi: (jenis: JenisObjekTtd, id: string) => ['ttd', 'verifikasi', jenis, id] as const,
}

/** Perbarui `Saya.ttd` di store tanpa memuat ulang /saya (mis. sesudah aktivasi atau tanda tangan). */
export function perbaruiStatusTtd(perubahan: Partial<StatusTtd>) {
  const sesi = useSesiStore()
  if (sesi.pengguna) sesi.pengguna = { ...sesi.pengguna, ttd: { ...sesi.pengguna.ttd, ...perubahan } }
}

/**
 * Handler `tandatangani(pin)` untuk TtdModal: memanggil POST /ttd/tandatangani untuk objek yang diberikan, mencatat
 * akhir sesi tanda tangan dari `meta`, dan memuat ulang verifikasi objek itu. Galat dilempar apa adanya (ditafsirkan
 * TtdModal).
 */
export function useTandaTangan() {
  const qc = useQueryClient()
  return (objek: { jenis: JenisObjekTtd; id: string }[], setelah?: (hasil: HasilTandaTangan) => void) =>
    async (pin: string | null) => {
      const hasil = await tandatangani(objek, pin)
      perbaruiStatusTtd({ sesi_berlaku_sampai: hasil.meta.sesi_berlaku_sampai })
      for (const o of objek) void qc.invalidateQueries({ queryKey: KUNCI_TTD.verifikasi(o.jenis, o.id) })
      setelah?.(hasil)
    }
}
