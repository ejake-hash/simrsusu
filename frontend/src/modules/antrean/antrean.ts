import { ApiError } from '@/shared/api/klien'
import type { StatusAntrean } from './tipe'

export const KUNCI_ANTREAN = {
  semua: ['antrean'] as const,
  daftar: (filter: object) => ['antrean', 'daftar', filter] as const,
}

export const LABEL_STATUS: Record<StatusAntrean, string> = {
  belum_check_in: 'Belum check-in',
  menunggu: 'Menunggu',
  dipanggil: 'Dipanggil',
  dilayani: 'Dilayani',
  dilewati: 'Dilewati',
  kembali: 'Kembali',
  selesai: 'Selesai',
  batal: 'Batal',
}
export const NADA_STATUS: Record<StatusAntrean, 'ok' | 'info' | 'netral' | 'peringatan'> = {
  belum_check_in: 'netral',
  menunggu: 'info',
  dipanggil: 'ok',
  dilayani: 'ok',
  dilewati: 'peringatan',
  kembali: 'info',
  selesai: 'netral',
  batal: 'peringatan',
}

export const jamWib = (iso: string) => `${new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Jakarta', hourCycle: 'h23' }).format(new Date(iso)).replace(':', '.')} WIB`

/** Pesan aksi yang ditolak (409): pesan server ditambah petunjuk langkah berikutnya dari kodenya. */
export function pesanAksi(e: unknown): string {
  if (!(e instanceof ApiError)) return 'Terjadi kesalahan. Coba lagi.'
  if (e.kode === 'jeda_panggilan_belum_lewat') {
    const pada = e.rincian[0]?.boleh_panggil_pada
    return typeof pada === 'string' ? `${e.message} Panggil lagi mulai pukul ${jamWib(pada)}.` : e.message
  }
  if (e.kode === 'batas_panggilan_tercapai') return `${e.message} Tandai Lewati bila pasien belum hadir.`
  if (e.kode === 'sesi_berakhir') return `${e.message} Pilihan yang tersisa: batalkan nomor ini.`
  return e.message
}

