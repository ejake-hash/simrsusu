import type { PermintaanTiruan, ResponsTiruan } from '../../akses/tiruan/serverTiruan'
import type { StatusPermintaanItem } from '@/shared/api/permintaanPemeriksaan'
import type { BarisDaftarKerjaLab, HasilLab, LokasiPengambilanSpesimen, NilaiHasilLab, PenandaHasilLab, Spesimen, TahapDaftarKerjaLab } from '../tipe'

/**
 * Server tiruan kontrak v0.40.0 (tiket 2.30): GET /lab/daftar-kerja (prioritas wajib, terlama dulu, kursor; order keluar
 * sesudah tidak ada butir dipesan/sampel_diambil), POST /permintaan-item/{id}/spesimen (barcode LAB-YYYYMMDD-NNNNN), POST
 * /spesimen/{id}/terima. v0.41.0 (tiket 2.31): parameter `tahap`, POST /permintaan-item/{id}/hasil, POST
 * /hasil-lab/{id}/validasi, POST /hasil-lab/{id}/adendum, GET /episode/{id}/hasil-lab. Pasien, order, dan data induk
 * SINTETIS berlabel contoh (meniru seeder `lokal:kunjungan-contoh`, bukan nilai klinis). `dapat` dari izin, seperti server.
 */
const TAHAP: Record<TahapDaftarKerjaLab, StatusPermintaanItem[]> = {
  spesimen: ['dipesan', 'sampel_diambil'],
  hasil: ['diterima_lab', 'menunggu_validasi'],
  otorisasi: ['menunggu_otorisasi'],
}
interface DataInduk {
  jenis: 'numerik' | 'teks'
  satuan: string | null
  spk: boolean
  rujukan: [min: number | null, maks: number | null, kritisMin: number | null, kritisMaks: number | null] | null
  kritisTeks?: string[]
}
/** Data induk sintetis: 01 numerik + rujukan, 02 teks + nilai kritis teks, 03 butuh otorisasi Sp.PK, 04 tanpa rujukan. */
const DATA_INDUK: Record<string, DataInduk> = {
  'CONTOH-LAB-01': { jenis: 'numerik', satuan: '{contoh}', spk: false, rujukan: [10, 20, 5, 30] },
  'CONTOH-LAB-02': { jenis: 'teks', satuan: null, spk: false, rujukan: null, kritisTeks: ['Contoh kritis'] },
  'CONTOH-LAB-03': { jenis: 'numerik', satuan: '{contoh}', spk: true, rujukan: [1, 2, null, 5] },
  'CONTOH-LAB-04': { jenis: 'numerik', satuan: '{contoh}', spk: false, rujukan: null },
}

