import type { PermintaanTiruan, ResponsTiruan } from '../../akses/tiruan/serverTiruan'
import type { AlasanTidakTersedia, JadwalPengecualian, JadwalPraktik, KeterisianJadwal, RingkasLokasi, RingkasPegawai } from '../tipe'

/**
 * Server tiruan kontrak v0.17.0 (tiket 2.4): /jadwal-praktik dan /jadwal-pengecualian. Keadaan di memori; aturan dari
 * kontrak (SIP aktif, jadwal bertumpuk, cuti/libur menutup slot, kuota di bawah terisi, If-Match). Terisi sintetis
 * (kunjungan baru ada di tiket 2.6). Dev (VITE_API_TIRUAN=true) dan unit test.
 */
const error = (status: number, kode: string, pesan: string, rincian: Record<string, unknown>[] = []): ResponsTiruan => ({ status, isi: { error: { kode, pesan, rincian } } })
const ok = (isi: unknown, status = 200, header?: Record<string, string>): ResponsTiruan => ({ status, isi, header })
const id = (n: number) => `00000000-0000-7000-d000-${n.toString(16).padStart(12, '0')}`
const TANGGAL_RS = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit' })
const TANGGAL = /^\d{4}-\d{2}-\d{2}$/
const JAM = /^([01][0-9]|2[0-3]):[0-5][0-9]$/
const tambahHari = (tgl: string, n: number) => {
  const d = new Date(`${tgl}T00:00:00Z`)
  d.setUTCDate(d.getUTCDate() + n)
  return d.toISOString().slice(0, 10)
}
/** Hari ISO 1–7 dari YYYY-MM-DD. */
const hariIso = (tgl: string) => new Date(`${tgl}T00:00:00Z`).getUTCDay() || 7

type Isi = Record<string, unknown>
interface JadwalT {
  id: string
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
  status: 'aktif' | 'nonaktif'
  diubah_pada: string
  v: number
}
interface PengecualianT {
  id: string
  jenis: 'cuti' | 'libur'
  pegawai_id: string | null
  dari_tanggal: string
  sampai_tanggal: string
  alasan: string
  status: 'aktif' | 'batal'
  alasan_batal: string | null
  diubah_pada: string
  v: number
}

export interface OpsiServerJadwal {
  boleh: (kode: string) => boolean
  pegawai: (id: string) => RingkasPegawai | null
  sipAktif: (pegawaiId: string, tanggal: string) => boolean
  poli: (id: string) => { id: string; kode: string; nama: string; ruang: RingkasLokasi | null } | null
  ruang: (id: string) => RingkasLokasi | null
  contoh: { dokter: string; poli: string }
  sekarang?: () => Date
}

