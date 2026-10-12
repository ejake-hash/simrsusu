/** Tipe tanda tangan elektronik T1 (docs/api/openapi.yaml v0.8.0, tiket 1.6). Tanpa kunci atau PIN di klien. */

export interface StatusTtd {
  /** false = belum diaktifkan, atau dicabut karena reset (aktifkan ulang dengan PIN baru). */
  aktif: boolean
  diaktifkan_pada: string | null
  /** Terisi bila TTE terakhir dicabut karena reset dan belum diaktifkan ulang. */
  dicabut_pada: string | null
  /** Terisi selama terkunci karena 5 PIN salah. */
  terkunci_sampai: string | null
  /** Tandatangani tanpa PIN sampai waktu ini (15 menit sesudah PIN benar, sesi login yang sama). */
  sesi_berlaku_sampai: string | null
  /** Ada persetujuan reset_ttd menunggu untuk akun ini. */
  reset_menunggu: boolean
}

export type JenisObjekTtd = 'persetujuan_akses' | 'usulan_data_induk'
export type StatusVerifikasi = 'sah' | 'isi_berubah' | 'tanda_tangan_tidak_sah'

export interface TandaTangan {
  id: string
  objek_jenis: JenisObjekTtd
  objek_id: string
  penanda_tangan: { id: string; nama: string; nip: string | null }
  tingkat: 'T1'
  metode: 'pin'
  /** SHA-256 isi kanonik saat ditandatangani. */
  hash_isi: string
  /** 16 hex awal SHA-256 kunci publik yang dipakai. */
  sidik_kunci: string
  /** true = kunci yang dipakai sudah diganti karena reset (tanda tangan tetap bisa sah). */
  kredensial_dicabut: boolean
  ditandatangani_pada: string
  verifikasi: { status: StatusVerifikasi; diperiksa_pada: string }
}

export interface HasilTandaTangan {
  /** Sepanjang dan seurut objek yang dikirim; `baru = false` = tanda tangan lama akun ini. */
  data: (TandaTangan & { baru: boolean })[]
  meta: { sesi_berlaku_sampai: string }
}
