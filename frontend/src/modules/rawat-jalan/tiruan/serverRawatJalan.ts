import type { PermintaanTiruan, ResponsTiruan } from '../../akses/tiruan/serverTiruan'
import type { RingkasPegawai } from '../../jadwal-praktik/tipe'
import type { Pelaku, RingkasPasienKunjungan } from '../../pendaftaran/tipeKunjungan'
import type { Alergi, AsesmenAwal, BarisWorklist, IsianAsesmenAwal, TandaVital } from '../tipe'

/**
 * Server tiruan kontrak v0.23.0 (tiket 2.13): GET /rawat-jalan/worklist, asesmen awal (draf → selesai → perbaikan ≤ 2 × 24 jam
 * dengan If-Match), alergi pasien, dan POST /antrean/{id}/panggil untuk nomor miliknya. Keadaan di memori; antrean dan
 * kunjungan sintetis dibuat sendiri untuk poli contoh karena server tiruan pendaftaran tidak menerbitkan antrean.
 * Bukan sumber aturan.
 */
const error = (status: number, kode: string, pesan: string, rincian: Record<string, unknown>[] = []): ResponsTiruan => ({ status, isi: { error: { kode, pesan, rincian } } })
const NO_STORE = { 'Cache-Control': 'no-store' }
const HARI_MS = 86_400_000
const TANGGAL_RS = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit' })

export interface OpsiServerRawatJalan {
  boleh: (kode: string) => boolean
  poliId: string
  dokter: RingkasPegawai
  pasien: RingkasPasienKunjungan[]
  pelaku: () => Pelaku
  sekarang?: () => Date
}

interface BarisT {
  antrean_id: string
  kunjungan_id: string
  nomor: string
  pasien: RingkasPasienKunjungan
  status: BarisWorklist['status_antrean']
  tahap: BarisWorklist['tahap']
  asesmen: AsesmenAwal | null
  menunggu_sejak: string
}

const SYARAT_TV: (keyof TandaVital)[] = ['sistolik', 'diastolik', 'nadi', 'napas', 'suhu']
const BATAS_TV: Record<string, [number, number]> = {
  sistolik: [30, 300], diastolik: [10, 200], nadi: [10, 300], napas: [2, 100], suhu: [25, 45], spo2: [30, 100], berat_badan: [0.3, 400], tinggi_badan: [20, 250],
}

