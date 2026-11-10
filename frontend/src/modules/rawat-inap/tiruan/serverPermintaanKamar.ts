import type { PermintaanTiruan, ResponsTiruan } from '../../akses/tiruan/serverTiruan'
import type { PermintaanTempatTidur, RingkasLokasiPeta, TempatTidurPeta } from '../tipe'
import { LOKASI_PETA_CONTOH } from './serverPetaTempatTidur'

/**
 * Server tiruan kontrak v0.36.0 (tiket 2.28): GET /permintaan-tempat-tidur (aktif, terlama dulu, kursor), POST
 * /permintaan-tempat-tidur/{id}/alokasi (TT peta tiruan → dipesan), POST /kunjungan/{id}/spri. Pasien dan kunjungan
 * SINTETIS berlabel contoh. Lama menunggu dihitung dari `sekarang` seperti server (dibulatkan ke atas, 0 bila belum tiba).
 */
const NO_STORE = { 'Cache-Control': 'no-store' }
const error = (status: number, kode: string, pesan: string, rincian: Record<string, unknown>[] = []): ResponsTiruan => ({ status, header: NO_STORE, isi: { error: { kode, pesan, rincian } } })
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i
const id = (n: number) => `00000000-0000-7000-b228-${n.toString(16).padStart(12, '0')}`

export const KELAS_LAIN_CONTOH: RingkasLokasiPeta = { id: id(900), kode: 'CONTOH-KELAS-2', nama: 'Kelas lain contoh' }

interface Simpanan extends Omit<PermintaanTempatTidur, 'lama_menunggu_menit'> {
  revisi: number
}

export interface OpsiServerPermintaanKamar {
  boleh: (izin: string) => boolean
  /** Daftar TT peta tiruan (sumber yang sama dengan GET /peta-tempat-tidur). */
  tempatTidur: () => TempatTidurPeta[]
  sekarang?: () => Date
}

