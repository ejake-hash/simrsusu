import { defineStore } from 'pinia'
import { ref } from 'vue'
import { ambilSaya, keluar as keluarApi, type Pengguna } from '@/modules/akses/api'
import { ApiError } from '@/shared/api/klien'

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

  /**
   * Cek sesi sekali per muat halaman. Server tak terjangkau atau error lain = anggap belum masuk
   * (guard mengarahkan ke /masuk, bukan halaman kosong) dan cek lagi di navigasi berikutnya.
   */
  async function pastikan(): Promise<void> {
    if (sudahDicek.value) return
    try {
      pengguna.value = await ambilSaya()
      sudahDicek.value = true
    } catch (error) {
      pengguna.value = null
      sudahDicek.value = error instanceof ApiError && error.status === 401
    }
  }

  async function muatSetelahMasuk(): Promise<void> {
    pengguna.value = await ambilSaya()
    sudahDicek.value = true
    pesanBerakhir.value = null
  }

  /** Dipanggil saat server menjawab 401 di luar langkah masuk. */
  function akhiri(error: ApiError): void {
    pengguna.value = null
    sudahDicek.value = true
    pesanBerakhir.value = error.kode === 'belum_masuk' ? null : error.message
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
