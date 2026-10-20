/** Tipe buku tarif (docs/api/openapi.yaml v0.18.0, tiket 2.2). Rupiah selalu string desimal, tanpa float. */

export interface HargaTarif {
  kelas_rawat_id: string
  kelas_rawat_label: string
  jasa_sarana: string
  jasa_pelayanan: string
  bhp: string
  /** Jumlah tiga komponen, dihitung server. */
  total: string
}

export interface IsianHargaTarif {
  kelas_rawat_id: string
  jasa_sarana: string
  jasa_pelayanan: string
  bhp: string
}

export interface Tarif {
  id: string
  kode: string
  nama: string
  kelompok: string
  status: 'aktif' | 'nonaktif'
  berlaku_dari: string
  harga: HargaTarif[]
  akan_berlaku: { berlaku_dari: string; harga: HargaTarif[] } | null
  /** Hanya terisi bila akun punya master_data.usulan.lihat. */
  usulan_terbuka: { id: string; nomor: string } | null
  versi: string
  diubah_pada: string
}

export interface FilterTarif {
  cari?: string
  status?: 'aktif' | 'nonaktif'
  kelompok?: string
  kelas_rawat_id?: string
  tanggal?: string
}
