import { defineStore } from 'pinia'
import { ref } from 'vue'
import { ambilSaya, keluar as keluarApi, type Pengguna } from '@/modules/akses/api'
import { GalatApi } from '@/shared/api/klien'

/**
 * Sesi di klien hanya cermin dari cookie sesi server (HttpOnly). Isinya di memori saja: tidak
 * ada token atau data pengguna di localStorage/sessionStorage (PRD 14.2). Muat ulang halaman
 * = tanya server lagi lewat GET /saya.
 */
export const useSesiStore = defineStore('sesi', () => {
  const pengguna = ref<Pengguna | null>(null)
  const sudahDicek = ref(false)
  /** Pesan dari server saat sesi berakhir (mis. sesi_kedaluwarsa), ditampilkan di halaman masuk. */
  const pesanBerakhir = ref<string | null>(null)

  async function pastikan(): Promise<void> {
    if (sudahDicek.value) return
    try {
      pengguna.value = await ambilSaya()
    } catch (galat) {
      if (!(galat instanceof GalatApi) || galat.status !== 401) throw galat
      pengguna.value = null
    }
    sudahDicek.value = true
  }

  async function muatSetelahMasuk(): Promise<void> {
    pengguna.value = await ambilSaya()
    sudahDicek.value = true
    pesanBerakhir.value = null
  }

  /** Dipanggil saat server menjawab 401 di luar langkah masuk. */
  function akhiri(galat: GalatApi): void {
    pengguna.value = null
    sudahDicek.value = true
    pesanBerakhir.value = galat.kode === 'belum_masuk' ? null : galat.message
  }

  async function keluar(): Promise<void> {
    try {
      await keluarApi()
    } finally {
      pengguna.value = null
      pesanBerakhir.value = null
    }
  }

  return { pengguna, sudahDicek, pesanBerakhir, pastikan, muatSetelahMasuk, akhiri, keluar }
})
