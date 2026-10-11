import { useQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import { useSesiStore } from '@/app/sesi'
import { daftarUnit } from './api'
import type { JenisUnit, UnitOrganisasi } from './tipe'

/** Kunci cache, label, dan kueri bersama modul organisasi (tiket 1.8a). */
export const KUNCI_ORG = {
  semua: ['organisasi'] as const,
  profil: ['organisasi', 'profil-rs'] as const,
  unitAnak: (induk: string | null) => ['organisasi', 'unit', 'anak', induk ?? 'akar'] as const,
  unit: (id: string) => ['organisasi', 'unit', 'rinci', id] as const,
  semuaUnit: ['organisasi', 'unit'] as const,
  cariUnit: (kueri: string) => ['organisasi', 'unit', 'cari', kueri] as const,
  unitSaya: (penggunaId: string | null) => ['organisasi', 'unit', 'milik-saya', penggunaId] as const,
  ksm: (filter: object) => ['organisasi', 'ksm', 'daftar', filter] as const,
  semuaKsm: ['organisasi', 'ksm'] as const,
}

export const LABEL_JENIS: Record<JenisUnit, string> = {
  instalasi: 'Instalasi',
  bidang: 'Bidang',
  bagian: 'Bagian',
  ruang: 'Ruang',
  poli: 'Poliklinik',
  depo: 'Depo farmasi',
}
export const DAFTAR_JENIS = Object.entries(LABEL_JENIS) as [JenisUnit, string][]

export const labelUnit = (u: Pick<UnitOrganisasi, 'kode' | 'nama'>) => `${u.kode} · ${u.nama}`
/** Jalur induk sebagai teks, mis. "IRNA › RUANG-MELATI" (tanpa unit itu sendiri bila `denganDiri` false). */
export const teksJalur = (u: Pick<UnitOrganisasi, 'jalur' | 'kode'>, denganDiri = false) =>
  [...u.jalur.map((j) => j.kode), ...(denganDiri ? [u.kode] : [])].join(' › ')

/** Unit penugasan ber-cakupan unit milik akun ini (pemilih unit kerja di header). Kosong = pemilih disembunyikan. */
export function useUnitSaya() {
  const sesi = useSesiStore()
  const id = computed(() => sesi.pengguna?.id ?? null)
  return useQuery({
    queryKey: computed(() => KUNCI_ORG.unitSaya(id.value)),
    queryFn: async () => (await daftarUnit({ milik_saya: true }, null, 200)).data,
    enabled: computed(() => id.value !== null),
    staleTime: 5 * 60_000,
  })
}
