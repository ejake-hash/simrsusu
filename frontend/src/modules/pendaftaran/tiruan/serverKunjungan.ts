import type { PermintaanTiruan, ResponsTiruan } from '../../akses/tiruan/serverTiruan'
import type { KeterisianJadwal, RingkasPegawai } from '../../jadwal-praktik/tipe'
import type {
  CaraBayar,
  Disposisi,
  InstrumenTriase,
  KategoriTriase,
  Kunjungan,
  Pelaku,
  RingkasEpisode,
  RingkasPasienKunjungan,
  RingkasPenjaminKunjungan,
  SlotTerdekat,
  StatusDisposisi,
  StatusKunjungan,
  Triase,
  TujuanDisposisi,
} from '../tipeKunjungan'

/**
 * Server tiruan kontrak v0.20.0 (tiket 2.6) dan v0.31.0 (2.23: GET/POST /kunjungan-igd, IGD aktif per pasien satu, tanda
 * `lebih_6_jam` dari jam `sekarang`; pemindahan ke episode `igd_lebih_6_jam` tidak ditiru), dan v0.32.0 (2.24: triase
 * hanya ditambah, berlaku = terbaru; instrumen dari `instrumenTriase`; field `triase` di daftar IGD bagi pemegang
 * igd.triase.lihat), dan v0.33.0 (2.26: GET/POST /kunjungan/{id}/disposisi; tujuan dari `matriks`, SIP dari `sipBerlaku`).
 * POST /kunjungan (episode rawat jalan harian terbentuk/digabung otomatis
 * dari No. RM + tanggal, 7.00.2), POST /kunjungan/{id}/batal (status batal, kuota dikembalikan), GET /kunjungan
 * (papan per poli per hari). Kuota dipinjam dari server jadwal (tiket 2.4) lewat `konsumsi`, jadi sisa di layar jadwal
 * ikut berkurang. Keadaan di memori; aturan dari kontrak termasuk 404/409/422. Semua data sintetis; bukan sumber aturan.
 */
const error = (status: number, kode: string, pesan: string, rincian: Record<string, unknown>[] = []): ResponsTiruan => ({ status, isi: { error: { kode, pesan, rincian } } })
const NO_STORE = { 'Cache-Control': 'no-store' }
const TANGGAL_RS = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit' })
const TANGGAL = /^\d{4}-\d{2}-\d{2}$/
/** Hari ISO 1–7 dari YYYY-MM-DD. */
const hariIso = (tgl: string) => new Date(`${tgl}T00:00:00Z`).getUTCDay() || 7
const tambahHari = (tgl: string, n: number) => {
  const d = new Date(`${tgl}T00:00:00Z`)
  d.setUTCDate(d.getUTCDate() + n)
  return d.toISOString().slice(0, 10)
}
/** Kunjungan boleh didaftarkan hingga sekian hari ke depan (di luar itu 422 tanggal_di_luar_jadwal). */
const RENTANG_DEPAN_HARI = 90

type AmbilJadwal = {
  id: string
  poli: { id: string; kode: string; nama: string }
  dokter: RingkasPegawai
  hari: number
  jam_mulai: string
  jam_selesai: string
  berlaku_dari: string
  berlaku_sampai: string | null
  status: 'aktif' | 'nonaktif'
  kuota_jkn: number
  kuota_non_jkn: number
}

export interface OpsiServerKunjungan {
  boleh: (kode: string) => boolean
  jadwal: {
    ambil: (id: string) => AmbilJadwal | null
    keterisian: (id: string, tanggal: string) => KeterisianJadwal | null
    konsumsi: (id: string, tanggal: string, cara: CaraBayar, delta: number) => void
    slotPoli: (poliId: string, tanggal: string, kecualiId: string) => SlotTerdekat[]
  }
  pasien: (id: string) => RingkasPasienKunjungan | null
  /** RM sudah digabung (409 pasien_digabung, IGD). Bawaan: tidak pernah. */
  pasienDigabung?: (id: string) => boolean
  penjamin: (id: string) => RingkasPenjaminKunjungan | null
  pelaku: () => Pelaku
  sekarang?: () => Date
  /** Instrumen triase aktif dari data induk (v0.32.0, server instrumen-triase). Bawaan: satu instrumen sintetis berlabel contoh. */
  instrumenTriase?: () => InstrumenTriase[]
  /** Sel matriks perpindahan aktif (v0.33.0, server matriks tiruan). Tanpa ini semua disposisi 422 di luar matriks. */
  matriks?: { berlaku: (asal: 'igd', tujuan: string) => { id: string; pos_asal: string; pos_tujuan: string; izin_perintah: string } | null }
  /** SIP/SPK akun berlaku hari ini (lapis 5, izin klinis). Bawaan: berlaku. */
  sipBerlaku?: () => boolean
}