/** Penanda seperti server (pembanding tegas, batas kritis didahulukan); hanya di server tiruan, layar tidak menghitung. */
function nilaiDanPenanda(d: DataInduk, nilai: number | null, nilaiTeks: string | null): NilaiHasilLab {
  const [rujukanMin, rujukanMaks, kritisMin, kritisMaks] = d.rujukan ?? [null, null, null, null]
  let penanda: PenandaHasilLab = null
  let peringatan: NilaiHasilLab['peringatan'] = null
  if (d.jenis === 'teks') {
    if (!d.kritisTeks?.length) peringatan = 'nilai_rujukan_tidak_ada'
    else if (d.kritisTeks.some((k) => k.toLowerCase() === nilaiTeks?.toLowerCase())) penanda = 'kritis'
  } else if (!d.rujukan) {
    peringatan = 'nilai_rujukan_tidak_ada'
  } else if (nilai !== null) {
    if (kritisMin !== null && nilai < kritisMin) penanda = 'll'
    else if (kritisMaks !== null && nilai > kritisMaks) penanda = 'hh'
    else if (rujukanMin !== null && nilai < rujukanMin) penanda = 'l'
    else if (rujukanMaks !== null && nilai > rujukanMaks) penanda = 'h'
  }
  return {
    nilai, nilai_teks: nilaiTeks, satuan: d.satuan, rujukan_min: rujukanMin, rujukan_maks: rujukanMaks, kritis_min: kritisMin, kritis_maks: kritisMaks,
    penanda, kritis: penanda === 'll' || penanda === 'hh' || penanda === 'kritis', peringatan,
  }
}
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

  /** Episode tiap order (BarisDaftarKerjaLab tidak membawanya; HasilLab membawanya). */
  const episodeOrder = (orderId: string) => id(300 + order.findIndex((o) => o.id === orderId) + 1)
  const hasil = new Map<string, Omit<HasilLab, 'dapat'>>()
  let nomorHasil = 0

  const aktif = (o: Simpanan, tahap: TahapDaftarKerjaLab) => o.status === 'dipesan' && o.item.some((i) => TAHAP[tahap].includes(i.status))
  const dapatHasil = (h: Omit<HasilLab, 'dapat'>): HasilLab['dapat'] => ({
    input: h.status === 'menunggu_validasi' && opsi.boleh('penunjang.laboratorium.input'),
    validasi: (h.status === 'menunggu_validasi' && opsi.boleh('penunjang.laboratorium.sahkan'))
      || (h.status === 'menunggu_otorisasi' && opsi.boleh('penunjang.laboratorium.setujui') && h.divalidasi_oleh?.id !== pelaku().id),
    adendum: h.status === 'divalidasi' && opsi.boleh(h.butuh_otorisasi_spk ? 'penunjang.laboratorium.setujui' : 'penunjang.laboratorium.sahkan'),
  })
  const keluarHasil = (h: Omit<HasilLab, 'dapat'>): HasilLab => ({ ...structuredClone(h), dapat: dapatHasil(h) })
  const keluar = (o: Simpanan): BarisDaftarKerjaLab => {
    const ambil = opsi.boleh('penunjang.laboratorium.input')
    const terima = opsi.boleh('penunjang.laboratorium.verifikasi')
    return {
      ...structuredClone(o),
      item: o.item
        .filter((i) => i.status !== 'batal')
        .map((i) => {
          const h = hasil.get(i.id)
          return {
            ...structuredClone(i),
            spesimen: i.spesimen ? { ...structuredClone(i.spesimen), item_status: i.status } : null,
            hasil: h ? keluarHasil(h) : null,
            dapat: {
              ambil: i.status === 'dipesan' && ambil, terima: i.status === 'sampel_diambil' && terima,
              input: (i.status === 'diterima_lab' || i.status === 'menunggu_validasi') && ambil, validasi: h ? dapatHasil(h).validasi : false,
            },
          }
        }),
    }
  }

  function daftar(r: PermintaanTiruan): ResponsTiruan {
    if (!opsi.boleh('penunjang.laboratorium.lihat')) return error(403, 'akses_ditolak', 'Anda tidak berhak melihat daftar kerja lab.')
    const prioritas = r.kueri.get('prioritas')
    if (prioritas !== 'cito' && prioritas !== 'rutin') return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'prioritas', pesan: 'Pilih cito atau rutin.' }])
    const tahap = (r.kueri.get('tahap') ?? 'spesimen') as TahapDaftarKerjaLab
    if (!(tahap in TAHAP)) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'tahap', pesan: 'Tahap tidak dikenal.' }])
    const batas = Number(r.kueri.get('batas') ?? 50)
    if (batas > 200) return error(422, 'ukuran_halaman_melebihi_batas', 'Batas maksimal 200.')
    const semua = order.filter((o) => o.prioritas === prioritas && aktif(o, tahap)).sort((a, b) => a.dipesan_pada.localeCompare(b.dipesan_pada) || a.id.localeCompare(b.id))
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

  /** Isian sesuai jenis hasil data induk; null = benar (kontrak: 422 validasi_gagal per kolom). */
  function cekIsian(d: DataInduk, isi: Record<string, unknown>): ResponsTiruan | null {
    const angka = typeof isi.nilai === 'number'
    const teks = typeof isi.nilai_teks === 'string' && isi.nilai_teks.trim().length > 0
    if (d.jenis === 'numerik' && (!angka || teks)) return error(422, 'validasi_gagal', 'Hasil pemeriksaan ini berupa angka: isi nilai, tanpa nilai teks.', [{ kolom: 'nilai', pesan: 'Nilai angka wajib untuk pemeriksaan ini.' }])
    if (d.jenis === 'teks' && (angka || !teks)) return error(422, 'validasi_gagal', 'Hasil pemeriksaan ini berupa teks: isi nilai teks, tanpa nilai angka.', [{ kolom: 'nilai_teks', pesan: 'Nilai teks wajib untuk pemeriksaan ini.' }])
    return null
  }

  function inputHasil(itemId: string, r: PermintaanTiruan): ResponsTiruan {
    if (!opsi.boleh('penunjang.laboratorium.input')) return error(403, 'akses_ditolak', 'Anda tidak berhak menginput hasil lab.')
    const t = UUID.test(itemId) ? cariButir(itemId) : null
    if (!t) return error(404, 'tidak_ditemukan', 'Butir order lab tidak ditemukan.')
    if (t.i.status === 'divalidasi') return error(409, 'hasil_sudah_divalidasi', 'Hasil sudah divalidasi.')
    if (t.o.status === 'batal' || (t.i.status !== 'diterima_lab' && t.i.status !== 'menunggu_validasi')) return error(409, 'status_tidak_sesuai', 'Butir ini tidak bisa diinput hasilnya sekarang.')
    const d = DATA_INDUK[t.i.kode_item]
    if (!d) return error(422, 'pemeriksaan_lab_belum_diatur', 'Data induk pemeriksaan lab belum diatur.')
    const isi = (r.isi ?? {}) as Record<string, unknown>
    const salah = cekIsian(d, isi)
    if (salah) return salah
    const nilai = d.jenis === 'numerik' ? (isi.nilai as number) : null
    const nilaiTeks = d.jenis === 'teks' ? (isi.nilai_teks as string).trim() : null
    const ada = hasil.get(t.i.id)
    const h: Omit<HasilLab, 'dapat'> = {
      id: ada?.id ?? id(700 + ++nomorHasil), permintaan_item_id: t.i.id, permintaan_pemeriksaan_id: t.o.id, episode_id: episodeOrder(t.o.id),
      kode_item: t.i.kode_item, nama: t.i.nama, status: 'menunggu_validasi', jenis_hasil: d.jenis, kode_loinc: null,
      catatan: typeof isi.catatan === 'string' ? isi.catatan : null, butuh_otorisasi_spk: d.spk, pasien: structuredClone(t.o.pasien),
      diinput_oleh: pelaku(), diinput_pada: sekarang().toISOString(), divalidasi_oleh: null, divalidasi_pada: null,
      diotorisasi_oleh: null, diotorisasi_pada: null, dikeluarkan_pada: null, adendum: [], ...nilaiDanPenanda(d, nilai, nilaiTeks),
    }
    hasil.set(t.i.id, h)
    t.i.status = 'menunggu_validasi'
    return { status: ada ? 200 : 201, header: NO_STORE, isi: { data: keluarHasil(h) } }
  }

  const cariHasil = (hasilId: string) => [...hasil.values()].find((h) => h.id === hasilId)

  function validasi(hasilId: string): ResponsTiruan {
    const h = UUID.test(hasilId) ? cariHasil(hasilId) : undefined
    if (!h) return error(404, 'tidak_ditemukan', 'Hasil lab tidak ditemukan.')
    const t = cariButir(h.permintaan_item_id)!
    if (h.status === 'divalidasi') return error(409, 'hasil_sudah_divalidasi', 'Hasil sudah divalidasi.')
    if (t.o.status === 'batal') return error(409, 'status_tidak_sesuai', 'Order sudah dibatalkan.')
    const jam = sekarang().toISOString()
    if (h.status === 'menunggu_validasi') {
      if (!opsi.boleh('penunjang.laboratorium.sahkan')) return error(403, 'akses_ditolak', 'Anda tidak berhak memvalidasi hasil lab.')
      Object.assign(h, { divalidasi_oleh: pelaku(), divalidasi_pada: jam, status: h.butuh_otorisasi_spk ? 'menunggu_otorisasi' : 'divalidasi', dikeluarkan_pada: h.butuh_otorisasi_spk ? null : jam })
    } else {
      if (!opsi.boleh('penunjang.laboratorium.setujui')) return error(403, 'akses_ditolak', 'Anda tidak berhak mengotorisasi hasil lab.')
      if (h.divalidasi_oleh?.id === pelaku().id) return error(409, 'sudah_memvalidasi', 'Anda sudah memvalidasi hasil ini.')
      Object.assign(h, { diotorisasi_oleh: pelaku(), diotorisasi_pada: jam, status: 'divalidasi', dikeluarkan_pada: jam })
    }
    t.i.status = h.status
    return { status: 200, header: NO_STORE, isi: { data: keluarHasil(h) } }
  }

  function adendum(hasilId: string, r: PermintaanTiruan): ResponsTiruan {
    const h = UUID.test(hasilId) ? cariHasil(hasilId) : undefined
    if (!h) return error(404, 'tidak_ditemukan', 'Hasil lab tidak ditemukan.')
    if (!opsi.boleh(h.butuh_otorisasi_spk ? 'penunjang.laboratorium.setujui' : 'penunjang.laboratorium.sahkan')) return error(403, 'akses_ditolak', 'Anda tidak berhak membuat adendum hasil ini.')
    if (h.status !== 'divalidasi') return error(409, 'status_tidak_sesuai', 'Hasil belum divalidasi.')
    const isi = (r.isi ?? {}) as Record<string, unknown>
    const alasan = typeof isi.alasan === 'string' ? isi.alasan.trim() : ''
    if (alasan.length < 5 || alasan.length > 500) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'alasan', pesan: 'Alasan 5 sampai 500 karakter.' }])
    const d = DATA_INDUK[h.kode_item]!
    const salah = cekIsian(d, isi)
    if (salah) return salah
    h.adendum.push({
      id: id(750 + h.adendum.length + 10 * nomorHasil), hasil_id: h.id, alasan, dibuat_oleh: pelaku(), dibuat_pada: sekarang().toISOString(),
      ...nilaiDanPenanda(d, d.jenis === 'numerik' ? (isi.nilai as number) : null, d.jenis === 'teks' ? (isi.nilai_teks as string).trim() : null),
    })
    return { status: 201, header: NO_STORE, isi: { data: keluarHasil(h) } }
  }

  function linimasa(episodeId: string, r: PermintaanTiruan): ResponsTiruan {
    if (!opsi.boleh('rawat_jalan.pemeriksaan.lihat')) return error(403, 'akses_ditolak', 'Anda tidak berhak membuka hasil lab episode ini.')
    if (!order.some((o) => episodeOrder(o.id) === episodeId)) return error(404, 'tidak_ditemukan', 'Episode tidak ditemukan.')
    const semua = [...hasil.values()].filter((h) => h.episode_id === episodeId && h.status === 'divalidasi').sort((a, b) => b.dikeluarkan_pada!.localeCompare(a.dikeluarkan_pada!) || b.id.localeCompare(a.id))
    const batas = Number(r.kueri.get('batas') ?? 50)
    const mulai = Number(r.kueri.get('kursor') ?? 0)
    const lanjut = mulai + batas < semua.length ? String(mulai + batas) : null
    return { status: 200, header: NO_STORE, isi: { data: semua.slice(mulai, mulai + batas).map(keluarHasil), meta: { kursor_berikutnya: lanjut, batas } } }
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
      if (bagian[0] === 'permintaan-item' && bagian[2] === 'hasil' && bagian.length === 3 && m === 'POST') return inputHasil(bagian[1]!, r)
      if (bagian[0] === 'hasil-lab' && bagian[2] === 'validasi' && bagian.length === 3 && m === 'POST') return validasi(bagian[1]!)
      if (bagian[0] === 'hasil-lab' && bagian[2] === 'adendum' && bagian.length === 3 && m === 'POST') return adendum(bagian[1]!, r)
      if (bagian[0] === 'episode' && bagian[2] === 'hasil-lab' && bagian.length === 3 && m === 'GET') return linimasa(bagian[1]!, r)
      return null
    },
  }
}
