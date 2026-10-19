import type { PermintaanTiruan, ResponsTiruan } from '../../akses/tiruan/serverTiruan'
import type { IzinPraktik, JenisIzinPraktik, KewenanganKlinis, PegawaiRingkas, PegawaiRinci, Profesi, StatusIzinPraktik, StatusPegawai } from '../tipe'

/**
 * Server tiruan kontrak v0.6.0 (tiket 1.8d): /pegawai, izin praktik, RKK, akun dari pegawai. Keadaan di memori; aturan
 * dari kontrak (izin, If-Match, NIK hanya tersamar kecuali tampilkan_nik + izin identitas, superadmin_aktif, keluar →
 * akun nonaktif). Dev (VITE_API_TIRUAN=true) dan unit test; tidak masuk build produksi. Semua nama, NIP, NIK, dan nomor
 * izin sintetis.
 */
const error = (status: number, kode: string, pesan: string, rincian: Record<string, unknown>[] = []): ResponsTiruan => ({ status, isi: { error: { kode, pesan, rincian } } })
const ok = (isi: unknown, status = 200, header?: Record<string, string>): ResponsTiruan => ({ status, isi, header })
const id = (n: number) => `00000000-0000-7000-a000-${n.toString(16).padStart(12, '0')}`
const salahKolom = (status: number, kode: string, pesan: string, kolom: string, pesanKolom = pesan) => error(status, kode, pesan, [{ kolom, pesan: pesanKolom }])

type Isi = Record<string, unknown>
interface PegawaiT {
  id: string
  nip: string | null
  nik: string | null
  nama: string
  jenis_kepegawaian: string | null
  profesi: Profesi | null
  unit_id: string | null
  jabatan: string | null
  tmt: string | null
  tanggal_keluar: string | null
  alasan_keluar: string | null
  dibuat_pada: string
  diubah_pada: string
  v: number
}
type IzinT = Omit<IzinPraktik, 'status'> & { pegawai_id: string }
type RkkT = Omit<KewenanganKlinis, 'status'> & { pegawai_id: string }

export interface OpsiServerSdm {
  /** Izin efektif akun yang masuk (server tiruan akses). */
  boleh: (kode: string) => boolean
  unit: (id: string) => { id: string; kode: string; nama: string; aktif: boolean } | null
  unitContoh: { irna: string; irja: string; bpd: string }
  akun: {
    ringkas: (pegawaiId: string) => { id: string; nama_pengguna: string; status: 'aktif' | 'nonaktif' } | null
    buat: (pegawaiId: string, namaPengguna: string, nama: string) => unknown
    superadmin: (pegawaiId: string) => boolean
    nonaktifkan: (pegawaiId: string) => void
    tautkan: (namaPengguna: string, pegawaiId: string) => void
  }
  /** Pelaku pencatatan (akun yang masuk). */
  pelaku: () => { id: string; nama: string }
  sekarang?: () => Date
  /** Ambang "akan habis" (hari); kontrak: dari konfigurasi, angka awal 60 (perlu verifikasi). */
  ambangAkanHabis?: number
}

const TANGGAL_RS = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit' })
const tambahHari = (tgl: string, n: number) => {
  const d = new Date(`${tgl}T00:00:00Z`)
  d.setUTCDate(d.getUTCDate() + n)
  return d.toISOString().slice(0, 10)
}
const TANGGAL = /^\d{4}-\d{2}-\d{2}$/
const PROFESI: Profesi[] = ['dokter', 'dokter_gigi', 'perawat', 'bidan', 'apoteker', 'tenaga_kesehatan_lain', 'non_kesehatan']
const JENIS_IZIN: JenisIzinPraktik[] = ['str', 'sip', 'sik', 'spk']
const MIME = ['application/pdf', 'image/jpeg', 'image/png']
const BURUK: Record<Exclude<StatusIzinPraktik, 'batal'>, number> = { berlaku: 0, belum_berlaku: 1, akan_habis: 2, habis: 3 }

