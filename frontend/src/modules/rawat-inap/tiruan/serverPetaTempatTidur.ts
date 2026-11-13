import type { PermintaanTiruan, ResponsTiruan } from '../../akses/tiruan/serverTiruan'
import type { RingkasLokasiPeta, StatusTempatTidur, TempatTidurPeta } from '../tipe'

/**
 * Server tiruan kontrak v0.35.0 (tiket 2.27): GET /peta-tempat-tidur (ETag/If-None-Match → 304, saring gedung_id dan
 * bangsal_id) dan POST /tempat-tidur/{id}/status (dibersihkan → kosong_siap). Gedung CTHG sama dengan
 * `lokal:kunjungan-contoh` (enam status); gedung CTHA menambah TT isolasi, ekstra, aturan jenis kelamin, dan TT ditutup.
 * Semua lokasi SINTETIS berlabel contoh. `hari_rawat` hanya pada TT berpasien (v0.37.0).
 */
const NO_STORE = { 'Cache-Control': 'no-store' }
const error = (status: number, kode: string, pesan: string, rincian: Record<string, unknown>[] = []): ResponsTiruan => ({ status, header: NO_STORE, isi: { error: { kode, pesan, rincian } } })
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

/** Sidik sederhana (FNV-1a) isi JSON untuk ETag tiruan. */
function sidik(isi: unknown): string {
  let h = 0x811c9dc5
  for (const c of JSON.stringify(isi)) {
    h ^= c.charCodeAt(0)
    h = Math.imul(h, 0x01000193) >>> 0
  }
  return `"${h.toString(16)}"`
}

const id = (n: number) => `00000000-0000-7000-b200-${n.toString(16).padStart(12, '0')}`
const lok = (n: number, kode: string, nama: string): RingkasLokasiPeta => ({ id: id(n), kode, nama })

export const LOKASI_PETA_CONTOH = {
  gedungRawatInap: lok(1, 'CTHG', 'Gedung Rawat Inap (contoh)'),
  lantai2: lok(2, '2', 'Lantai 2 (contoh)'),
  melati: lok(3, 'CONTOH-MELATI', 'Bangsal Melati (contoh)'),
  kamar201: lok(4, '201', 'Kamar 201 (contoh)'),
  kamar202: lok(5, '202', 'Kamar 202 (contoh)'),
  gedungAnggrek: lok(6, 'CTHA', 'Gedung Anggrek (contoh)'),
  lantai1: lok(7, '1', 'Lantai 1 (contoh)'),
  anggrek: lok(8, 'CONTOH-ANGGREK', 'Bangsal Anggrek (contoh)'),
  kamar101: lok(9, '101', 'Kamar 101 (contoh)'),
  kelas: lok(10, 'CONTOH-KELAS', 'Kelas contoh'),
} as const

export interface OpsiServerPetaTempatTidur {
  boleh: (izin: string) => boolean
  sekarang?: () => Date
}

