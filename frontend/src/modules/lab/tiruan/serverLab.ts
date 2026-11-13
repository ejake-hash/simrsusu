import type { PermintaanTiruan, ResponsTiruan } from '../../akses/tiruan/serverTiruan'
import type { BarisDaftarKerjaLab, LokasiPengambilanSpesimen, Spesimen } from '../tipe'

/**
 * Server tiruan kontrak v0.40.0 (tiket 2.30): GET /lab/daftar-kerja (prioritas wajib, terlama dulu, kursor; order keluar
 * sesudah tidak ada butir dipesan/sampel_diambil), POST /permintaan-item/{id}/spesimen (barcode LAB-YYYYMMDD-NNNNN), POST
 * /spesimen/{id}/terima. Pasien dan order SINTETIS berlabel contoh. `dapat` per butir dari izin, seperti server.
 */
const NO_STORE = { 'Cache-Control': 'no-store' }
const error = (status: number, kode: string, pesan: string, rincian: Record<string, unknown>[] = []): ResponsTiruan => ({ status, header: NO_STORE, isi: { error: { kode, pesan, rincian } } })
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i
const id = (n: number) => `00000000-0000-7000-b230-${n.toString(16).padStart(12, '0')}`
const LOKASI: LokasiPengambilanSpesimen[] = ['instalasi', 'samping_tt', 'kamar_operasi']

type Simpanan = Omit<BarisDaftarKerjaLab, 'item'> & { item: (Omit<BarisDaftarKerjaLab['item'][number], 'dapat' | 'spesimen'> & { spesimen: Spesimen | null })[] }

export interface OpsiServerLab {
  boleh: (izin: string) => boolean
  sekarang?: () => Date
  pelaku?: () => { id: string; nama: string }
}