export function buatServerSdm(opsi: OpsiServerSdm) {
  const sekarang = opsi.sekarang ?? (() => new Date())
  const iso = () => sekarang().toISOString()
  const hariIni = () => TANGGAL_RS.format(sekarang())
  const ambang = opsi.ambangAkanHabis ?? 60
  let nomor = 100
  const dokumen = new Map<string, Blob>()

  const pegawai = new Map<string, PegawaiT>()
  const izin = new Map<string, IzinT>()
  const rkk = new Map<string, RkkT>()

  const statusPegawai = (p: PegawaiT): StatusPegawai => (p.tanggal_keluar === null ? 'aktif' : p.tanggal_keluar > hariIni() ? 'akan_keluar' : 'keluar')
  const statusIzin = (x: IzinT): StatusIzinPraktik => {
    if (x.dibatalkan_pada) return 'batal'
    const t = hariIni()
    if (x.terbit > t) return 'belum_berlaku'
    if (x.berlaku_sampai === null) return 'berlaku'
    if (x.berlaku_sampai < t) return 'habis'
    return x.berlaku_sampai <= tambahHari(t, ambang) ? 'akan_habis' : 'berlaku'
  }
  const statusRkk = (x: RkkT): KewenanganKlinis['status'] => {
    if (x.dibatalkan_pada) return 'batal'
    const t = hariIni()
    return x.berlaku_dari > t ? 'belum_berlaku' : x.berlaku_sampai < t ? 'habis' : 'berlaku'
  }
  /** Izin terbaru per jenis (yang tidak dibatalkan), lalu keadaan terburuk antarjenis (perbaikan #50). */
  function statusIzinPegawai(pegawaiId: string): PegawaiRingkas['izin_praktik_status'] {
    const terbaru = new Map<JenisIzinPraktik, IzinT>()
    for (const x of izin.values()) {
      if (x.pegawai_id !== pegawaiId || x.dibatalkan_pada) continue
      const ada = terbaru.get(x.jenis)
      if (!ada || x.terbit > ada.terbit) terbaru.set(x.jenis, x)
    }
    let hasil: PegawaiRingkas['izin_praktik_status'] = null
    for (const x of terbaru.values()) {
      const s = statusIzin(x) as Exclude<StatusIzinPraktik, 'batal'>
      if (hasil === null || BURUK[s] > BURUK[hasil]) hasil = s
    }
    return hasil
  }
  const samaran = (nik: string | null) => (nik ? `${nik.slice(0, 4)} •••• •••• ${nik.slice(12)}` : null)
  const unitRingkas = (uid: string | null) => {
    const u = uid ? opsi.unit(uid) : null
    return u ? { id: u.id, kode: u.kode, nama: u.nama } : null
  }
  function ringkas(p: PegawaiT): PegawaiRingkas {
    const akun = opsi.akun.ringkas(p.id)
    return {
      id: p.id, nip: p.nip, nama: p.nama, nik_samaran: samaran(p.nik), profesi: p.profesi, jabatan: p.jabatan, unit: unitRingkas(p.unit_id),
      status: statusPegawai(p), punya_akun: akun !== null, akun: opsi.boleh('pengaturan.akun.lihat') ? akun : null, izin_praktik_status: statusIzinPegawai(p.id),
    }
  }
  function rinci(p: PegawaiT, tampilkanNik = false): PegawaiRinci {
    return {
      ...ringkas(p),
      ...(tampilkanNik && p.nik ? { nik: p.nik } : {}),
      nik_dapat_dilihat: opsi.boleh('sdm.pegawai.identitas.lihat'),
      jenis_kepegawaian: p.jenis_kepegawaian, tmt: p.tmt, tanggal_keluar: p.tanggal_keluar, alasan_keluar: p.alasan_keluar,
      ihs_practitioner_id: null, versi: String(p.v), diubah_pada: p.diubah_pada,
    }
  }
  const denganEtag = (p: PegawaiT, status = 200, tampilkanNik = false) => ok({ data: rinci(p, tampilkanNik) }, status, { ETag: `"${p.v}"`, 'Cache-Control': 'no-store' })
  const bentukIzin = (x: IzinT): IzinPraktik => {
    const { pegawai_id: _, ...isi } = x
    return { ...isi, status: statusIzin(x) }
  }
  const bentukRkk = (x: RkkT): KewenanganKlinis => {
    const { pegawai_id: _, ...isi } = x
    return { ...isi, status: statusRkk(x) }
  }
  function halaman<T>(daftar: T[], kueri: URLSearchParams): ResponsTiruan {
    const batas = Number(kueri.get('batas') ?? 50)
    if (batas > 200) return error(422, 'ukuran_halaman_melebihi_batas', 'Ukuran halaman maksimum 200.')
    const mulai = kueri.get('kursor') ? Number(atob(kueri.get('kursor')!)) : 0
    return ok({ data: daftar.slice(mulai, mulai + batas), meta: { kursor_berikutnya: mulai + batas < daftar.length ? btoa(String(mulai + batas)) : null } })
  }
  const tolakIzin = () => error(403, 'akses_ditolak', 'Anda tidak punya akses untuk tindakan ini.')
  const dasarSalah = (isi: Isi, kolom = 'dasar_perubahan') =>
    typeof isi[kolom] === 'string' && (isi[kolom] as string).trim().length >= 3 ? null : salahKolom(422, 'validasi_gagal', 'Isian belum benar.', kolom, 'Isi dasar perubahan, minimal 3 karakter.')

  // ── Data awal sintetis ──
  const tambahPegawai = (isi: Partial<PegawaiT> & { nama: string }, namaPengguna?: string) => {
    const p: PegawaiT = {
      id: id(++nomor), nip: null, nik: null, jenis_kepegawaian: 'Sintetis', profesi: 'non_kesehatan', unit_id: null, jabatan: null, tmt: '2024-01-02',
      tanggal_keluar: null, alasan_keluar: null, dibuat_pada: `2026-10-01T02:${String(nomor % 60).padStart(2, '0')}:00.000Z`, diubah_pada: '2026-10-01T02:00:00.000Z', v: 1, ...isi,
    }
    pegawai.set(p.id, p)
    if (namaPengguna) opsi.akun.tautkan(namaPengguna, p.id)
    return p
  }
  const sa = tambahPegawai({ nama: 'Contoh Superadmin', nip: 'SINT-0001', jabatan: 'Admin SIMRS' }, 'contoh.superadmin')
  tambahPegawai({ nama: 'Contoh Superadmin Kedua', nip: 'SINT-0002', jabatan: 'Admin SIMRS' }, 'contoh.superadmin.dua')
  const dokter = tambahPegawai({ nama: 'Contoh Dokter', nip: 'SINT-0101', nik: '1271000000000042', profesi: 'dokter', unit_id: opsi.unitContoh.irja, jabatan: 'Dokter spesialis penyakit dalam' }, 'contoh.dokter')
  const perawat = tambahPegawai({ nama: 'Contoh Perawat', nip: 'SINT-0102', nik: '1271000000000043', profesi: 'perawat', unit_id: opsi.unitContoh.bpd, jabatan: 'Perawat pelaksana' }, 'contoh.perawat')
  tambahPegawai({ nama: 'Contoh Admin Data Induk', nip: 'SINT-0103', unit_id: opsi.unitContoh.irna }, 'contoh.data.induk')
  const apoteker = tambahPegawai({ nama: 'Contoh Apoteker', nip: 'SINT-0201', profesi: 'apoteker', jabatan: 'Apoteker depo' })
  const bidan = tambahPegawai({ nama: 'Contoh Bidan Baru', profesi: 'bidan', unit_id: opsi.unitContoh.irna, tmt: null })
  const keluar = tambahPegawai({ nama: 'Contoh Pegawai Keluar', nip: 'SINT-0301', tanggal_keluar: '2026-09-30', alasan_keluar: 'Pindah tugas ke instansi lain (sintetis)' })

  const catatIzin = (p: PegawaiT, jenis: JenisIzinPraktik, nomorIzin: string, terbit: string, sampai: string | null, isi: Partial<IzinT> = {}) => {
    const x: IzinT = {
      id: id(++nomor), pegawai_id: p.id, jenis, nomor: nomorIzin, terbit, berlaku_sampai: sampai, dokumen: null, dicatat_oleh: { id: id(1), nama: 'Contoh Admin SDM' },
      dicatat_pada: '2026-10-01T03:00:00.000Z', alasan_batal: null, dibatalkan_oleh: null, dibatalkan_pada: null, ...isi,
    }
    izin.set(x.id, x)
    return x
  }
  const t0 = hariIni()
  catatIzin(dokter, 'str', 'STR-SINT-0101', '2020-03-01', null)
  catatIzin(dokter, 'sip', 'SIP-SINT-0101', '2024-01-02', tambahHari(t0, 700), { dokumen: { jenis_mime: 'application/pdf', ukuran: 182_000 } })
  catatIzin(perawat, 'str', 'STR-SINT-0102', '2021-05-01', null)
  catatIzin(perawat, 'sip', 'SIP-SINT-0102-LAMA', '2021-06-01', '2026-01-31')
  catatIzin(perawat, 'sip', 'SIP-SINT-0102', '2026-02-01', tambahHari(t0, 400))
  catatIzin(apoteker, 'sip', 'SIP-SINT-0201', '2021-11-01', tambahHari(t0, 30))
  catatIzin(apoteker, 'str', 'STR-SINT-0201-SALAH', '2021-01-01', null, {
    alasan_batal: 'Salah ketik nomor', dibatalkan_oleh: { id: id(1), nama: 'Contoh Admin SDM' }, dibatalkan_pada: '2026-10-02T03:00:00.000Z',
  })
  rkk.set(id(++nomor), {
    id: id(nomor), pegawai_id: dokter.id, kategori: 'Penyakit dalam umum', kode_tindakan: ['89.03', '99.29'], berlaku_dari: '2025-01-01', berlaku_sampai: tambahHari(t0, 365),
    komite: 'Komite Medik', dasar: 'SK-SINT/RKK/001', dicatat_oleh: { id: id(1), nama: 'Contoh Admin SDM' }, dicatat_pada: '2026-10-01T03:00:00.000Z',
    alasan_batal: null, dibatalkan_oleh: null, dibatalkan_pada: null,
  })

  // ── Validasi isian pegawai (POST dan PATCH) ──
  function periksaIsian(isi: Isi, lama: PegawaiT | null): ResponsTiruan | null {
    const ada = (k: string) => k in isi
    if ((!lama || ada('nama')) && (typeof isi.nama !== 'string' || isi.nama.trim().length < 2 || isi.nama.length > 150)) {
      return salahKolom(422, 'validasi_gagal', 'Isian belum benar.', 'nama', 'Nama 2–150 karakter.')
    }
    if (ada('nik') && isi.nik !== null) {
      if (!opsi.boleh('sdm.pegawai.identitas.lihat')) return tolakIzin()
      if (typeof isi.nik !== 'string' || !/^[0-9]{16}$/.test(isi.nik)) return salahKolom(422, 'validasi_gagal', 'Isian belum benar.', 'nik', 'NIK 16 digit angka.')
      if ([...pegawai.values()].some((p) => p.id !== lama?.id && p.nik === isi.nik)) return salahKolom(409, 'nik_sudah_dipakai', 'NIK ini sudah tercatat untuk pegawai lain.', 'nik')
    }
    if (ada('nik') && isi.nik === null && lama && lama.nik !== null && !opsi.boleh('sdm.pegawai.identitas.lihat')) return tolakIzin()
    if (ada('nip') && isi.nip !== null && isi.nip !== '') {
      if (typeof isi.nip !== 'string' || isi.nip.length > 30) return salahKolom(422, 'validasi_gagal', 'Isian belum benar.', 'nip', 'NIP paling banyak 30 karakter.')
      if ([...pegawai.values()].some((p) => p.id !== lama?.id && p.nip === isi.nip)) return salahKolom(409, 'nip_sudah_dipakai', `NIP ${isi.nip} sudah dipakai.`, 'nip')
    }
    if (ada('profesi') && isi.profesi !== null && !PROFESI.includes(isi.profesi as Profesi)) return salahKolom(422, 'validasi_gagal', 'Isian belum benar.', 'profesi', 'Pilih profesi.')
    if (ada('unit_organisasi_id') && isi.unit_organisasi_id !== null && !opsi.unit(String(isi.unit_organisasi_id))?.aktif) {
      return salahKolom(422, 'unit_tidak_valid', 'Unit tidak ada atau nonaktif.', 'unit_organisasi_id')
    }
    if (ada('tmt') && isi.tmt !== null && !TANGGAL.test(String(isi.tmt))) return salahKolom(422, 'validasi_gagal', 'Isian belum benar.', 'tmt', 'Tanggal tidak valid.')
    return dasarSalah(isi)
  }
  const terapkanIsian = (p: PegawaiT, isi: Isi) => {
    for (const k of ['nip', 'nik', 'nama', 'jenis_kepegawaian', 'profesi', 'jabatan', 'tmt'] as const) {
      if (k in isi) (p as unknown as Record<string, unknown>)[k] = typeof isi[k] === 'string' ? (isi[k] as string).trim() || null : (isi[k] ?? null)
    }
    if ('unit_organisasi_id' in isi) p.unit_id = (isi.unit_organisasi_id as string | null) ?? null
  }

  function tangani(r: PermintaanTiruan): ResponsTiruan | null {
    const bagian = r.jalur.replace(/\/+$/, '').split('/').filter(Boolean)
    if (bagian[0] !== 'pegawai') return null
    const [, a1, a2, a3, a4] = bagian
    const m = r.metode.toUpperCase()
    const isi = (r.isi ?? {}) as Isi

    if (!a1) {
      if (m === 'GET') {
        if (!opsi.boleh('sdm.pegawai.lihat')) return tolakIzin()
        const q = r.kueri
        const cari = (q.get('cari') ?? '').trim().toLowerCase()
        const daftar = [...pegawai.values()]
          .filter((p) => !cari || p.nama.toLowerCase().includes(cari) || (p.nip ?? '').toLowerCase().includes(cari))
          .filter((p) => !q.get('status') || statusPegawai(p) === q.get('status'))
          .filter((p) => !q.get('profesi') || p.profesi === q.get('profesi'))
          .filter((p) => !q.get('unit_organisasi_id') || p.unit_id === q.get('unit_organisasi_id'))
          .filter((p) => q.get('punya_akun') !== 'false' || (opsi.akun.ringkas(p.id) === null && statusPegawai(p) !== 'keluar'))
          .filter((p) => q.get('punya_akun') !== 'true' || opsi.akun.ringkas(p.id) !== null)
          .sort((x, y) => y.dibuat_pada.localeCompare(x.dibuat_pada) || y.id.localeCompare(x.id))
          .map(ringkas)
        return halaman(daftar, q)
      }
      if (m === 'POST') {
        if (!opsi.boleh('sdm.pegawai.input')) return tolakIzin()
        // nik: null = tidak diisi (perbaikan #50), bukan permintaan mengisi NIK.
        const isiBersih = { ...isi }
        if (isiBersih.nik === null) delete isiBersih.nik
        const salah = periksaIsian(isiBersih, null)
        if (salah) return salah
        const p = tambahPegawai({ nama: String(isi.nama).trim(), dibuat_pada: iso(), diubah_pada: iso() })
        terapkanIsian(p, isiBersih)
        return denganEtag(p, 201)
      }
      return null
    }

    const p = pegawai.get(a1)
    if (!p) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')

    if (!a2) {
      if (m === 'GET') {
        if (!opsi.boleh('sdm.pegawai.lihat')) return tolakIzin()
        const tampilkan = r.kueri.get('tampilkan_nik') === 'true'
        if (tampilkan && !opsi.boleh('sdm.pegawai.identitas.lihat')) return tolakIzin()
        return denganEtag(p, 200, tampilkan)
      }
      if (m === 'PATCH') {
        if (!opsi.boleh('sdm.pegawai.perbaiki')) return tolakIzin()
        const im = r.header['if-match']
        if (!im) return error(428, 'if_match_wajib', 'Header If-Match wajib.')
        if (im.replace(/^W\//, '').replace(/"/g, '') !== String(p.v)) return error(412, 'versi_berubah', 'Data pegawai ini diubah orang lain sejak dibuka.')
        if (Object.keys(isi).filter((k) => k !== 'dasar_perubahan').length === 0) return error(422, 'validasi_gagal', 'Kirim minimal satu perubahan.')
        const salah = periksaIsian(isi, p)
        if (salah) return salah
        // Pasangan keluar diperiksa terhadap keadaan sesudah digabung.
        const tglKeluar = 'tanggal_keluar' in isi ? (isi.tanggal_keluar as string | null) : p.tanggal_keluar
        const alasanKeluar = 'alasan_keluar' in isi ? (isi.alasan_keluar as string | null) : p.alasan_keluar
        if (tglKeluar !== null && !TANGGAL.test(tglKeluar)) return salahKolom(422, 'validasi_gagal', 'Isian belum benar.', 'tanggal_keluar', 'Tanggal tidak valid.')
        if (tglKeluar !== null && (typeof alasanKeluar !== 'string' || alasanKeluar.trim().length < 5)) {
          return salahKolom(422, 'validasi_gagal', 'Isian belum benar.', 'alasan_keluar', 'Isi alasan keluar, minimal 5 karakter.')
        }
        if ('tanggal_keluar' in isi && tglKeluar !== null && opsi.akun.superadmin(p.id)) {
          return error(409, 'superadmin_aktif', 'Akun pegawai ini memegang peran Superadmin. Akhiri penugasan superadmin lebih dulu lewat persetujuan superadmin lain.')
        }
        terapkanIsian(p, isi)
        p.tanggal_keluar = tglKeluar
        p.alasan_keluar = tglKeluar === null ? null : (alasanKeluar?.trim() ?? null)
        p.v++
        p.diubah_pada = iso()
        if (statusPegawai(p) === 'keluar') opsi.akun.nonaktifkan(p.id)
        return denganEtag(p)
      }
      return null
    }

    // ── Izin praktik ──
    if (a2 === 'izin-praktik') {
      if (!a3) {
        if (m === 'GET') {
          if (!opsi.boleh('sdm.kredensial.lihat')) return tolakIzin()
          const daftar = [...izin.values()].filter((x) => x.pegawai_id === p.id).sort((x, y) => y.dicatat_pada.localeCompare(x.dicatat_pada) || y.id.localeCompare(x.id)).map(bentukIzin)
          return halaman(daftar, r.kueri)
        }
        if (m === 'POST') {
          if (!opsi.boleh('sdm.kredensial.input')) return tolakIzin()
          const f = r.isi instanceof FormData ? r.isi : null
          if (!f) return error(415, 'jenis_isi_tidak_didukung', 'Kirim multipart/form-data.')
          const jenis = String(f.get('jenis') ?? '') as JenisIzinPraktik
          const nomorIzin = String(f.get('nomor') ?? '').trim()
          const terbit = String(f.get('terbit') ?? '')
          const sampai = String(f.get('berlaku_sampai') ?? '') || null
          const berkas = f.get('berkas')
          if (!JENIS_IZIN.includes(jenis)) return salahKolom(422, 'validasi_gagal', 'Isian belum benar.', 'jenis', 'Pilih jenis izin.')
          if (nomorIzin.length < 3 || nomorIzin.length > 100) return salahKolom(422, 'validasi_gagal', 'Isian belum benar.', 'nomor', 'Nomor 3–100 karakter.')
          if (!TANGGAL.test(terbit)) return salahKolom(422, 'validasi_gagal', 'Isian belum benar.', 'terbit', 'Isi tanggal terbit.')
          if (sampai === null && jenis !== 'str') return salahKolom(422, 'tanggal_tidak_valid', 'Isi tanggal berlaku sampai.', 'berlaku_sampai', 'Wajib diisi untuk selain STR.')
          if (sampai !== null && (!TANGGAL.test(sampai) || sampai < terbit)) return salahKolom(422, 'tanggal_tidak_valid', 'Tanggal berlaku sampai tidak boleh sebelum tanggal terbit.', 'berlaku_sampai')
          if ([...izin.values()].some((x) => x.jenis === jenis && x.nomor === nomorIzin && !x.dibatalkan_pada)) return salahKolom(409, 'izin_ganda', 'Izin dengan jenis dan nomor ini masih tercatat.', 'nomor')
          let dok: IzinPraktik['dokumen'] = null
          if (berkas instanceof Blob && berkas.size > 0) {
            if (!MIME.includes(berkas.type)) return salahKolom(422, 'tipe_berkas_tidak_diizinkan', 'Berkas harus PDF, JPEG, atau PNG.', 'berkas')
            if (berkas.size > 5 * 1024 * 1024) return salahKolom(422, 'berkas_terlalu_besar', 'Berkas paling besar 5 MB.', 'berkas')
            dok = { jenis_mime: berkas.type as NonNullable<IzinPraktik['dokumen']>['jenis_mime'], ukuran: berkas.size }
          }
          const x = catatIzin(p, jenis, nomorIzin, terbit, sampai, { dokumen: dok, dicatat_oleh: opsi.pelaku(), dicatat_pada: iso() })
          if (dok && berkas instanceof Blob) dokumen.set(x.id, berkas)
          return ok({ data: bentukIzin(x) }, 201)
        }
        return null
      }
      const x = izin.get(a3)
      if (!x || x.pegawai_id !== p.id) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
      if (m === 'POST' && a4 === 'batal') {
        if (!opsi.boleh('sdm.kredensial.perbaiki')) return tolakIzin()
        if (x.dibatalkan_pada) return error(409, 'sudah_batal', 'Catatan ini sudah dibatalkan.')
        if (typeof isi.alasan_batal !== 'string' || isi.alasan_batal.trim().length < 5) return salahKolom(422, 'validasi_gagal', 'Isian belum benar.', 'alasan_batal', 'Isi alasan, minimal 5 karakter.')
        Object.assign(x, { alasan_batal: isi.alasan_batal.trim(), dibatalkan_oleh: opsi.pelaku(), dibatalkan_pada: iso() })
        return ok({ data: bentukIzin(x) })
      }
      if (m === 'GET' && a4 === 'dokumen') {
        if (!opsi.boleh('sdm.kredensial.lihat')) return tolakIzin()
        if (!x.dokumen) return error(404, 'tidak_ditemukan', 'Izin ini tidak punya dokumen.')
        const eks = x.dokumen.jenis_mime === 'application/pdf' ? 'pdf' : x.dokumen.jenis_mime === 'image/png' ? 'png' : 'jpg'
        return ok(dokumen.get(x.id) ?? new Blob(['%PDF-1.4 sintetis'], { type: x.dokumen.jenis_mime }), 200, {
          'Content-Type': x.dokumen.jenis_mime, 'Content-Disposition': `attachment; filename="${x.jenis}-${x.id}.${eks}"`, 'X-Content-Type-Options': 'nosniff', 'Cache-Control': 'no-store',
        })
      }
      return null
    }

    // ── Kewenangan klinis ──
    if (a2 === 'kewenangan-klinis') {
      if (!a3) {
        if (m === 'GET') {
          if (!opsi.boleh('sdm.kredensial.lihat')) return tolakIzin()
          const daftar = [...rkk.values()].filter((x) => x.pegawai_id === p.id).sort((x, y) => y.dicatat_pada.localeCompare(x.dicatat_pada) || y.id.localeCompare(x.id)).map(bentukRkk)
          return halaman(daftar, r.kueri)
        }
        if (m === 'POST') {
          if (!opsi.boleh('sdm.kredensial.input')) return tolakIzin()
          const teks = (k: string, min: number, max: number) => typeof isi[k] === 'string' && (isi[k] as string).trim().length >= min && (isi[k] as string).length <= max
          if (!teks('kategori', 2, 100)) return salahKolom(422, 'validasi_gagal', 'Isian belum benar.', 'kategori', 'Kategori 2–100 karakter.')
          const kode = Array.isArray(isi.kode_tindakan) ? (isi.kode_tindakan as unknown[]) : []
          if (kode.length < 1 || kode.length > 500 || new Set(kode).size !== kode.length || kode.some((k) => typeof k !== 'string' || !/^[0-9A-Za-z.-]{1,20}$/.test(k))) {
            return salahKolom(422, 'validasi_gagal', 'Isian belum benar.', 'kode_tindakan', 'Kode tindakan 1–20 karakter (huruf, angka, titik, tanda hubung), tanpa duplikat.')
          }
          const dari = String(isi.berlaku_dari ?? '')
          const sampai = String(isi.berlaku_sampai ?? '')
          if (!TANGGAL.test(dari)) return salahKolom(422, 'validasi_gagal', 'Isian belum benar.', 'berlaku_dari', 'Isi tanggal mulai.')
          if (!TANGGAL.test(sampai) || sampai < dari) return salahKolom(422, 'tanggal_tidak_valid', 'Tanggal berakhir tidak boleh sebelum tanggal mulai.', 'berlaku_sampai')
          if (!teks('komite', 2, 150)) return salahKolom(422, 'validasi_gagal', 'Isian belum benar.', 'komite', 'Komite 2–150 karakter.')
          const x: RkkT = {
            id: id(++nomor), pegawai_id: p.id, kategori: String(isi.kategori).trim(), kode_tindakan: kode as string[], berlaku_dari: dari, berlaku_sampai: sampai,
            komite: String(isi.komite).trim(), dasar: typeof isi.dasar === 'string' && isi.dasar.trim() ? isi.dasar.trim() : null,
            dicatat_oleh: opsi.pelaku(), dicatat_pada: iso(), alasan_batal: null, dibatalkan_oleh: null, dibatalkan_pada: null,
          }
          rkk.set(x.id, x)
          return ok({ data: bentukRkk(x) }, 201)
        }
        return null
      }
      const x = rkk.get(a3)
      if (!x || x.pegawai_id !== p.id) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
      if (m === 'POST' && a4 === 'batal') {
        if (!opsi.boleh('sdm.kredensial.perbaiki')) return tolakIzin()
        if (x.dibatalkan_pada) return error(409, 'sudah_batal', 'Catatan ini sudah dibatalkan.')
        if (typeof isi.alasan_batal !== 'string' || isi.alasan_batal.trim().length < 5) return salahKolom(422, 'validasi_gagal', 'Isian belum benar.', 'alasan_batal', 'Isi alasan, minimal 5 karakter.')
        Object.assign(x, { alasan_batal: isi.alasan_batal.trim(), dibatalkan_oleh: opsi.pelaku(), dibatalkan_pada: iso() })
        return ok({ data: bentukRkk(x) })
      }
      return null
    }

    // ── Akun dari pegawai ──
    if (a2 === 'akun' && !a3 && m === 'POST') {
      if (!opsi.boleh('pengaturan.akun.input') || !opsi.boleh('sdm.pegawai.lihat')) return tolakIzin()
      if (opsi.akun.ringkas(p.id)) return error(409, 'pegawai_sudah_punya_akun', 'Pegawai ini sudah punya akun (satu akun untuk satu pegawai).')
      if (statusPegawai(p) === 'keluar') return error(409, 'pegawai_keluar', 'Pegawai ini sudah keluar.')
      if (typeof isi.nama_pengguna !== 'string' || !/^[a-z][a-z0-9._-]{2,49}$/.test(isi.nama_pengguna)) {
        return salahKolom(422, 'validasi_gagal', 'Isian belum benar.', 'nama_pengguna', 'Huruf kecil, angka, titik, garis bawah, atau tanda hubung; 3–50 karakter, diawali huruf.')
      }
      if (isi.email !== undefined && isi.email !== null && (typeof isi.email !== 'string' || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(isi.email))) {
        return salahKolom(422, 'validasi_gagal', 'Isian belum benar.', 'email', 'Alamat email tidak valid.')
      }
      const dasar = dasarSalah(isi, 'dasar_pemberian')
      if (dasar) return dasar
      let akun: unknown
      try {
        akun = opsi.akun.buat(p.id, isi.nama_pengguna, p.nama)
      } catch {
        return salahKolom(409, 'nama_pengguna_dipakai', `Nama pengguna ${isi.nama_pengguna} sudah dipakai.`, 'nama_pengguna')
      }
      const sandi = Array.from(crypto.getRandomValues(new Uint8Array(15)), (b) => 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'[b % 55]).join('').replace(/(.{5})(?!$)/g, '$1-')
      return ok({ data: { akun, sandi_awal: sandi } }, 201, { 'Cache-Control': 'no-store' })
    }
    return null
  }

  const pegawaiKeluar = (pegawaiId: string) => {
    const p = pegawai.get(pegawaiId)
    return p ? statusPegawai(p) === 'keluar' : false
  }
  const ringkasPegawai = (pegawaiId: string) => {
    const p = pegawai.get(pegawaiId)
    return p ? { id: p.id, nama: p.nama, status: statusPegawai(p) } : null
  }
  /** SIP yang tidak dibatalkan dan berlaku pada tanggal itu (dipakai jadwal praktik, kontrak v0.17.0). */
  const sipAktif = (pegawaiId: string, tanggal: string) =>
    [...izin.values()].some((x) => x.pegawai_id === pegawaiId && x.jenis === 'sip' && !x.dibatalkan_pada && x.terbit <= tanggal && (x.berlaku_sampai === null || x.berlaku_sampai >= tanggal))
  return { tangani, pegawaiKeluar, ringkasPegawai, sipAktif, contoh: { superadmin: sa.id, dokter: dokter.id, perawat: perawat.id, apoteker: apoteker.id, bidan: bidan.id, keluar: keluar.id } }
}

export type ServerSdm = ReturnType<typeof buatServerSdm>