const TUJUAN_DISPOSISI: readonly TujuanDisposisi[] = ['pulang', 'rawat_inap', 'kamar_operasi', 'rujuk_keluar', 'aps', 'meninggal', 'doa']
/** Pos tujuan matriks yang bukan disposisi FR-03-09 (konsul, order, rawat inap). */
const BUKAN_DISPOSISI = ['poli_lain', 'penunjang', 'igd', 'icu_hcu']
const MENUNGGU: Partial<Record<TujuanDisposisi, StatusDisposisi>> = { rawat_inap: 'menunggu_kamar', kamar_operasi: 'menunggu_kamar_operasi' }
const IZIN_KLINIS = ['igd.disposisi.input']

interface TriaseT {
  id: string
  kunjungan_id: string
  kategori: KategoriTriase
  instrumen: InstrumenTriase | null
  skala: string | null
  /** Salinan label skala saat triase (riwayat tidak ikut berubah bila data induk diedit). */
  skala_label: string | null
  keluhan: string | null
  ditriase_pada: string
  petugas: Pelaku
}
const KATEGORI_TRIASE: readonly KategoriTriase[] = ['merah', 'kuning', 'hijau', 'hitam']
/** Instrumen SINTETIS (bukan instrumen klinis RS). */
export const INSTRUMEN_TRIASE_CONTOH: InstrumenTriase[] = [{
  id: '00000000-0000-7000-a000-000000000001', kode: 'CONTOH-TRIASE', nama: 'Instrumen triase contoh',
  skala: [1, 2, 3].map((n) => ({ kode: `C${n}`, label: `Tingkat contoh ${n}` })),
}]

interface KunjunganT {
  id: string
  nomor: string
  jenis: 'poliklinik' | 'igd'
  pasien: RingkasPasienKunjungan
  episode: RingkasEpisode
  /** null untuk IGD (tanpa jadwal, poli, dan dokter). */
  jadwal_praktik_id: string | null
  poli: { id: string; kode: string; nama: string } | null
  dokter: RingkasPegawai | null
  tanggal: string
  tiba_pada: string | null
  cara_bayar: CaraBayar
  penjamin: RingkasPenjaminKunjungan | null
  status: StatusKunjungan
  keluhan: string | null
  alasan_batal: string | null
  dibatalkan_oleh: Pelaku | null
  dibatalkan_pada: string | null
  didaftarkan_oleh: Pelaku
  v: number
  dibuat_pada: string
}

