import { useQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import { ApiError } from '@/shared/api/klien'
import { ambilDaftarPeran, ambilPohonMenu } from './apiPengaturan'
import { indeksPohon } from './matriks'
import type { AkunRinci } from './tipePengaturan'

/**
 * Kunci cache TanStack Query layar Pengaturan. Daftar dan rincian sengaja tidak berbagi awalan: invalidasi
 * daftar sesudah perubahan tidak ikut memuat ulang setiap rincian yang pernah dibuka (hemat rate limit 120/menit).
 */
export const KUNCI = {
  pohon: ['pengaturan', 'menu'] as const,
  daftarPeran: ['pengaturan', 'daftar-peran'] as const,
  peran: (id: string) => ['pengaturan', 'peran', id] as const,
  daftarAkun: ['pengaturan', 'daftar-akun'] as const,
  /** Awalan semua rincian akun (termasuk menu Lihat sebagai). */
  semuaAkun: ['pengaturan', 'akun'] as const,
  akun: (id: string) => ['pengaturan', 'akun', id] as const,
  menuAkun: (id: string) => ['pengaturan', 'akun', id, 'menu'] as const,
  labelAkun: (id: string) => ['pengaturan', 'akun', id, 'label'] as const,
  labelSensitivitas: ['pengaturan', 'label-sensitivitas'] as const,
  /** Awalan antrean dan rincian persetujuan akses (tiket 1.2d). */
  persetujuan: ['pengaturan', 'persetujuan'] as const,
  daftarPersetujuan: (tab: string, status: string | undefined) => ['pengaturan', 'persetujuan', 'daftar', tab, status ?? 'semua'] as const,
  rinciPersetujuan: (id: string) => ['pengaturan', 'persetujuan', 'rinci', id] as const,
  permintaan: ['pengaturan', 'permintaan'] as const,
  daftarPermintaan: (filter: object) => ['pengaturan', 'permintaan', 'daftar', filter] as const,
}

/** Pohon /menu jarang berubah (hanya saat rilis/modul dinyalakan): cukup sekali per buka halaman. */
export function usePohonMenu() {
  const kueri = useQuery({ queryKey: KUNCI.pohon, queryFn: ambilPohonMenu, staleTime: 5 * 60_000 })
  const indeks = computed(() => (kueri.data.value ? indeksPohon(kueri.data.value) : null))
  return { ...kueri, indeks }
}

/** Peran aktif untuk pilihan (filter akun, tugaskan peran): satu halaman berbatas 200, cukup untuk daftar peran RS. */
export function usePeranAktif() {
  return useQuery({
    queryKey: [...KUNCI.daftarPeran, 'pilihan'],
    queryFn: async () => (await ambilDaftarPeran({ status: 'aktif' }, null, 200)).data,
    staleTime: 60_000,
  })
}

/** Akun boleh diubah penyunting ini (tombol ubah dirender) — PRD 9.2.4; server tetap memeriksa ulang. */
export function bolehUbahAkun(akun: AkunRinci): boolean {
  return akun.dapat_diubah
}

export function pesanError(e: unknown): string {
  return e instanceof ApiError ? e.message : 'Terjadi kesalahan. Coba lagi.'
}

/**
 * Pesan per kolom dari `rincian` (422) untuk vee-validate `setErrors`, hanya kolom yang ada di formulir
 * (`peta` menerjemahkan nama kolom API ke nama kolom formulir). Kosong = tampilkan pesan umum dari server.
 */
export function errorKolom(e: unknown, kolomForm: readonly string[], peta: Record<string, string> = {}): Record<string, string> {
  if (!(e instanceof ApiError)) return {}
  const hasil: Record<string, string> = {}
  for (const r of e.rincian) {
    if (typeof r.kolom !== 'string' || typeof r.pesan !== 'string') continue
    const kolom = peta[r.kolom] ?? r.kolom
    if (kolomForm.includes(kolom)) hasil[kolom] = r.pesan
  }
  return hasil
}