export function buatServerJadwal(opsi: OpsiServerJadwal) {
  const sekarang = opsi.sekarang ?? (() => new Date())
  const iso = () => sekarang().toISOString()
  const hariIni = () => TANGGAL_RS.format(sekarang())
  let nomor = 100
  const jadwal = new Map<string, JadwalT>()
  const pengecualian = new Map<string, PengecualianT>()
  const tolakIzin = () => error(403, 'tidak_diizinkan', 'Anda tidak memiliki izin untuk tindakan ini.')
  const salahKolom = (kode: string, pesan: string, kolom: string) => error(422, kode, pesan, [{ kolom, pesan }])

  const ringkasPengecualian = (x: PengecualianT): JadwalPengecualian => ({
    id: x.id, jenis: x.jenis, pegawai: x.pegawai_id ? opsi.pegawai(x.pegawai_id) : null, dari_tanggal: x.dari_tanggal, sampai_tanggal: x.sampai_tanggal,
    alasan: x.alasan, status: x.status, alasan_batal: x.alasan_batal, versi: String(x.v), diubah_pada: x.diubah_pada,
  })
  const menutup = (j: JadwalT) => (x: PengecualianT) => x.status === 'aktif' && (x.pegawai_id === null || x.pegawai_id === j.pegawai_id)
  /** Kuota yang terpakai kunjungan nyata (tiket 2.6) di atas terisi sintetis. Kunci `${jadwalId}|${tanggal}`. */
  const terpakai = new Map<string, { jkn: number; non: number }>()
  const kunciPakai = (jadwalId: string, tanggal: string) => `${jadwalId}|${tanggal}`
  /** Terisi sintetis tetap per jadwal dan tanggal + kunjungan nyata; dibatasi kuota agar kolom sisa bermakna. */
  const terisi = (j: JadwalT, tanggal: string) => {
    const benih = (j.hari * 7 + Number(tanggal.slice(8)) * 3 + parseInt(j.id.slice(-2), 16)) % 10
    const p = terpakai.get(kunciPakai(j.id, tanggal)) ?? { jkn: 0, non: 0 }
    return {
      jkn: Math.min(j.kuota_jkn, Math.round((j.kuota_jkn * benih) / 10) + p.jkn),
      non: Math.min(j.kuota_non_jkn, Math.round((j.kuota_non_jkn * ((benih + 4) % 10)) / 10) + p.non),
    }
  }
  function keterisian(j: JadwalT, tanggal: string): KeterisianJadwal {
    const tutup = [...pengecualian.values()].find((x) => menutup(j)(x) && x.dari_tanggal <= tanggal && tanggal <= x.sampai_tanggal)
    const alasan: AlasanTidakTersedia | null = j.status === 'nonaktif' ? 'nonaktif' : (tutup?.jenis ?? null)
    const t = terisi(j, tanggal)
    return {
      tanggal, kuota_jkn: j.kuota_jkn, terisi_jkn: t.jkn, kuota_non_jkn: j.kuota_non_jkn, terisi_non_jkn: t.non,
      sisa_jkn: alasan ? 0 : j.kuota_jkn - t.jkn, sisa_non_jkn: alasan ? 0 : j.kuota_non_jkn - t.non, tersedia: alasan === null, alasan_tidak_tersedia: alasan,
    }
  }
  function bentuk(j: JadwalT, tanggal: string | null): JadwalPraktik {
    const hariIniRs = hariIni()
    return {
      id: j.id, pegawai: opsi.pegawai(j.pegawai_id)!, poliklinik: (({ id: i, kode, nama }) => ({ id: i, kode, nama }))(opsi.poli(j.poli_id)!),
      ruang: j.ruang_id ? opsi.ruang(j.ruang_id) : (opsi.poli(j.poli_id)?.ruang ?? null), hari: j.hari, jam_mulai: j.jam_mulai, jam_selesai: j.jam_selesai,
      kuota_jkn: j.kuota_jkn, kuota_non_jkn: j.kuota_non_jkn, berlaku_dari: j.berlaku_dari, berlaku_sampai: j.berlaku_sampai, status: j.status,
      pada_tanggal: tanggal ? keterisian(j, tanggal) : null,
      pengecualian: opsi.boleh('master_data.jadwal_praktik.lihat') ? [...pengecualian.values()].filter((x) => menutup(j)(x) && x.sampai_tanggal >= hariIniRs).map(ringkasPengecualian) : [],
      versi: String(j.v), diubah_pada: j.diubah_pada,
    }
  }
  function periksa(isi: Isi, j: Pick<JadwalT, 'pegawai_id' | 'hari' | 'jam_mulai' | 'jam_selesai' | 'berlaku_dari'> & { id?: string }): ResponsTiruan | null {
    if (!JAM.test(j.jam_mulai)) return salahKolom('validasi_gagal', 'Jam mulai harus HH:MM.', 'jam_mulai')
    if (!JAM.test(j.jam_selesai) || j.jam_selesai <= j.jam_mulai) return salahKolom('validasi_gagal', 'Jam selesai harus sesudah jam mulai.', 'jam_selesai')
    if (!Number.isInteger(j.hari) || j.hari < 1 || j.hari > 7) return salahKolom('validasi_gagal', 'Hari 1–7.', 'hari')
    for (const k of ['kuota_jkn', 'kuota_non_jkn']) {
      const n = isi[k]
      if (n !== undefined && (!Number.isInteger(n) || (n as number) < 0 || (n as number) > 1000)) return salahKolom('validasi_gagal', 'Kuota 0–1000.', k)
    }
    if (isi.ruang_id && !opsi.ruang(isi.ruang_id as string)) return salahKolom('bukan_ruang', 'Ruang tidak ditemukan atau tidak aktif.', 'ruang_id')
    if (!opsi.sipAktif(j.pegawai_id, j.berlaku_dari)) return salahKolom('sip_tidak_aktif', 'Dokter tidak memiliki SIP aktif pada tanggal mulai berlaku.', 'pegawai_id')
    const bentrok = [...jadwal.values()].find((x) => x.id !== j.id && x.status === 'aktif' && x.pegawai_id === j.pegawai_id && x.hari === j.hari && x.jam_mulai < j.jam_selesai && j.jam_mulai < x.jam_selesai)
    if (bentrok) return error(422, 'jadwal_bertumpuk', 'Jam bertumpuk dengan jadwal aktif dokter yang sama di hari itu.', [{ kolom: 'jam_mulai', pesan: 'Jam bertumpuk dengan jadwal lain.', jadwal_id: bentrok.id }])
    return null
  }

  const tambah = (p: Omit<JadwalT, 'id' | 'status' | 'diubah_pada' | 'v' | 'ruang_id' | 'berlaku_sampai'> & Partial<Pick<JadwalT, 'ruang_id' | 'berlaku_sampai'>>) => {
    const j: JadwalT = { id: id(++nomor), status: 'aktif', diubah_pada: '2026-10-01T04:00:00.000Z', v: 1, ruang_id: null, berlaku_sampai: null, ...p }
    jadwal.set(j.id, j)
    return j
  }
  const t0 = TANGGAL_RS.format(sekarang())
  tambah({ pegawai_id: opsi.contoh.dokter, poli_id: opsi.contoh.poli, hari: 1, jam_mulai: '08:00', jam_selesai: '12:00', kuota_jkn: 20, kuota_non_jkn: 10, berlaku_dari: '2026-01-01' })
  tambah({ pegawai_id: opsi.contoh.dokter, poli_id: opsi.contoh.poli, hari: 3, jam_mulai: '13:00', jam_selesai: '16:00', kuota_jkn: 12, kuota_non_jkn: 6, berlaku_dari: '2026-01-01' })
  tambah({ pegawai_id: opsi.contoh.dokter, poli_id: opsi.contoh.poli, hari: 5, jam_mulai: '08:00', jam_selesai: '11:00', kuota_jkn: 15, kuota_non_jkn: 5, berlaku_dari: '2026-01-01', berlaku_sampai: tambahHari(t0, -30) })

  function tangani(r: PermintaanTiruan): ResponsTiruan | null {
    const bagian = r.jalur.replace(/\/+$/, '').split('/').filter(Boolean)
    const m = r.metode.toUpperCase()
    const isi = (r.isi ?? {}) as Isi
    if (bagian[0] === 'jadwal-praktik') {
      const a1 = bagian[1]
      if (!a1 && m === 'GET') {
        if (!opsi.boleh('master_data.jadwal_praktik.lihat')) return tolakIzin()
        const tanggal = r.kueri.get('tanggal')
        if (tanggal && (!TANGGAL.test(tanggal) || tanggal < tambahHari(hariIni(), -366))) return salahKolom('validasi_gagal', 'Tanggal tidak valid.', 'tanggal')
        const poliId = r.kueri.get('poli_id')
        const pegawaiId = r.kueri.get('pegawai_id')
        const status = r.kueri.get('status') ?? 'aktif'
        const batas = Number(r.kueri.get('batas') ?? 50)
        let daftar = [...jadwal.values()].filter((j) => j.status === status && (!poliId || j.poli_id === poliId) && (!pegawaiId || j.pegawai_id === pegawaiId))
        if (tanggal) daftar = daftar.filter((j) => j.hari === hariIso(tanggal) && j.berlaku_dari <= tanggal && (j.berlaku_sampai === null || tanggal <= j.berlaku_sampai))
        daftar.sort((a, b) => opsi.poli(a.poli_id)!.nama.localeCompare(opsi.poli(b.poli_id)!.nama) || a.hari - b.hari || a.jam_mulai.localeCompare(b.jam_mulai))
        const mulai = r.kueri.get('kursor') ? Number(atob(r.kueri.get('kursor')!)) : 0
        return ok({ data: daftar.slice(mulai, mulai + batas).map((j) => bentuk(j, tanggal)), meta: { kursor_berikutnya: mulai + batas < daftar.length ? btoa(String(mulai + batas)) : null } })
      }
      if (!a1 && m === 'POST') {
        if (!opsi.boleh('master_data.jadwal_praktik.input')) return tolakIzin()
        if (!opsi.pegawai(String(isi.pegawai_id))) return error(404, 'tidak_ditemukan', 'Dokter tidak ditemukan.')
        if (!opsi.poli(String(isi.poli_id))) return error(404, 'tidak_ditemukan', 'Poliklinik tidak ditemukan.')
        if (typeof isi.dasar_perubahan !== 'string' || isi.dasar_perubahan.trim().length < 3) return salahKolom('validasi_gagal', 'Isi dasar perubahan.', 'dasar_perubahan')
        if (typeof isi.berlaku_dari !== 'string' || !TANGGAL.test(isi.berlaku_dari)) return salahKolom('validasi_gagal', 'Tanggal mulai berlaku wajib.', 'berlaku_dari')
        const calon = { pegawai_id: isi.pegawai_id as string, hari: isi.hari as number, jam_mulai: String(isi.jam_mulai), jam_selesai: String(isi.jam_selesai), berlaku_dari: isi.berlaku_dari }
        const salah = periksa(isi, calon)
        if (salah) return salah
        const j = tambah({ ...calon, poli_id: isi.poli_id as string, ruang_id: (isi.ruang_id as string | null) ?? null, kuota_jkn: Number(isi.kuota_jkn ?? 0), kuota_non_jkn: Number(isi.kuota_non_jkn ?? 0), berlaku_sampai: (isi.berlaku_sampai as string | null) ?? null })
        j.diubah_pada = iso()
        return ok({ data: bentuk(j, null) }, 201, { ETag: `"${j.v}"` })
      }
      const j = a1 ? jadwal.get(a1) : undefined
      if (a1 && m === 'PATCH') {
        if (!opsi.boleh('master_data.jadwal_praktik.perbaiki')) return tolakIzin()
        if (!j) return error(404, 'tidak_ditemukan', 'Jadwal tidak ditemukan.')
        const ifMatch = r.header['if-match']
        if (!ifMatch) return error(428, 'if_match_wajib', 'Muat ulang data sebelum mengubah.')
        if (ifMatch !== `"${j.v}"`) return error(412, 'versi_berubah', 'Jadwal diubah orang lain sejak dibuka.')
        const calon = { ...j, ...Object.fromEntries(Object.entries(isi).filter(([k]) => ['hari', 'jam_mulai', 'jam_selesai', 'berlaku_dari', 'kuota_jkn', 'kuota_non_jkn', 'berlaku_sampai', 'ruang_id', 'status'].includes(k))) } as JadwalT
        const salah = calon.status === 'aktif' ? periksa(isi, calon) : null
        if (salah) return salah
        const mendatang = [...Array(60).keys()].map((n) => tambahHari(hariIni(), n)).filter((t) => hariIso(t) === calon.hari)
        const maks = mendatang.reduce((a, t) => ({ jkn: Math.max(a.jkn, terisi(j, t).jkn), non: Math.max(a.non, terisi(j, t).non) }), { jkn: 0, non: 0 })
        if (calon.status === 'aktif' && (isi.kuota_jkn !== undefined || isi.kuota_non_jkn !== undefined) && (maks.jkn > calon.kuota_jkn || maks.non > calon.kuota_non_jkn)) {
          return salahKolom('kuota_di_bawah_terisi', `Kuota di bawah jumlah yang sudah terisi (JKN ${maks.jkn}, non-JKN ${maks.non}).`, maks.jkn > calon.kuota_jkn ? 'kuota_jkn' : 'kuota_non_jkn')
        }
        Object.assign(j, calon, { v: j.v + 1, diubah_pada: iso() })
        return ok({ data: bentuk(j, null) }, 200, { ETag: `"${j.v}"` })
      }
    }
    if (bagian[0] === 'jadwal-pengecualian') {
      const a1 = bagian[1]
      if (!a1 && m === 'POST') {
        if (!opsi.boleh('master_data.jadwal_praktik.input')) return tolakIzin()
        const jenis = isi.jenis as 'cuti' | 'libur'
        const dari = String(isi.dari_tanggal)
        const sampai = String(isi.sampai_tanggal)
        const pegawaiId = (isi.pegawai_id as string | null) ?? null
        if (jenis !== 'cuti' && jenis !== 'libur') return salahKolom('validasi_gagal', 'Pilih jenis.', 'jenis')
        if (!TANGGAL.test(dari)) return salahKolom('validasi_gagal', 'Tanggal mulai wajib.', 'dari_tanggal')
        if (!TANGGAL.test(sampai) || sampai < dari) return salahKolom('validasi_gagal', 'Tanggal selesai tidak boleh sebelum tanggal mulai.', 'sampai_tanggal')
        if (jenis === 'cuti' && !pegawaiId) return salahKolom('validasi_gagal', 'Cuti wajib menyebut dokter.', 'pegawai_id')
        if (jenis === 'libur' && pegawaiId) return salahKolom('validasi_gagal', 'Libur RS berlaku untuk semua dokter.', 'pegawai_id')
        if (pegawaiId && !opsi.pegawai(pegawaiId)) return error(404, 'tidak_ditemukan', 'Dokter tidak ditemukan.')
        if (typeof isi.alasan !== 'string' || isi.alasan.trim().length < 3) return salahKolom('validasi_gagal', 'Isi alasan.', 'alasan')
        const x: PengecualianT = { id: id(++nomor), jenis, pegawai_id: pegawaiId, dari_tanggal: dari, sampai_tanggal: sampai, alasan: isi.alasan.trim(), status: 'aktif', alasan_batal: null, diubah_pada: iso(), v: 1 }
        pengecualian.set(x.id, x)
        return ok({ data: ringkasPengecualian(x) }, 201, { ETag: `"${x.v}"` })
      }
      const x = a1 ? pengecualian.get(a1) : undefined
      if (a1 && m === 'PATCH') {
        if (!opsi.boleh('master_data.jadwal_praktik.perbaiki')) return tolakIzin()
        if (!x) return error(404, 'tidak_ditemukan', 'Pengecualian tidak ditemukan.')
        const ifMatch = r.header['if-match']
        if (!ifMatch) return error(428, 'if_match_wajib', 'Muat ulang data sebelum mengubah.')
        if (ifMatch !== `"${x.v}"`) return error(412, 'versi_berubah', 'Pengecualian diubah orang lain sejak dibuka.')
        if (x.sampai_tanggal < hariIni()) return salahKolom('validasi_gagal', 'Pengecualian yang sudah lewat tidak dapat diubah.', 'sampai_tanggal')
        if (isi.status === 'batal') {
          if (typeof isi.alasan_batal !== 'string' || isi.alasan_batal.trim().length < 3) return salahKolom('validasi_gagal', 'Isi alasan pembatalan.', 'alasan_batal')
          x.status = 'batal'
          x.alasan_batal = isi.alasan_batal.trim()
        }
        if (typeof isi.dari_tanggal === 'string') x.dari_tanggal = isi.dari_tanggal
        if (typeof isi.sampai_tanggal === 'string') x.sampai_tanggal = isi.sampai_tanggal
        if (x.sampai_tanggal < x.dari_tanggal) return salahKolom('validasi_gagal', 'Tanggal selesai tidak boleh sebelum tanggal mulai.', 'sampai_tanggal')
        if (typeof isi.alasan === 'string') x.alasan = isi.alasan
        x.v += 1
        x.diubah_pada = iso()
        return ok({ data: ringkasPengecualian(x) }, 200, { ETag: `"${x.v}"` })
      }
    }
    return null
  }

  // ── Dipakai server tiruan kunjungan (tiket 2.6): satu jadwal = poli + dokter + kuota pada tanggal ──
  /** Inti jadwal (poli + dokter + kuota) untuk membentuk kunjungan; null bila jadwal/poli/dokter tidak ada. */
  const ambil = (jadwalId: string) => {
    const j = jadwal.get(jadwalId)
    if (!j) return null
    const poli = opsi.poli(j.poli_id)
    const dokter = opsi.pegawai(j.pegawai_id)
    if (!poli || !dokter) return null
    return {
      id: j.id, poli: { id: poli.id, kode: poli.kode, nama: poli.nama }, dokter, hari: j.hari, jam_mulai: j.jam_mulai,
      jam_selesai: j.jam_selesai, berlaku_dari: j.berlaku_dari, berlaku_sampai: j.berlaku_sampai, status: j.status,
      kuota_jkn: j.kuota_jkn, kuota_non_jkn: j.kuota_non_jkn,
    }
  }
  const keterisianJadwal = (jadwalId: string, tanggal: string): KeterisianJadwal | null => {
    const j = jadwal.get(jadwalId)
    return j ? keterisian(j, tanggal) : null
  }
  /** Tambah/kurangi kuota terpakai kunjungan (delta +1 saat daftar, −1 saat batal). */
  const konsumsi = (jadwalId: string, tanggal: string, cara: 'jkn' | 'non_jkn', delta: number) => {
    const k = kunciPakai(jadwalId, tanggal)
    const cur = terpakai.get(k) ?? { jkn: 0, non: 0 }
    if (cara === 'jkn') cur.jkn = Math.max(0, cur.jkn + delta)
    else cur.non = Math.max(0, cur.non + delta)
    terpakai.set(k, cur)
  }
  /** Slot dokter lain di poli yang sama pada tanggal itu yang masih punya sisa (rincian 409 kuota_habis). */
  const slotPoli = (poliId: string, tanggal: string, kecualiId: string) =>
    [...jadwal.values()]
      .filter((j) => j.poli_id === poliId && j.id !== kecualiId && j.status === 'aktif' && j.hari === hariIso(tanggal) && j.berlaku_dari <= tanggal && (j.berlaku_sampai === null || tanggal <= j.berlaku_sampai))
      .map((j) => {
        const k = keterisian(j, tanggal)
        return { jadwal_praktik_id: j.id, pegawai: opsi.pegawai(j.pegawai_id)!, sisa_jkn: k.sisa_jkn, sisa_non_jkn: k.sisa_non_jkn, jam_mulai: j.jam_mulai }
      })
      .filter((s) => s.sisa_jkn > 0 || s.sisa_non_jkn > 0)
      .sort((a, b) => a.jam_mulai.localeCompare(b.jam_mulai))

  return { tangani, ambil, keterisian: keterisianJadwal, konsumsi, slotPoli }
}