export function buatServerPermintaanKamar(opsi: OpsiServerPermintaanKamar) {
  const sekarang = opsi.sekarang ?? (() => new Date())
  const L = LOKASI_PETA_CONTOH
  let nomor = 0
  const daftar: Simpanan[] = []
  function tambah(asal: 'igd' | 'poliklinik', nama: string, nomorKunjungan: string, mintaPada: string, lain: Partial<Simpanan> = {}) {
    const n = ++nomor
    const p: Simpanan = {
      id: id(n), kunjungan: { id: id(100 + n), nomor: nomorKunjungan, jenis_kunjungan: asal },
      pasien: { id: id(200 + n), no_rm: `90000${n}`, nama, jenis_kelamin: 'perempuan', tanggal_lahir: '1970-01-01' },
      asal, status: 'menunggu', kelas_diminta: asal === 'igd' ? null : L.kelas, bangsal_pilihan: null,
      spri: { id: id(300 + n), tanggal_rencana: mintaPada.slice(0, 10), no_surat_bpjs: null },
      diminta_oleh: { id: id(400), nama: 'Contoh Dokter' }, diminta_pada: mintaPada, menunggu_sejak: mintaPada,
      tempat_tidur: null, dialokasikan_pada: null, revisi: 0, ...lain,
    }
    daftar.push(p)
    return p
  }
  // Urutan menunggu_sejak: IGD terlama, poli, lalu poli kelas lain dengan rencana besok (belum tiba).
  tambah('igd', 'Pasien Contoh IGD', 'IGD-001', '2026-11-02T01:20:00.000Z')
  tambah('poliklinik', 'Pasien Contoh Poli', 'PD-001', '2026-11-02T02:30:00.000Z', { bangsal_pilihan: L.melati })
  tambah('poliklinik', 'Pasien Contoh Kelas Lain', 'PD-002', '2026-11-02T02:45:00.000Z', {
    kelas_diminta: KELAS_LAIN_CONTOH, spri: { id: id(399), tanggal_rencana: '2026-11-03', no_surat_bpjs: null }, menunggu_sejak: '2026-11-02T17:00:00.000Z',
  })

  const lama = (p: Simpanan) => Math.max(0, Math.ceil((sekarang().getTime() - new Date(p.menunggu_sejak).getTime()) / 60_000))
  const keluar = (p: Simpanan): PermintaanTempatTidur => {
    const { revisi: _r, ...isi } = structuredClone(p)
    return { ...isi, lama_menunggu_menit: lama(p) }
  }

  function antrean(r: PermintaanTiruan): ResponsTiruan {
    if (!opsi.boleh('pendaftaran.admisi.lihat')) return error(403, 'akses_ditolak', 'Anda tidak berhak melihat antrean permintaan kamar.')
    const status = r.kueri.get('status')
    if (status && status !== 'menunggu' && status !== 'dialokasikan') return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'status', pesan: 'Status tidak dikenal.' }])
    const batas = Number(r.kueri.get('batas') ?? 50)
    if (batas > 200) return error(422, 'ukuran_halaman_melebihi_batas', 'Batas maksimal 200.')
    const aktif = daftar
      .filter((p) => (p.status === 'menunggu' || p.status === 'dialokasikan') && (!status || p.status === status))
      .sort((a, b) => a.menunggu_sejak.localeCompare(b.menunggu_sejak) || a.id.localeCompare(b.id))
    const mulai = Number(r.kueri.get('kursor') ?? 0)
    const halaman = aktif.slice(mulai, mulai + batas)
    const lanjut = mulai + batas < aktif.length ? String(mulai + batas) : null
    return { status: 200, header: NO_STORE, isi: { data: halaman.map(keluar), meta: { kursor_berikutnya: lanjut, batas } } }
  }

  function alokasi(permintaanId: string, r: PermintaanTiruan): ResponsTiruan {
    if (!opsi.boleh('pendaftaran.admisi.input')) return error(403, 'akses_ditolak', 'Anda tidak berhak mengalokasikan tempat tidur.')
    const p = UUID.test(permintaanId) ? daftar.find((x) => x.id === permintaanId) : undefined
    if (!p) return error(404, 'tidak_ditemukan', 'Permintaan kamar tidak ditemukan.')
    // Urutan kontrak: permintaan (404, 409 bukan menunggu) sebelum TT (422, 409, 422 kelas).
    if (p.status !== 'menunggu') return error(409, 'permintaan_kamar_tidak_menunggu', 'Permintaan kamar tidak lagi menunggu.', [{ status: p.status }])
    const isi = (r.isi ?? {}) as Record<string, unknown>
    const ttId = typeof isi.tempat_tidur_id === 'string' ? isi.tempat_tidur_id : ''
    const tt = UUID.test(ttId) ? opsi.tempatTidur().find((x) => x.id === ttId) : undefined
    if (!tt) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'tempat_tidur_id', pesan: 'Pilih TT yang ada.' }])
    if (tt.ditutup || (tt.status === 'kosong_siap' && !tt.dapat_dipilih)) return error(409, 'tempat_tidur_ditutup', 'Tempat tidur sudah ditutup.')
    if (tt.status !== 'kosong_siap') return error(409, 'tempat_tidur_tidak_tersedia', 'Tempat tidur tidak kosong siap.', [{ status_sekarang: tt.status }])
    if (p.kelas_diminta && p.kelas_diminta.id !== tt.kelas_rawat.id) return error(422, 'kelas_tidak_sesuai', 'Kelas TT berbeda dengan kelas yang diminta.')
    const jam = sekarang().toISOString()
    tt.status = 'dipesan'
    tt.status_sejak = jam
    tt.dapat_dipilih = false
    p.status = 'dialokasikan'
    p.tempat_tidur = { id: tt.id, kode: tt.kode, bangsal: tt.bangsal }
    p.dialokasikan_pada = jam
    p.revisi++
    return { status: 200, header: NO_STORE, isi: { data: keluar(p) } }
  }

  function spri(kunjunganId: string, r: PermintaanTiruan): ResponsTiruan {
    if (!opsi.boleh('rawat_jalan.pemeriksaan.sahkan')) return error(403, 'akses_ditolak', 'Anda tidak berhak memberi SPRI.')
    if (!UUID.test(kunjunganId)) return error(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.')
    const ada = daftar.find((x) => x.kunjungan.id === kunjunganId && (x.status === 'menunggu' || x.status === 'dialokasikan'))
    if (ada) return error(409, 'permintaan_kamar_sudah_ada', 'Kunjungan ini sudah punya permintaan kamar yang masih berjalan.', [{ permintaan_tempat_tidur_id: ada.id }])
    const isi = (r.isi ?? {}) as Record<string, unknown>
    const rincian: Record<string, unknown>[] = []
    const alasan = typeof isi.alasan === 'string' ? isi.alasan.trim() : ''
    if (alasan.length < 5) rincian.push({ kolom: 'alasan', pesan: 'Isi alasan rawat, minimal 5 karakter.' })
    if (typeof isi.kelas_rawat_id !== 'string' || !UUID.test(isi.kelas_rawat_id)) rincian.push({ kolom: 'kelas_rawat_id', pesan: 'Pilih kelas rawat yang aktif.' })
    const hariIni = sekarang().toISOString().slice(0, 10)
    if (typeof isi.tanggal_rencana === 'string' && isi.tanggal_rencana < hariIni) rincian.push({ kolom: 'tanggal_rencana', pesan: 'Isi hari ini atau sesudahnya.' })
    if (rincian.length) return error(422, 'validasi_gagal', 'Isian belum benar.', rincian)
    const jam = sekarang().toISOString()
    const tanggal = typeof isi.tanggal_rencana === 'string' ? isi.tanggal_rencana : hariIni
    const kelas = isi.kelas_rawat_id === KELAS_LAIN_CONTOH.id ? KELAS_LAIN_CONTOH : { ...L.kelas, id: isi.kelas_rawat_id as string }
    const p = tambah('poliklinik', 'Pasien Contoh SPRI', 'PD-100', jam, {
      kunjungan: { id: kunjunganId, nomor: 'PD-100', jenis_kunjungan: 'poliklinik' }, kelas_diminta: kelas,
      bangsal_pilihan: isi.bangsal_id === L.melati.id ? L.melati : null, spri: { id: id(500 + nomor), tanggal_rencana: tanggal, no_surat_bpjs: null },
      menunggu_sejak: tanggal > hariIni ? `${tanggal}T00:00:00.000Z` : jam,
    })
    return { status: 201, header: NO_STORE, isi: { data: keluar(p) } }
  }

  function tangani(r: PermintaanTiruan): ResponsTiruan | null {
    const bagian = r.jalur.replace(/\/+$/, '').split('/').filter(Boolean)
    const m = r.metode.toUpperCase()
    if (bagian[0] === 'permintaan-tempat-tidur' && bagian.length === 1 && m === 'GET') return antrean(r)
    if (bagian[0] === 'permintaan-tempat-tidur' && bagian[2] === 'alokasi' && bagian.length === 3 && m === 'POST') return alokasi(bagian[1]!, r)
    if (bagian[0] === 'kunjungan' && bagian[2] === 'spri' && bagian.length === 3 && m === 'POST') return spri(bagian[1]!, r)
    return null
  }

  return { tangani, daftar: () => daftar }
}