export function buatServerRawatJalan(opsi: OpsiServerRawatJalan) {
  const sekarang = () => opsi.sekarang?.() ?? new Date()
  const iso = () => sekarang().toISOString()
  let n = 0
  const uid = (p: string) => `${p}${String(++n).padStart(8, '0')}-0000-7000-8000-000000000000`
  const hariIni = TANGGAL_RS.format(sekarang())
  const baris: BarisT[] = opsi.pasien.map((p, i) => ({
    antrean_id: uid('a'), kunjungan_id: uid('c'), nomor: `RJ-${String(i + 1).padStart(3, '0')}`, pasien: p, status: 'menunggu', tahap: 'perawat', asesmen: null,
    menunggu_sejak: new Date(sekarang().getTime() - (30 - i * 10) * 60_000).toISOString(),
  }))
  const alergi: (Alergi & { pasien_id: string })[] = []
  let versiN = 0

  const bentukBaris = (b: BarisT): BarisWorklist => ({
    antrean_id: b.antrean_id, kunjungan_id: b.kunjungan_id, nomor_tampil: b.nomor, pasien: b.pasien, dokter: opsi.dokter, status_antrean: b.status, tahap: b.tahap,
    asesmen_awal: b.asesmen ? { id: b.asesmen.id, status: b.asesmen.status } : null,
    ada_alergi: alergi.some((a) => a.pasien_id === b.pasien.id && a.status === 'aktif'),
    terdaftar_pada: b.menunggu_sejak, menunggu_sejak: b.menunggu_sejak,
  })
  const etag = (a: AsesmenAwal) => ({ ETag: `"${a.versi}"` })
  const baru = (a: AsesmenAwal) => {
    a.versi = `v${++versiN}`
    a.diubah_pada = iso()
  }
  const validasi = (isian: IsianAsesmenAwal): ResponsTiruan | null => {
    for (const [k, v] of Object.entries(isian.tanda_vital ?? {})) {
      const batas = BATAS_TV[k]
      if (v != null && batas && (typeof v !== 'number' || v < batas[0] || v > batas[1])) return error(422, 'validasi_gagal', 'Isian tidak valid.', [{ kolom: `tanda_vital.${k}`, pesan: `Di luar batas ${batas[0]}–${batas[1]}.` }])
    }
    return null
  }
  const imt = (tv: TandaVital) => (tv.berat_badan && tv.tinggi_badan ? Math.round((tv.berat_badan / (tv.tinggi_badan / 100) ** 2) * 100) / 100 : null)
  const terapkan = (a: AsesmenAwal, isian: IsianAsesmenAwal) => {
    for (const k of ['keluhan_utama', 'riwayat_penyakit_sekarang', 'riwayat_penyakit_dahulu', 'catatan'] as const) if (k in isian) a[k] = isian[k] ?? null
    a.tanda_vital = { ...a.tanda_vital, ...isian.tanda_vital }
    a.skrining = { ...a.skrining, ...isian.skrining }
    a.imt = imt(a.tanda_vital)
  }

  function tanganiAsesmen(r: PermintaanTiruan, bagian: string[], metode: string): ResponsTiruan | null {
    if (bagian[0] === 'kunjungan' && bagian[2] === 'asesmen-awal') {
      const b = baris.find((x) => x.kunjungan_id === bagian[1])
      if (!b) return error(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.')
      if (metode === 'GET') {
        if (!opsi.boleh('rawat_jalan.asesmen_perawat.lihat') && !opsi.boleh('rawat_jalan.pemeriksaan.lihat')) return error(403, 'akses_ditolak', 'Anda tidak berhak melihat asesmen.')
        return b.asesmen ? { status: 200, header: etag(b.asesmen), isi: { data: b.asesmen } } : error(404, 'tidak_ditemukan', 'Asesmen awal belum dibuat.')
      }
      if (metode !== 'POST') return null
      if (!opsi.boleh('rawat_jalan.asesmen_perawat.input')) return error(403, 'akses_ditolak', 'Anda tidak berhak mengisi asesmen.')
      if (b.asesmen) return error(409, 'asesmen_sudah_ada', 'Kunjungan ini sudah punya asesmen awal.', [{ id: b.asesmen.id }])
      const isian = (r.isi ?? {}) as IsianAsesmenAwal
      const salah = validasi(isian)
      if (salah) return salah
      const a: AsesmenAwal = {
        id: uid('s'), kunjungan_id: b.kunjungan_id, episode_id: uid('e'), status: 'draf', keluhan_utama: null, riwayat_penyakit_sekarang: null, riwayat_penyakit_dahulu: null,
        tanda_vital: {}, imt: null, skrining: { skala_nyeri: null, lokasi_nyeri: null, risiko_jatuh: null, gizi_berisiko: null, catatan: null }, catatan: null,
        penulis: opsi.pelaku(), dibuat_pada: iso(), selesai_pada: null, batas_perbaikan_pada: null, jumlah_versi: 0, versi: '', diubah_pada: iso(),
      }
      terapkan(a, isian)
      baru(a)
      b.asesmen = a
      return { status: 201, header: etag(a), isi: { data: a } }
    }
    if (bagian[0] !== 'asesmen-awal') return null
    const b = baris.find((x) => x.asesmen?.id === bagian[1])
    const a = b?.asesmen
    if (!a) return error(404, 'tidak_ditemukan', 'Asesmen tidak ditemukan.')
    const izinPerbaiki = metode === 'PATCH' && opsi.boleh('rawat_jalan.asesmen_perawat.perbaiki')
    const izinInput = metode === 'POST' && opsi.boleh('rawat_jalan.asesmen_perawat.input')
    if (!izinPerbaiki && !izinInput) return error(403, 'akses_ditolak', 'Anda tidak berhak mengubah asesmen.')
    const im = r.header['if-match']
    if (!im) return error(428, 'if_match_wajib', 'Header If-Match wajib.')
    if (im !== `"${a.versi}"`) return error(412, 'versi_usang', 'Asesmen sudah berubah.')
    if (metode === 'POST' && bagian[2] === 'selesai') {
      if (a.status === 'selesai') return error(409, 'status_tidak_sesuai', 'Asesmen sudah selesai.')
      const kurang = [...(a.keluhan_utama ? [] : ['keluhan_utama']), ...SYARAT_TV.filter((k) => a.tanda_vital[k] == null).map((k) => `tanda_vital.${k}`)]
      if (kurang.length) return error(422, 'validasi_gagal', 'Isian wajib belum lengkap.', kurang.map((kolom) => ({ kolom, pesan: 'Wajib diisi.' })))
      a.status = 'selesai'
      a.selesai_pada = iso()
      a.batas_perbaikan_pada = new Date(sekarang().getTime() + 2 * HARI_MS).toISOString()
      b.tahap = 'dokter'
      b.status = 'menunggu'
      b.menunggu_sejak = iso()
      baru(a)
      return { status: 200, header: etag(a), isi: { data: a } }
    }
    if (metode === 'PATCH') {
      const { alasan_perubahan: alasan, ...isian } = (r.isi ?? {}) as IsianAsesmenAwal & { alasan_perubahan?: string }
      if (!Object.keys(isian).length) return error(422, 'validasi_gagal', 'Minimal satu isian.', [])
      const salah = validasi(isian)
      if (salah) return salah
      if (a.status === 'selesai') {
        if (a.batas_perbaikan_pada && new Date(a.batas_perbaikan_pada) <= sekarang()) return error(409, 'batas_perbaikan_lewat', 'Batas perbaikan sudah lewat.')
        if (!alasan || alasan.trim().length < 5) return error(422, 'validasi_gagal', 'Alasan perubahan wajib.', [{ kolom: 'alasan_perubahan', pesan: 'Minimal 5 karakter.' }])
        a.jumlah_versi += 1
      }
      terapkan(a, isian)
      baru(a)
      return { status: 200, header: etag(a), isi: { data: a } }
    }
    return null
  }

  function tanganiAlergi(r: PermintaanTiruan, bagian: string[], metode: string): ResponsTiruan | null {
    if (bagian[0] === 'pasien' && bagian[2] === 'alergi') {
      const pasienId = bagian[1]!
      if (!opsi.pasien.some((p) => p.id === pasienId)) return error(404, 'tidak_ditemukan', 'Pasien tidak ditemukan.')
      if (metode === 'GET') {
        if (!opsi.boleh('rawat_jalan.asesmen_perawat.lihat') && !opsi.boleh('rawat_jalan.pemeriksaan.lihat')) return error(403, 'akses_ditolak', 'Anda tidak berhak melihat alergi.')
        const status = r.kueri.get('status') ?? 'aktif'
        return { status: 200, isi: { data: alergi.filter((x) => x.pasien_id === pasienId && x.status === status).reverse(), meta: { kursor_berikutnya: null } } }
      }
      if (metode !== 'POST') return null
      if (!opsi.boleh('rawat_jalan.asesmen_perawat.input')) return error(403, 'akses_ditolak', 'Anda tidak berhak mencatat alergi.')
      const isi = (r.isi ?? {}) as { zat?: string; jenis?: Alergi['jenis']; reaksi?: string | null; tingkat?: Alergi['tingkat'] }
      const zat = (isi.zat ?? '').trim()
      if (zat.length < 2 || !isi.jenis) return error(422, 'validasi_gagal', 'Zat dan jenis wajib.', [{ kolom: zat.length < 2 ? 'zat' : 'jenis' }])
      const kunci = (s: string) => s.toLowerCase().replace(/\s+/g, ' ')
      if (alergi.some((x) => x.pasien_id === pasienId && x.status === 'aktif' && kunci(x.zat) === kunci(zat))) return error(409, 'alergi_sudah_tercatat', 'Alergi dengan zat ini sudah tercatat.')
      const baruA: Alergi = {
        id: uid('g'), pasien_id: pasienId, zat, jenis: isi.jenis, barang: null, reaksi: isi.reaksi || null, tingkat: isi.tingkat ?? null, status: 'aktif',
        dicatat_oleh: opsi.pelaku(), dicatat_pada: iso(), alasan_batal: null, dibatalkan_oleh: null, dibatalkan_pada: null,
      }
      alergi.push(baruA)
      return { status: 201, isi: { data: baruA } }
    }
    if (bagian[0] === 'alergi' && bagian[2] === 'batal' && metode === 'POST') {
      if (!opsi.boleh('rawat_jalan.asesmen_perawat.perbaiki')) return error(403, 'akses_ditolak', 'Anda tidak berhak membatalkan alergi.')
      const x = alergi.find((g) => g.id === bagian[1])
      if (!x) return error(404, 'tidak_ditemukan', 'Alergi tidak ditemukan.')
      const alasan = String(((r.isi ?? {}) as { alasan?: string }).alasan ?? '').trim()
      if (alasan.length < 5) return error(422, 'validasi_gagal', 'Alasan minimal 5 karakter.', [{ kolom: 'alasan' }])
      if (x.status === 'batal') return error(409, 'sudah_batal', 'Alergi sudah dibatalkan.')
      Object.assign(x, { status: 'batal', alasan_batal: alasan, dibatalkan_oleh: opsi.pelaku(), dibatalkan_pada: iso() })
      return { status: 200, isi: { data: x } }
    }
    return null
  }

  function tangani(r: PermintaanTiruan): ResponsTiruan | null {
    const bagian = r.jalur.replace(/^\/+|\/+$/g, '').split('/')
    const metode = r.metode.toUpperCase()
    if (bagian[0] === 'rawat-jalan' && bagian[1] === 'worklist' && metode === 'GET') {
      if (!opsi.boleh('rawat_jalan.worklist.lihat')) return error(403, 'akses_ditolak', 'Anda tidak berhak melihat worklist.')
      const tanggal = r.kueri.get('tanggal')
      const poliId = r.kueri.get('poli_id')
      if (!tanggal || !poliId) return error(422, 'validasi_gagal', 'Tanggal dan poliklinik wajib.', [{ kolom: !poliId ? 'poli_id' : 'tanggal' }])
      if (poliId !== opsi.poliId || tanggal !== hariIni) return { status: 200, header: NO_STORE, isi: { data: [], meta: { kursor_berikutnya: null } } }
      const tahap = r.kueri.get('tahap')
      const hasil = baris.filter((b) => !tahap || b.tahap === tahap).map(bentukBaris)
      return { status: 200, header: NO_STORE, isi: { data: hasil, meta: { kursor_berikutnya: null } } }
    }
    if (bagian[0] === 'antrean' && bagian[2] === 'panggil' && metode === 'POST') {
      const b = baris.find((x) => x.antrean_id === bagian[1])
      if (!b) return null
      if (!opsi.boleh('pendaftaran.antrean.perbaiki')) return error(403, 'akses_ditolak', 'Anda tidak berhak memanggil.')
      b.status = 'dipanggil'
      return { status: 200, isi: { data: { id: b.antrean_id, nomor_tampil: b.nomor, status: 'dipanggil' } } }
    }
    return tanganiAsesmen(r, bagian, metode) ?? tanganiAlergi(r, bagian, metode)
  }

  return { tangani, contoh: { poliId: opsi.poliId, baris } }
}

export type ServerRawatJalan = ReturnType<typeof buatServerRawatJalan>