export function buatServerKunjungan(opsi: OpsiServerKunjungan) {
  const sekarang = opsi.sekarang ?? (() => new Date())
  const iso = () => sekarang().toISOString()
  const hariIni = () => TANGGAL_RS.format(sekarang())
  let idN = 0
  let epN = 0
  const uid = (grup: string, n: number) => `00000000-0000-7000-${grup}-${n.toString(16).padStart(12, '0')}`
  const daftar: KunjunganT[] = []
  /** Satu episode rawat jalan harian per pasien per tanggal (7.00.2). */
  const episode = new Map<string, RingkasEpisode>()
  /** Nomor kunjungan berurutan per hari: RJ-YYYYMMDD-NNNN. */
  const urutHarian = new Map<string, number>()
  /** Riwayat triase, urut dicatat (hanya ditambah). */
  const triase: TriaseT[] = []
  let trN = 0
  const instrumenAktif = () => opsi.instrumenTriase?.() ?? INSTRUMEN_TRIASE_CONTOH

  const episodeHarian = (pasienId: string, tanggal: string): RingkasEpisode => {
    const kunci = `${pasienId}|${tanggal}`
    let ep = episode.get(kunci)
    if (!ep) {
      ep = { id: uid('e000', ++epN), jenis: 'rawat_jalan_harian', mulai_tanggal: tanggal }
      episode.set(kunci, ep)
    }
    return ep
  }
  /** Nomor dari penghitung harian yang sama untuk poli (RJ-) dan IGD (IGD-), seperti kontrak v0.31.0. */
  const nomorKunjungan = (tanggal: string, awalan = 'RJ'): string => {
    const padat = tanggal.replace(/-/g, '')
    const urut = (urutHarian.get(padat) ?? 0) + 1
    urutHarian.set(padat, urut)
    return `${awalan}-${padat}-${String(urut).padStart(4, '0')}`
  }
  const ENAM_JAM = 6 * 60 * 60 * 1000
  const aktif = (k: KunjunganT) => k.status === 'terdaftar' || k.status === 'dilayani'
  const lebih6Jam = (k: KunjunganT) => k.jenis === 'igd' && aktif(k) && k.tiba_pada !== null && sekarang().getTime() - new Date(k.tiba_pada).getTime() > ENAM_JAM

  const bentuk = (k: KunjunganT): Kunjungan => ({
    id: k.id, nomor: k.nomor, pasien: k.pasien, episode: k.episode, jenis_kunjungan: k.jenis, poliklinik: k.poli,
    dokter: k.dokter, tanggal: k.tanggal, tiba_pada: k.tiba_pada, lebih_6_jam: lebih6Jam(k), cara_bayar: k.cara_bayar, penjamin: k.penjamin,
    status: k.status, keluhan: k.keluhan, alasan_batal: k.alasan_batal, dibatalkan_oleh: k.dibatalkan_oleh, dibatalkan_pada: k.dibatalkan_pada,
    didaftarkan_oleh: k.didaftarkan_oleh, versi: String(k.v), dibuat_pada: k.dibuat_pada, diubah_pada: iso(),
  })

  /** Penjamin IGD sesuai cara bayar; ResponsTiruan = error. Penjamin tak dikenal = 422 validasi_gagal pada kolomnya. */
  function periksaPenjamin(caraBayar: CaraBayar, penjaminId: string | null): RingkasPenjaminKunjungan | null | ResponsTiruan {
    const tidakDikenal = () => error(422, 'validasi_gagal', 'Penjamin tidak ditemukan.', [{ kolom: 'penjamin_id', pesan: 'Penjamin tidak ditemukan.' }])
    if (caraBayar === 'jkn') {
      if (!penjaminId) return error(422, 'penjamin_wajib', 'Cara bayar JKN wajib menyertakan penjamin BPJS.', [{ kolom: 'penjamin_id' }])
      const p = opsi.penjamin(penjaminId)
      if (!p) return tidakDikenal()
      if (p.jenis !== 'bpjs') return error(422, 'penjamin_tidak_sesuai', 'Cara bayar JKN membutuhkan penjamin berjenis BPJS.', [{ kolom: 'penjamin_id' }])
      return p
    }
    if (!penjaminId) return null
    return opsi.penjamin(penjaminId) ?? tidakDikenal()
  }
  const responsError = (x: unknown): x is ResponsTiruan => typeof x === 'object' && x !== null && 'status' in x && 'isi' in x

  /** POST /kunjungan-igd (v0.31.0): isian minimal, jam tiba = jam server, episode rawat jalan harian hari tiba. */
  function buatIgd(isi: Record<string, unknown>): ResponsTiruan {
    const pasienId = isi.pasien_id as string | undefined
    const caraBayar = isi.cara_bayar as CaraBayar | undefined
    const penjaminId = (isi.penjamin_id as string | null | undefined) ?? null
    if (typeof isi.keluhan === 'string' && isi.keluhan.length > 1000) {
      return error(422, 'validasi_gagal', 'Keluhan maksimal 1.000 karakter.', [{ kolom: 'keluhan', pesan: 'Keluhan maksimal 1.000 karakter.' }])
    }
    const keluhan = typeof isi.keluhan === 'string' && isi.keluhan.trim() ? isi.keluhan.trim() : null
    if (!pasienId) return error(422, 'validasi_gagal', 'Pilih pasien.', [{ kolom: 'pasien_id' }])
    if (caraBayar !== 'jkn' && caraBayar !== 'non_jkn') return error(422, 'validasi_gagal', 'Pilih cara bayar.', [{ kolom: 'cara_bayar' }])

    const pasien = opsi.pasien(pasienId)
    if (!pasien) return error(404, 'tidak_ditemukan', 'Pasien tidak ditemukan.')
    if (opsi.pasienDigabung?.(pasienId)) return error(409, 'pasien_digabung', 'No. RM ini sudah digabung ke RM lain. Daftarkan kunjungan di RM tujuan.')
    const penjamin = periksaPenjamin(caraBayar, penjaminId)
    if (responsError(penjamin)) return penjamin
    const masihDiIgd = daftar.find((k) => k.jenis === 'igd' && k.pasien.id === pasienId && aktif(k))
    if (masihDiIgd) return error(409, 'kunjungan_igd_aktif', 'Pasien masih tercatat di IGD. Lanjutkan kunjungan IGD yang aktif.', [{ kunjungan_id: masihDiIgd.id }])

    const tanggal = hariIni()
    const k: KunjunganT = {
      id: uid('c000', ++idN), nomor: nomorKunjungan(tanggal, 'IGD'), jenis: 'igd', pasien, episode: episodeHarian(pasienId, tanggal),
      jadwal_praktik_id: null, poli: null, dokter: null, tanggal, tiba_pada: iso(), cara_bayar: caraBayar, penjamin, status: 'terdaftar', keluhan,
      alasan_batal: null, dibatalkan_oleh: null, dibatalkan_pada: null, didaftarkan_oleh: opsi.pelaku(), v: 1, dibuat_pada: iso(),
    }
    daftar.push(k)
    return { status: 201, header: { ...NO_STORE, ETag: `"${k.v}"` }, isi: { data: bentuk(k) } }
  }

  function buat(isi: Record<string, unknown>): ResponsTiruan {
    const pasienId = isi.pasien_id as string | undefined
    const jadwalId = isi.jadwal_praktik_id as string | undefined
    const tanggal = isi.tanggal as string | undefined
    const jenis = (isi.jenis_kunjungan as string | undefined) ?? 'poliklinik'
    const caraBayar = isi.cara_bayar as CaraBayar | undefined
    const penjaminId = (isi.penjamin_id as string | null | undefined) ?? null
    const keluhan = typeof isi.keluhan === 'string' && isi.keluhan.trim() ? isi.keluhan.trim().slice(0, 500) : null

    if (!pasienId || !jadwalId) return error(422, 'validasi_gagal', 'Pilih pasien dan jadwal praktik.', [{ kolom: !pasienId ? 'pasien_id' : 'jadwal_praktik_id' }])
    if (!tanggal || !TANGGAL.test(tanggal)) return error(422, 'validasi_gagal', 'Tanggal kunjungan wajib.', [{ kolom: 'tanggal' }])
    if (caraBayar !== 'jkn' && caraBayar !== 'non_jkn') return error(422, 'validasi_gagal', 'Pilih cara bayar.', [{ kolom: 'cara_bayar' }])
    if (jenis !== 'poliklinik') return error(422, 'jenis_belum_didukung', 'Hanya kunjungan poliklinik yang didukung tahap ini.', [{ kolom: 'jenis_kunjungan' }])

    const pasien = opsi.pasien(pasienId)
    if (!pasien) return error(404, 'tidak_ditemukan', 'Pasien tidak ditemukan.')
    const j = opsi.jadwal.ambil(jadwalId)
    if (!j) return error(404, 'tidak_ditemukan', 'Jadwal praktik tidak ditemukan.')

    // Tanggal harus hari praktik jadwal, dalam masa berlaku, tidak masa lalu, dan dalam rentang ke depan.
    const h = hariIni()
    if (tanggal < h || tanggal > tambahHari(h, RENTANG_DEPAN_HARI) || hariIso(tanggal) !== j.hari || tanggal < j.berlaku_dari || (j.berlaku_sampai !== null && tanggal > j.berlaku_sampai)) {
      return error(422, 'tanggal_di_luar_jadwal', 'Tanggal bukan hari praktik jadwal ini atau di luar rentang yang diizinkan.', [{ kolom: 'tanggal' }])
    }

    const terisi = opsi.jadwal.keterisian(jadwalId, tanggal)
    if (!terisi || !terisi.tersedia) {
      return error(422, 'jadwal_tidak_tersedia', 'Jadwal tertutup pada tanggal itu (cuti dokter, libur RS, atau nonaktif).', [{ alasan: terisi?.alasan_tidak_tersedia ?? 'nonaktif' }])
    }

    let penjamin: RingkasPenjaminKunjungan | null = null
    if (caraBayar === 'jkn') {
      if (!penjaminId) return error(422, 'penjamin_wajib', 'Cara bayar JKN wajib menyertakan penjamin BPJS.', [{ kolom: 'penjamin_id' }])
      penjamin = opsi.penjamin(penjaminId)
      if (!penjamin) return error(404, 'tidak_ditemukan', 'Penjamin tidak ditemukan.')
      if (penjamin.jenis !== 'bpjs') return error(422, 'penjamin_tidak_sesuai', 'Cara bayar JKN membutuhkan penjamin berjenis BPJS.', [{ kolom: 'penjamin_id' }])
    } else if (penjaminId) {
      penjamin = opsi.penjamin(penjaminId)
      if (!penjamin) return error(404, 'tidak_ditemukan', 'Penjamin tidak ditemukan.')
    }

    // Kunjungan ganda: pasien sudah punya kunjungan aktif pada jadwal + tanggal yang sama.
    if (daftar.some((k) => k.pasien.id === pasienId && k.jadwal_praktik_id === jadwalId && k.tanggal === tanggal && k.status !== 'batal')) {
      return error(409, 'kunjungan_ganda', 'Pasien sudah terdaftar pada jadwal dan tanggal ini.')
    }

    const sisa = caraBayar === 'jkn' ? terisi.sisa_jkn : terisi.sisa_non_jkn
    if (sisa <= 0) {
      return error(409, 'kuota_habis', `Kuota ${caraBayar === 'jkn' ? 'JKN' : 'non-JKN'} dokter ini penuh pada tanggal itu.`, opsi.jadwal.slotPoli(j.poli.id, tanggal, jadwalId) as unknown as Record<string, unknown>[])
    }

    opsi.jadwal.konsumsi(jadwalId, tanggal, caraBayar, 1)
    const k: KunjunganT = {
      id: uid('c000', ++idN), nomor: nomorKunjungan(tanggal), jenis: 'poliklinik', pasien, episode: episodeHarian(pasienId, tanggal), jadwal_praktik_id: jadwalId,
      poli: j.poli, dokter: j.dokter, tanggal, tiba_pada: null, cara_bayar: caraBayar, penjamin, status: 'terdaftar', keluhan,
      alasan_batal: null, dibatalkan_oleh: null, dibatalkan_pada: null, didaftarkan_oleh: opsi.pelaku(), v: 1, dibuat_pada: iso(),
    }
    daftar.push(k)
    return { status: 201, header: { ...NO_STORE, ETag: `"${k.v}"` }, isi: { data: bentuk(k) } }
  }

  // ── Triase (v0.32.0) ──
  /** Terbaru dulu (urutan catat terbalik; jam server tiruan tidak mundur). */
  const riwayatTriase = (kunjunganId: string) => triase.filter((t) => t.kunjungan_id === kunjunganId).reverse()
  const bentukTriase = (t: TriaseT, k: KunjunganT, berlakuId: string | undefined): Triase => ({
    id: t.id, kunjungan_id: t.kunjungan_id, kategori: t.kategori,
    instrumen: t.instrumen ? { id: t.instrumen.id, kode: t.instrumen.kode, nama: t.instrumen.nama } : null,
    skala: t.skala === null ? null : { kode: t.skala, label: t.skala_label ?? t.skala },
    keluhan: t.keluhan, tiba_pada: k.tiba_pada, ditriase_pada: t.ditriase_pada, petugas: t.petugas, berlaku: t.id === berlakuId,
  })
  const validasi = (kolom: string, pesan: string) => error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom, pesan }])

  function catatTriase(k: KunjunganT, isi: Record<string, unknown>): ResponsTiruan {
    const kategori = isi.kategori as KategoriTriase
    if (!KATEGORI_TRIASE.includes(kategori)) return validasi('kategori', 'Pilih kategori triase.')
    if (typeof isi.keluhan === 'string' && isi.keluhan.length > 1000) return validasi('keluhan', 'Keluhan maksimal 1.000 karakter.')
    if (k.jenis !== 'igd' || !aktif(k)) {
      return error(409, 'status_tidak_sesuai', 'Triase hanya untuk kunjungan IGD yang masih terdaftar atau dilayani.', [{ jenis_kunjungan: k.jenis, status: k.status }])
    }
    const instrumenId = (isi.instrumen_triase_id as string | null | undefined) ?? null
    const skala = typeof isi.skala === 'string' && isi.skala.trim() ? isi.skala.trim() : null
    let ins: InstrumenTriase | null = null
    if (!instrumenId) {
      if (instrumenAktif().length) return validasi('instrumen_triase_id', 'Pilih instrumen triase.')
      if (skala) return validasi('skala', 'Skala hanya diisi bersama instrumen triase.')
    } else {
      ins = instrumenAktif().find((i) => i.id === instrumenId) ?? null
      if (!ins) return validasi('instrumen_triase_id', 'Instrumen triase tidak ditemukan atau tidak aktif.')
      if (!ins.skala.length && skala) return validasi('skala', 'Instrumen ini tidak memakai skala.')
      if (ins.skala.length && (!skala || !ins.skala.some((s) => s.kode === skala))) return validasi('skala', 'Pilih skala dari instrumen triase ini.')
    }
    const t: TriaseT = {
      id: uid('d000', ++trN), kunjungan_id: k.id, kategori, instrumen: ins ? { ...ins, skala: [...ins.skala] } : null, skala, skala_label: ins?.skala.find((s) => s.kode === skala)?.label ?? null, keluhan: typeof isi.keluhan === 'string' && isi.keluhan.trim() ? isi.keluhan.trim() : null,
      ditriase_pada: iso(), petugas: opsi.pelaku(),
    }
    triase.push(t)
    return { status: 201, header: NO_STORE, isi: { data: bentukTriase(t, k, t.id) } }
  }

  // ── Disposisi (v0.33.0) ──
  const disposisi: Disposisi[] = []
  let dsN = 0
  function catatDisposisi(k: KunjunganT, isi: Record<string, unknown>): ResponsTiruan {
    const tujuan = String(isi.tujuan ?? '')
    if (BUKAN_DISPOSISI.includes(tujuan)) {
      return error(422, 'tujuan_bukan_disposisi', 'Tujuan ini bukan disposisi IGD; perintahnya lewat konsul, order, atau rawat inap.', [{ kolom: 'tujuan', pos_tujuan: tujuan, pesan: 'Pilih disposisi IGD.' }])
    }
    if (!TUJUAN_DISPOSISI.includes(tujuan as TujuanDisposisi)) return validasi('tujuan', 'Pilih tujuan disposisi.')
    const alasan = typeof isi.alasan === 'string' ? isi.alasan.trim() : ''
    if (alasan.length < 5 || alasan.length > 1000) return validasi('alasan', 'Isi alasan, 5 sampai 1.000 karakter.')
    const serah = typeof isi.serah_terima === 'string' && isi.serah_terima.trim() ? isi.serah_terima.trim() : null
    if (serah !== null && (serah.length < 5 || serah.length > 4000)) return validasi('serah_terima', 'Catatan serah terima 5 sampai 4.000 karakter.')
    if (serah === null && MENUNGGU[tujuan as TujuanDisposisi]) return validasi('serah_terima', 'Serah terima wajib untuk rawat inap dan kamar operasi.')
    if (k.jenis !== 'igd' || !aktif(k)) {
      return error(409, 'status_tidak_sesuai', 'Disposisi hanya untuk kunjungan IGD yang masih terdaftar atau dilayani.', [{ jenis_kunjungan: k.jenis, status: k.status }])
    }
    const aturan = opsi.matriks?.berlaku('igd', tujuan) ?? null
    if (!aturan) {
      return error(422, 'perpindahan_di_luar_matriks', 'Tujuan ini tidak ada di matriks perpindahan aktif untuk IGD.', [{ kolom: 'tujuan', pos_asal: 'igd', pos_tujuan: tujuan, pesan: 'Pilih tujuan yang diizinkan matriks perpindahan.' }])
    }
    if (!opsi.boleh(aturan.izin_perintah)) return error(403, 'akses_ditolak', 'Anda tidak punya akses untuk tindakan ini.')
    if (IZIN_KLINIS.includes(aturan.izin_perintah) && !(opsi.sipBerlaku?.() ?? true)) {
      return error(403, 'izin_praktik_tidak_berlaku', 'Tindakan klinis ini butuh SIP/SPK yang berlaku hari ini.')
    }
    const status: StatusDisposisi = MENUNGGU[tujuan as TujuanDisposisi] ?? 'selesai'
    if (status !== 'selesai' && disposisi.some((d) => d.kunjungan_id === k.id && d.status === status)) {
      return error(409, 'perintah_sudah_menunggu', 'Perintah ini sudah tercatat dan masih menunggu. Buka riwayat disposisi.', [{ pos_tujuan: tujuan, status }])
    }
    if (status === 'selesai') {
      k.status = 'selesai'
      k.v += 1
    }
    const d: Disposisi = {
      id: uid('b000', ++dsN), kunjungan_id: k.id, episode: { id: k.episode.id, jenis: k.episode.jenis, status: 'aktif' }, pos_asal: 'igd', tujuan,
      aturan_perpindahan_id: aturan.id, alasan, serah_terima: serah, status, diputuskan_oleh: opsi.pelaku(), diputuskan_pada: iso(),
    }
    disposisi.push(d)
    return { status: 201, header: NO_STORE, isi: { data: d } }
  }

  function batal(k: KunjunganT, isi: Record<string, unknown>): ResponsTiruan {
    if (k.status === 'batal') return error(409, 'sudah_batal', 'Kunjungan sudah dibatalkan.')
    if (k.status !== 'terdaftar') return error(409, 'sudah_dilayani', 'Pelayanan klinis sudah dimulai; kunjungan tidak dapat dibatalkan.')
    const alasan = isi.alasan_batal
    if (typeof alasan !== 'string' || alasan.trim().length < 5) return error(422, 'validasi_gagal', 'Isi alasan pembatalan, minimal 5 karakter.', [{ kolom: 'alasan_batal' }])
    if (k.jadwal_praktik_id) opsi.jadwal.konsumsi(k.jadwal_praktik_id, k.tanggal, k.cara_bayar, -1)
    k.status = 'batal'
    k.alasan_batal = alasan.trim()
    k.dibatalkan_oleh = opsi.pelaku()
    k.dibatalkan_pada = iso()
    k.v += 1
    return { status: 200, header: { ...NO_STORE, ETag: `"${k.v}"` }, isi: { data: bentuk(k) } }
  }

  function tangani(r: PermintaanTiruan): ResponsTiruan | null {
    const bagian = r.jalur.replace(/^\/+|\/+$/g, '').split('/')
    const metode = r.metode.toUpperCase()
    if (bagian[0] === 'kunjungan-igd' && !bagian[1]) {
      if (metode === 'GET') {
        // v0.32.0: izin tetap igd.pendaftaran.lihat; field `triase` hanya untuk pemegang igd.triase.lihat.
        if (!opsi.boleh('igd.pendaftaran.lihat')) return error(403, 'akses_ditolak', 'Anda tidak berhak melihat kunjungan IGD.')
        const bolehTriase = opsi.boleh('igd.triase.lihat')
        const status = r.kueri.get('status') as StatusKunjungan | null
        const hasil = daftar
          .filter((k) => k.jenis === 'igd' && (status ? k.status === status : aktif(k)))
          // Urut jam tiba terlama dulu (keyset tiba_pada, id).
          .sort((a, b) => (a.tiba_pada ?? '').localeCompare(b.tiba_pada ?? '') || a.id.localeCompare(b.id))
        const batas = Number(r.kueri.get('batas') ?? 50)
        const mulai = r.kueri.get('kursor') ? Number(atob(r.kueri.get('kursor')!)) : 0
        const bentukDaftar = (k: KunjunganT): Kunjungan => {
          if (!bolehTriase) return bentuk(k)
          const t = riwayatTriase(k.id)[0]
          return { ...bentuk(k), triase: t ? { kategori: t.kategori, ditriase_pada: t.ditriase_pada } : null }
        }
        return { status: 200, header: NO_STORE, isi: { data: hasil.slice(mulai, mulai + batas).map(bentukDaftar), meta: { kursor_berikutnya: mulai + batas < hasil.length ? btoa(String(mulai + batas)) : null } } }
      }
      if (metode === 'POST') {
        if (!opsi.boleh('igd.pendaftaran.input')) return error(403, 'akses_ditolak', 'Anda tidak berhak mendaftarkan pasien IGD.')
        return buatIgd((r.isi ?? {}) as Record<string, unknown>)
      }
      return null
    }
    if (bagian[0] === 'instrumen-triase' && !bagian[1] && metode === 'GET') {
      if (!opsi.boleh('igd.triase.lihat')) return error(403, 'akses_ditolak', 'Anda tidak berhak melihat instrumen triase.')
      return { status: 200, header: NO_STORE, isi: { data: [...instrumenAktif()].sort((a, b) => a.kode.localeCompare(b.kode)) } }
    }
    if (bagian[0] !== 'kunjungan') return null
    const id = bagian[1]

    if (id && bagian[2] === 'triase' && !bagian[3]) {
      if (metode !== 'GET' && metode !== 'POST') return null
      if (!opsi.boleh(metode === 'GET' ? 'igd.triase.lihat' : 'igd.triase.input')) return error(403, 'akses_ditolak', 'Anda tidak berhak atas triase IGD.')
      const k = daftar.find((x) => x.id === id)
      if (!k) return error(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.')
      if (metode === 'POST') return catatTriase(k, (r.isi ?? {}) as Record<string, unknown>)
      const semua = riwayatTriase(k.id)
      const batas = Number(r.kueri.get('batas') ?? 50)
      const mulai = r.kueri.get('kursor') ? Number(atob(r.kueri.get('kursor')!)) : 0
      return {
        status: 200, header: NO_STORE,
        isi: { data: semua.slice(mulai, mulai + batas).map((t) => bentukTriase(t, k, semua[0]?.id)), meta: { kursor_berikutnya: mulai + batas < semua.length ? btoa(String(mulai + batas)) : null } },
      }
    }

    if (id && bagian[2] === 'disposisi' && !bagian[3]) {
      if (metode !== 'GET' && metode !== 'POST') return null
      if (!opsi.boleh(metode === 'GET' ? 'igd.disposisi.lihat' : 'igd.disposisi.input')) return error(403, 'akses_ditolak', 'Anda tidak punya akses untuk tindakan ini.')
      const k = daftar.find((x) => x.id === id)
      if (!k) return error(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.')
      if (metode === 'POST') return catatDisposisi(k, (r.isi ?? {}) as Record<string, unknown>)
      const semua = disposisi.filter((d) => d.kunjungan_id === k.id).reverse()
      const batas = Number(r.kueri.get('batas') ?? 50)
      const mulai = r.kueri.get('kursor') ? Number(atob(r.kueri.get('kursor')!)) : 0
      return { status: 200, header: NO_STORE, isi: { data: semua.slice(mulai, mulai + batas), meta: { kursor_berikutnya: mulai + batas < semua.length ? btoa(String(mulai + batas)) : null } } }
    }

    if (!id) {
      if (metode === 'GET') {
        if (!opsi.boleh('pendaftaran.pasien.lihat')) return error(403, 'akses_ditolak', 'Anda tidak berhak melihat kunjungan.')
        const tanggal = r.kueri.get('tanggal')
        const poliId = r.kueri.get('poli_id')
        if (!tanggal || !TANGGAL.test(tanggal) || !poliId) return error(422, 'validasi_gagal', 'Tanggal dan poliklinik wajib.', [{ kolom: !poliId ? 'poli_id' : 'tanggal' }])
        const pegawaiId = r.kueri.get('pegawai_id')
        const status = r.kueri.get('status') as StatusKunjungan | null
        let hasil = daftar.filter((k) => k.tanggal === tanggal && k.poli?.id === poliId && (!pegawaiId || k.dokter?.id === pegawaiId))
        hasil = status ? hasil.filter((k) => k.status === status) : hasil.filter((k) => k.status !== 'batal')
        // Urut: terlama didaftarkan dulu (keyset dibuat_pada, id).
        hasil = [...hasil].sort((a, b) => a.dibuat_pada.localeCompare(b.dibuat_pada) || a.id.localeCompare(b.id))
        const batas = Number(r.kueri.get('batas') ?? 50)
        const mulai = r.kueri.get('kursor') ? Number(atob(r.kueri.get('kursor')!)) : 0
        return { status: 200, header: NO_STORE, isi: { data: hasil.slice(mulai, mulai + batas).map(bentuk), meta: { kursor_berikutnya: mulai + batas < hasil.length ? btoa(String(mulai + batas)) : null } } }
      }
      if (metode === 'POST') {
        if (!opsi.boleh('pendaftaran.pasien.input')) return error(403, 'akses_ditolak', 'Anda tidak berhak mendaftarkan kunjungan.')
        return buat((r.isi ?? {}) as Record<string, unknown>)
      }
      return null
    }

    if (bagian[2] === 'batal' && metode === 'POST') {
      if (!opsi.boleh('pendaftaran.pasien.batal')) return error(403, 'akses_ditolak', 'Anda tidak berhak membatalkan kunjungan.')
      const k = daftar.find((x) => x.id === id)
      if (!k) return error(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.')
      return batal(k, (r.isi ?? {}) as Record<string, unknown>)
    }
    return null
  }

  return { tangani }
}

export type ServerKunjungan = ReturnType<typeof buatServerKunjungan>
