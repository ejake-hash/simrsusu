import type { PermintaanTiruan, ResponsTiruan } from '../../akses/tiruan/serverTiruan'
import type { KeterisianJadwal, RingkasPegawai } from '../../jadwal-praktik/tipe'
import type { CaraBayar, Kunjungan, Pelaku, RingkasEpisode, RingkasPasienKunjungan, RingkasPenjaminKunjungan, SlotTerdekat, StatusKunjungan } from '../tipeKunjungan'

/**
 * Server tiruan kontrak v0.20.0 (tiket 2.6): POST /kunjungan (episode rawat jalan harian terbentuk/digabung otomatis
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
  penjamin: (id: string) => RingkasPenjaminKunjungan | null
  pelaku: () => Pelaku
  sekarang?: () => Date
}

interface KunjunganT {
  id: string
  nomor: string
  pasien: RingkasPasienKunjungan
  episode: RingkasEpisode
  jadwal_praktik_id: string
  poli: { id: string; kode: string; nama: string }
  dokter: RingkasPegawai
  tanggal: string
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

  const episodeHarian = (pasienId: string, tanggal: string): RingkasEpisode => {
    const kunci = `${pasienId}|${tanggal}`
    let ep = episode.get(kunci)
    if (!ep) {
      ep = { id: uid('e000', ++epN), jenis: 'rawat_jalan_harian', mulai_tanggal: tanggal }
      episode.set(kunci, ep)
    }
    return ep
  }
  const nomorKunjungan = (tanggal: string): string => {
    const padat = tanggal.replace(/-/g, '')
    const urut = (urutHarian.get(padat) ?? 0) + 1
    urutHarian.set(padat, urut)
    return `RJ-${padat}-${String(urut).padStart(4, '0')}`
  }

  const bentuk = (k: KunjunganT): Kunjungan => ({
    id: k.id, nomor: k.nomor, pasien: k.pasien, episode: k.episode, jenis_kunjungan: 'poliklinik', poliklinik: k.poli,
    dokter: k.dokter, tanggal: k.tanggal, cara_bayar: k.cara_bayar, penjamin: k.penjamin, status: k.status, keluhan: k.keluhan,
    alasan_batal: k.alasan_batal, dibatalkan_oleh: k.dibatalkan_oleh, dibatalkan_pada: k.dibatalkan_pada,
    didaftarkan_oleh: k.didaftarkan_oleh, versi: String(k.v), dibuat_pada: k.dibuat_pada, diubah_pada: iso(),
  })

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
      id: uid('c000', ++idN), nomor: nomorKunjungan(tanggal), pasien, episode: episodeHarian(pasienId, tanggal), jadwal_praktik_id: jadwalId,
      poli: j.poli, dokter: j.dokter, tanggal, cara_bayar: caraBayar, penjamin, status: 'terdaftar', keluhan,
      alasan_batal: null, dibatalkan_oleh: null, dibatalkan_pada: null, didaftarkan_oleh: opsi.pelaku(), v: 1, dibuat_pada: iso(),
    }
    daftar.push(k)
    return { status: 201, header: { ...NO_STORE, ETag: `"${k.v}"` }, isi: { data: bentuk(k) } }
  }

  function batal(k: KunjunganT, isi: Record<string, unknown>): ResponsTiruan {
    if (k.status === 'batal') return error(409, 'sudah_batal', 'Kunjungan sudah dibatalkan.')
    if (k.status !== 'terdaftar') return error(409, 'sudah_dilayani', 'Pelayanan klinis sudah dimulai; kunjungan tidak dapat dibatalkan.')
    const alasan = isi.alasan_batal
    if (typeof alasan !== 'string' || alasan.trim().length < 5) return error(422, 'validasi_gagal', 'Isi alasan pembatalan, minimal 5 karakter.', [{ kolom: 'alasan_batal' }])
    opsi.jadwal.konsumsi(k.jadwal_praktik_id, k.tanggal, k.cara_bayar, -1)
    k.status = 'batal'
    k.alasan_batal = alasan.trim()
    k.dibatalkan_oleh = opsi.pelaku()
    k.dibatalkan_pada = iso()
    k.v += 1
    return { status: 200, header: { ...NO_STORE, ETag: `"${k.v}"` }, isi: { data: bentuk(k) } }
  }

  function tangani(r: PermintaanTiruan): ResponsTiruan | null {
    const bagian = r.jalur.replace(/^\/+|\/+$/g, '').split('/')
    if (bagian[0] !== 'kunjungan') return null
    const metode = r.metode.toUpperCase()
    const id = bagian[1]

    if (!id) {
      if (metode === 'GET') {
        if (!opsi.boleh('pendaftaran.pasien.lihat')) return error(403, 'akses_ditolak', 'Anda tidak berhak melihat kunjungan.')
        const tanggal = r.kueri.get('tanggal')
        const poliId = r.kueri.get('poli_id')
        if (!tanggal || !TANGGAL.test(tanggal) || !poliId) return error(422, 'validasi_gagal', 'Tanggal dan poliklinik wajib.', [{ kolom: !poliId ? 'poli_id' : 'tanggal' }])
        const pegawaiId = r.kueri.get('pegawai_id')
        const status = r.kueri.get('status') as StatusKunjungan | null
        let hasil = daftar.filter((k) => k.tanggal === tanggal && k.poli.id === poliId && (!pegawaiId || k.dokter.id === pegawaiId))
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
