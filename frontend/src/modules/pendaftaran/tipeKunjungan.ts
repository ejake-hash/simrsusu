import type { Halaman } from '@/modules/akses/tipePengaturan'
import type { RingkasPegawai } from '@/modules/jadwal-praktik/tipe'
import type { JenisPenjamin } from '@/modules/penjamin/tipe'
import type { JenisKelaminPasien } from './tipe'

/** Bentuk respons kunjungan poli (docs/api/openapi.yaml v0.20.0, tiket 2.6). Tanpa impor nilai agar dipakai data tiruan. */

export type JenisKunjungan = 'poliklinik' | 'igd' | 'rawat_inap_elektif' | 'penunjang_langsung' | 'mcu' | 'terjadwal_rutin' | 'telekonsultasi' | 'kemoterapi_sehari'
export type CaraBayar = 'jkn' | 'non_jkn'
export type StatusKunjungan = 'terdaftar' | 'dilayani' | 'selesai' | 'batal'

/** Dua penanda identitas untuk papan kunjungan (nama + No. RM/tanggal lahir, 5.1 butir 9); tanpa NIK/alamat. */
export interface RingkasPasienKunjungan {
  id: string
  no_rm: string
  nama: string
  jenis_kelamin: JenisKelaminPasien
  tanggal_lahir: string | null
}
export interface RingkasEpisode {
  id: string
  jenis: 'rawat_jalan_harian'
  mulai_tanggal: string
}
export interface RingkasPenjaminKunjungan {
  id: string
  kode: string
  nama: string
  jenis: JenisPenjamin
}
/** Pelaku; null = konsol/sistem (mis. pembatalan otomatis jam tutup). */
export interface Pelaku {
  id: string
  nama: string
}
export interface Kunjungan {
  id: string
  nomor: string
  pasien: RingkasPasienKunjungan
  episode: RingkasEpisode
  jenis_kunjungan: JenisKunjungan
  poliklinik: { id: string; kode: string; nama: string }
  dokter: RingkasPegawai
  tanggal: string
  cara_bayar: CaraBayar
  penjamin: RingkasPenjaminKunjungan | null
  status: StatusKunjungan
  keluhan: string | null
  alasan_batal: string | null
  dibatalkan_oleh: Pelaku | null
  dibatalkan_pada: string | null
  didaftarkan_oleh: Pelaku | null
  versi: string
  dibuat_pada: string
  diubah_pada: string
}
export interface IsianKunjungan {
  pasien_id: string
  jadwal_praktik_id: string
  tanggal: string
  jenis_kunjungan?: JenisKunjungan
  cara_bayar: CaraBayar
  penjamin_id?: string | null
  keluhan?: string | null
}
/** Satu slot dokter lain di poli yang sama pada tanggal itu yang masih punya sisa (rincian 409 `kuota_habis`). */
export interface SlotTerdekat {
  jadwal_praktik_id: string
  pegawai: RingkasPegawai
  sisa_jkn: number
  sisa_non_jkn: number
  jam_mulai: string
}
export interface FilterKunjungan {
  tanggal: string
  poli_id: string
  pegawai_id?: string
  status?: StatusKunjungan
}
export type HalamanKunjungan = Halaman<Kunjungan>
