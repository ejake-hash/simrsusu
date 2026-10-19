/** Bentuk respons jadwal praktik dan pengecualian (docs/api/openapi.yaml v0.17.0, tiket 2.4). Tanpa impor nilai agar dipakai data tiruan. */
export type JenisPengecualian = 'cuti' | 'libur'
export type AlasanTidakTersedia = 'cuti' | 'libur' | 'nonaktif'

export interface RingkasPegawai {
  id: string
  nama: string
  status: 'aktif' | 'akan_keluar' | 'keluar'
}
export interface RingkasLokasi {
  id: string
  kode_lengkap: string
  nama: string
}
export interface KeterisianJadwal {
  tanggal: string
  kuota_jkn: number
  terisi_jkn: number
  kuota_non_jkn: number
  terisi_non_jkn: number
  sisa_jkn: number
  sisa_non_jkn: number
  tersedia: boolean
  alasan_tidak_tersedia: AlasanTidakTersedia | null
}
export interface JadwalPengecualian {
  id: string
  jenis: JenisPengecualian
  pegawai: RingkasPegawai | null
  dari_tanggal: string
  sampai_tanggal: string
  alasan: string
  status: 'aktif' | 'batal'
  alasan_batal: string | null
  versi: string
  diubah_pada: string
}
export interface JadwalPraktik {
  id: string
  pegawai: RingkasPegawai
  poliklinik: { id: string; kode: string; nama: string }
  ruang: RingkasLokasi | null
  hari: number
  jam_mulai: string
  jam_selesai: string
  kuota_jkn: number
  kuota_non_jkn: number
  berlaku_dari: string
  berlaku_sampai: string | null
  status: 'aktif' | 'nonaktif'
  pada_tanggal: KeterisianJadwal | null
  pengecualian: JadwalPengecualian[]
  versi: string
  diubah_pada: string
}
export interface FilterJadwal {
  tanggal?: string
  poli_id?: string
  pegawai_id?: string
  status?: 'aktif' | 'nonaktif'
}
export interface IsianJadwal {
  pegawai_id: string
  poli_id: string
  ruang_id: string | null
  hari: number
  jam_mulai: string
  jam_selesai: string
  kuota_jkn: number
  kuota_non_jkn: number
  berlaku_dari: string
  berlaku_sampai: string | null
  dasar_perubahan: string
}
export type UbahJadwal = Partial<Omit<IsianJadwal, 'pegawai_id' | 'poli_id'>> & { status?: 'aktif' | 'nonaktif'; dasar_perubahan: string }
export interface IsianPengecualian {
  jenis: JenisPengecualian
  pegawai_id: string | null
  dari_tanggal: string
  sampai_tanggal: string
  alasan: string
}