export function buatServerLab(opsi: OpsiServerLab) {
  const sekarang = opsi.sekarang ?? (() => new Date())
  const pelaku = opsi.pelaku ?? (() => ({ id: id(900), nama: 'Contoh Analis Lab' }))
  const pemesan = { id: id(800), nama: 'Contoh Dokter Poli' }
  const order: Simpanan[] = [
    {
      id: id(1), prioritas: 'cito', status: 'dipesan', dipesan_pada: '2026-11-02T01:10:00.000Z', diagnosis_kerja: 'Demam sintetis', pemesan,
      pasien: { id: id(101), no_rm: '900001', nama: 'Pasien Contoh Cito', tanggal_lahir: '1970-01-01' },
      asal: { kunjungan_id: id(201), jenis_kunjungan: 'poliklinik', nama: 'Poli Penyakit Dalam (contoh)' },
      lokasi_saat_ini: { jenis: 'poli', nama: 'Poli Penyakit Dalam (contoh)', tempat_tidur: null },
      item: [
        { id: id(11), kode_item: 'CONTOH-LAB-01', nama: 'Pemeriksaan lab contoh satu', status: 'dipesan', spesimen: null },
        { id: id(12), kode_item: 'CONTOH-LAB-02', nama: 'Pemeriksaan lab contoh dua', status: 'dipesan', spesimen: null },
      ],
    },
    {
      id: id(2), prioritas: 'rutin', status: 'dipesan', dipesan_pada: '2026-11-02T00:30:00.000Z', diagnosis_kerja: 'Kontrol sintetis', pemesan: null,
      pasien: { id: id(102), no_rm: '900002', nama: 'Pasien Contoh Rawat Inap', tanggal_lahir: null },
      asal: { kunjungan_id: id(202), jenis_kunjungan: 'poliklinik', nama: 'Poli Penyakit Dalam (contoh)' },
      lokasi_saat_ini: { jenis: 'rawat_inap', nama: 'Bangsal Melati (contoh)', tempat_tidur: 'CTHG-2-202-3' },
      item: [{ id: id(21), kode_item: 'CONTOH-LAB-01', nama: 'Pemeriksaan lab contoh satu', status: 'dipesan', spesimen: null }],
    },
    {
      id: id(3), prioritas: 'rutin', status: 'dipesan', dipesan_pada: '2026-11-02T02:00:00.000Z', diagnosis_kerja: 'Rujukan sintetis', pemesan,
      pasien: { id: id(103), no_rm: '900003', nama: 'Pasien Contoh Tanpa Lokasi', tanggal_lahir: '1985-05-05' },
      asal: { kunjungan_id: id(203), jenis_kunjungan: null, nama: null },
      lokasi_saat_ini: null,
      item: [{ id: id(31), kode_item: 'CONTOH-LAB-03', nama: 'Pemeriksaan lab contoh tiga', status: 'dipesan', spesimen: null }],
    },
  ]
  let nomor = 0

  const aktif = (o: Simpanan) => o.status === 'dipesan' && o.item.some((i) => i.status === 'dipesan' || i.status === 'sampel_diambil')
  const keluar = (o: Simpanan): BarisDaftarKerjaLab => {
    const ambil = opsi.boleh('penunjang.laboratorium.input')
    const terima = opsi.boleh('penunjang.laboratorium.verifikasi')
    return {
      ...structuredClone(o),
      item: o.item
        .filter((i) => i.status !== 'batal')
        .map((i) => ({ ...structuredClone(i), spesimen: i.spesimen ? { ...structuredClone(i.spesimen), item_status: i.status } : null, dapat: { ambil: i.status === 'dipesan' && ambil, terima: i.status === 'sampel_diambil' && terima } })),
    }
  }

  function daftar(r: PermintaanTiruan): ResponsTiruan {
    if (!opsi.boleh('penunjang.laboratorium.lihat')) return error(403, 'akses_ditolak', 'Anda tidak berhak melihat daftar kerja lab.')
    const prioritas = r.kueri.get('prioritas')
    if (prioritas !== 'cito' && prioritas !== 'rutin') return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'prioritas', pesan: 'Pilih cito atau rutin.' }])
    const batas = Number(r.kueri.get('batas') ?? 50)
    if (batas > 200) return error(422, 'ukuran_halaman_melebihi_batas', 'Batas maksimal 200.')
    const semua = order.filter((o) => o.prioritas === prioritas && aktif(o)).sort((a, b) => a.dipesan_pada.localeCompare(b.dipesan_pada) || a.id.localeCompare(b.id))
    const mulai = Number(r.kueri.get('kursor') ?? 0)
    const lanjut = mulai + batas < semua.length ? String(mulai + batas) : null
    return { status: 200, header: NO_STORE, isi: { data: semua.slice(mulai, mulai + batas).map(keluar), meta: { kursor_berikutnya: lanjut, batas } } }
  }

  const cariButir = (itemId: string) => {
    for (const o of order) {
      const i = o.item.find((x) => x.id === itemId)
      if (i) return { o, i }
    }
    return null
  }

  function ambil(itemId: string, r: PermintaanTiruan): ResponsTiruan {
    if (!opsi.boleh('penunjang.laboratorium.input')) return error(403, 'akses_ditolak', 'Anda tidak berhak mengambil sampel lab.')
    const t = UUID.test(itemId) ? cariButir(itemId) : null
    if (!t) return error(404, 'tidak_ditemukan', 'Butir order lab tidak ditemukan.')
    if (t.o.status === 'batal' || t.i.status !== 'dipesan') return error(409, 'status_tidak_sesuai', 'Sampel butir ini sudah diambil atau order sudah dibatalkan.')
    const isi = (r.isi ?? {}) as Record<string, unknown>
    const rincian: Record<string, unknown>[] = []
    const jenis = typeof isi.jenis === 'string' ? isi.jenis.trim() : ''
    if (jenis.length < 2 || jenis.length > 100) rincian.push({ kolom: 'jenis', pesan: 'Jenis spesimen 2 sampai 100 karakter.' })
    if (!LOKASI.includes(isi.lokasi_pengambilan as LokasiPengambilanSpesimen)) rincian.push({ kolom: 'lokasi_pengambilan', pesan: 'Pilih lokasi pengambilan.' })
    if (rincian.length) return error(422, 'validasi_gagal', 'Isian belum benar.', rincian)
    const jam = sekarang()
    const tanggal = jam.toISOString().slice(0, 10).replace(/-/g, '')
    t.i.status = 'sampel_diambil'
    t.i.spesimen = {
      id: id(500 + ++nomor), permintaan_item_id: t.i.id, permintaan_pemeriksaan_id: t.o.id, item_status: 'sampel_diambil',
      barcode: `LAB-${tanggal}-${String(nomor).padStart(5, '0')}`, jenis, lokasi_pengambilan: isi.lokasi_pengambilan as LokasiPengambilanSpesimen,
      diambil_pada: jam.toISOString(), diambil_oleh: pelaku(), diterima_pada: null, diterima_oleh: null, kondisi: null,
    }
    return { status: 201, header: NO_STORE, isi: { data: structuredClone(t.i.spesimen) } }
  }

  function terima(spesimenId: string): ResponsTiruan {
    if (!opsi.boleh('penunjang.laboratorium.verifikasi')) return error(403, 'akses_ditolak', 'Anda tidak berhak menerima spesimen.')
    const t = order.flatMap((o) => o.item.map((i) => ({ o, i }))).find((x) => x.i.spesimen?.id === spesimenId)
    if (!t?.i.spesimen) return error(404, 'tidak_ditemukan', 'Spesimen tidak ditemukan.')
    if (t.o.status === 'batal' || t.i.status !== 'sampel_diambil') return error(409, 'status_tidak_sesuai', 'Spesimen sudah diterima atau order sudah dibatalkan.')
    t.i.status = 'diterima_lab'
    Object.assign(t.i.spesimen, { item_status: 'diterima_lab', diterima_pada: sekarang().toISOString(), diterima_oleh: pelaku(), kondisi: 'baik' })
    return { status: 200, header: NO_STORE, isi: { data: structuredClone(t.i.spesimen) } }
  }

  return {
    order,
    /** Uji: batalkan order dari pos pemesan (2.16) agar aksi lab berikutnya 409. */
    batalkan: (orderId: string) => {
      const o = order.find((x) => x.id === orderId)
      if (o) {
        o.status = 'batal'
        o.item.forEach((i) => (i.status = 'batal'))
      }
    },
    tangani(r: PermintaanTiruan): ResponsTiruan | null {
      const m = r.metode.toUpperCase()
      const bagian = r.jalur.split('/').filter(Boolean)
      if (bagian[0] === 'lab' && bagian[1] === 'daftar-kerja' && bagian.length === 2 && m === 'GET') return daftar(r)
      if (bagian[0] === 'permintaan-item' && bagian[2] === 'spesimen' && bagian.length === 3 && m === 'POST') return ambil(bagian[1]!, r)
      if (bagian[0] === 'spesimen' && bagian[2] === 'terima' && bagian.length === 3 && m === 'POST') return terima(bagian[1]!)
      return null
    },
  }
}