export function buatServerPetaTempatTidur(opsi: OpsiServerPetaTempatTidur) {
  const sekarang = opsi.sekarang ?? (() => new Date())
  const L = LOKASI_PETA_CONTOH
  let nomor = 100
  // Kode TT yang penutupannya sudah dijadwalkan (v0.36.0): tetap kosong siap tetapi tidak dapat dipilih.
  const tutupTerjadwal = new Set(['CTHA-1-101-4'])
  const dapatDipilih = (tt: Pick<TempatTidurPeta, 'kode' | 'ditutup' | 'status'>) => !tt.ditutup && tt.status === 'kosong_siap' && !tutupTerjadwal.has(tt.kode)
  const daftar: TempatTidurPeta[] = []
  function tambah(gedung: RingkasLokasiPeta, lantai: RingkasLokasiPeta, bangsal: RingkasLokasiPeta, kamar: RingkasLokasiPeta, kode: string, status: StatusTempatTidur, lain: Partial<TempatTidurPeta> = {}) {
    const ditutup = lain.ditutup ?? false
    const tt: TempatTidurPeta = {
      id: id(++nomor), kode, kamar, bangsal, lantai, gedung, kelas_rawat: L.kelas, aturan_jenis_kelamin: 'campur', isolasi: false, ekstra: false,
      status: ditutup ? 'tidak_dipakai' : status, status_sejak: '2026-11-02T01:00:00.000Z', ditutup, dapat_dipilih: dapatDipilih({ kode, ditutup, status }), hari_rawat: null, ...lain,
    }
    daftar.push(tt)
    return tt
  }
  // Urutan server: gedung, lantai, bangsal, kode TT.
  tambah(L.gedungAnggrek, L.lantai1, L.anggrek, L.kamar101, 'CTHA-1-101-1', 'kosong_siap', { isolasi: true, aturan_jenis_kelamin: 'laki_laki' })
  tambah(L.gedungAnggrek, L.lantai1, L.anggrek, L.kamar101, 'CTHA-1-101-2', 'dibersihkan', { aturan_jenis_kelamin: 'perempuan' })
  tambah(L.gedungAnggrek, L.lantai1, L.anggrek, L.kamar101, 'CTHA-1-101-3', 'tidak_dipakai', { ditutup: true })
  tambah(L.gedungAnggrek, L.lantai1, L.anggrek, L.kamar101, 'CTHA-1-101-4', 'kosong_siap', { ekstra: true })
  const contoh: [RingkasLokasiPeta, string, StatusTempatTidur][] = [
    [L.kamar201, 'CTHG-2-201-1', 'terisi'], [L.kamar201, 'CTHG-2-201-2', 'dibersihkan'], [L.kamar201, 'CTHG-2-201-3', 'dipesan'],
    [L.kamar202, 'CTHG-2-202-1', 'rencana_pulang'], [L.kamar202, 'CTHG-2-202-2', 'tidak_dipakai'], [L.kamar202, 'CTHG-2-202-3', 'kosong_siap'],
  ]
  // TT berpasien membawa hari rawat (v0.37.0); TT lain null.
  const hariRawat: Partial<Record<StatusTempatTidur, number>> = { terisi: 3, rencana_pulang: 5 }
  // v0.38.0: TT berpasien membawa id kunjungan rawat inap (disimpan di sini, dikirim hanya bagi izin pindah ruang).
  let nomorRi = 500
  for (const [kamar, kode, status] of contoh) {
    const berpasien = hariRawat[status] !== undefined
    const n = nomorRi
    const pasien = { nama: n === 500 ? 'Pasien Contoh Rawat Inap' : 'Pasien Contoh Rencana Pulang', no_rm: `9000${99 - (n - 500)}`, tanggal_lahir: '1965-05-05' }
    tambah(L.gedungRawatInap, L.lantai2, L.melati, kamar, kode, status, {
      hari_rawat: hariRawat[status] ?? null, ...(berpasien ? { kunjungan_rawat_inap_id: id(nomorRi++), pasien_rawat_inap: pasien } : {}),
    })
  }
  /** Bentuk keluar: id kunjungan dan identitas pasien dibuang bila pemanggil tidak memegang izin pembukanya. */
  const keluar = (tt: TempatTidurPeta, bolehPindah: boolean): TempatTidurPeta => {
    const salinan = structuredClone(tt)
    if (!bolehPindah || salinan.ditutup || !salinan.kunjungan_rawat_inap_id) {
      delete salinan.kunjungan_rawat_inap_id
      delete salinan.pasien_rawat_inap
    }
    return salinan
  }

  function peta(r: PermintaanTiruan): ResponsTiruan {
    if (!opsi.boleh('rawat_inap.peta_tempat_tidur.lihat')) return error(403, 'akses_ditolak', 'Anda tidak berhak melihat peta tempat tidur.')
    const gedung = r.kueri.get('gedung_id')
    const bangsal = r.kueri.get('bangsal_id')
    for (const [kolom, nilai] of [['gedung_id', gedung], ['bangsal_id', bangsal]] as const) {
      if (nilai && !UUID.test(nilai)) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom, pesan: 'Harus UUID.' }])
    }
    const data = daftar.filter((tt) => (!gedung || tt.gedung?.id === gedung) && (!bangsal || tt.bangsal.id === bangsal))
    // v0.39.0: lihat pemulangan atau lihat rencana pulang juga membuka id kunjungan dan identitas.
    const bolehPindah = ['rawat_inap.pindah_ruang.input', 'rawat_inap.pemulangan.lihat', 'rawat_inap.rencana_pulang.lihat'].some((i) => opsi.boleh(i))
    const isi = { data: data.map((tt) => keluar(tt, bolehPindah)), meta: { terpotong: false } }
    const e = sidik(isi)
    if (r.header['if-none-match'] === e) return { status: 304, header: { ...NO_STORE, ETag: e }, isi: null }
    return { status: 200, header: { ...NO_STORE, ETag: e }, isi: structuredClone(isi) }
  }

  function ubahStatus(ttId: string, r: PermintaanTiruan): ResponsTiruan {
    if (!opsi.boleh('rawat_inap.peta_tempat_tidur.perbaiki')) return error(403, 'akses_ditolak', 'Anda tidak berhak mengubah status tempat tidur.')
    const tt = UUID.test(ttId) ? daftar.find((x) => x.id === ttId) : undefined
    if (!tt) return error(404, 'tidak_ditemukan', 'Tempat tidur tidak ditemukan.')
    const isi = (r.isi ?? {}) as Record<string, unknown>
    if (isi.status !== 'kosong_siap' || Object.keys(isi).length !== 1) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'status', pesan: 'Status hanya boleh kosong_siap.' }])
    if (tt.ditutup) return error(409, 'tempat_tidur_ditutup', 'Tempat tidur sudah ditutup.')
    if (tt.status !== 'dibersihkan') {
      return error(409, 'transisi_status_tempat_tidur_tidak_sah', 'Status tempat tidur sudah berubah. Muat ulang peta.', [{ status_sekarang: tt.status, status_diminta: 'kosong_siap' }])
    }
    tt.status = 'kosong_siap'
    tt.status_sejak = sekarang().toISOString()
    tt.dapat_dipilih = dapatDipilih(tt)
    return { status: 200, header: NO_STORE, isi: { data: keluar(tt, false) } }
  }

  function tangani(r: PermintaanTiruan): ResponsTiruan | null {
    const bagian = r.jalur.replace(/\/+$/, '').split('/').filter(Boolean)
    const m = r.metode.toUpperCase()
    if (bagian[0] === 'peta-tempat-tidur' && bagian.length === 1 && m === 'GET') return peta(r)
    if (bagian[0] === 'tempat-tidur' && bagian[2] === 'status' && bagian.length === 3 && m === 'POST') return ubahStatus(bagian[1]!, r)
    return null
  }

  /** Untuk uji: ubah status TT seolah dari alur lain (2.28/2.29). */
  function aturStatus(kode: string, status: StatusTempatTidur) {
    const tt = daftar.find((x) => x.kode === kode)
    if (tt) {
      tt.status = status
      tt.dapat_dipilih = dapatDipilih(tt)
    }
  }

  return { tangani, aturStatus, daftar: () => daftar }
}
