import type { PermintaanTiruan, ResponsTiruan } from '../../akses/tiruan/serverTiruan'
import type { JenisTugas, Notifikasi, PreferensiNotifikasi, RiwayatTugas, TabTugas, Tugas } from '../tipe'

/**
 * Server tiruan kontrak v0.7.0 (tiket 1.7): /saya/notifikasi, /saya/tugas, /tugas/{id}, /saya/preferensi-notifikasi,
 * /jenis-tugas. ETag = sidik isi respons; If-None-Match sama → 304. Keadaan di memori untuk akun yang masuk (dev dan
 * unit test); tidak masuk build produksi. Semua judul dan nama sintetis; tanpa data pasien.
 */
const error = (status: number, kode: string, pesan: string, rincian: Record<string, unknown>[] = []): ResponsTiruan => ({ status, isi: { error: { kode, pesan, rincian } } })
const ok = (isi: unknown, status = 200, header?: Record<string, string>): ResponsTiruan => ({ status, isi, header })
const id = (n: number) => `00000000-0000-7000-b000-${n.toString(16).padStart(12, '0')}`
type Isi = Record<string, unknown>

/** Sidik sederhana (FNV-1a) isi JSON untuk ETag tiruan. */
function sidik(isi: unknown): string {
  let h = 0x811c9dc5
  for (const c of JSON.stringify(isi)) {
    h ^= c.charCodeAt(0)
    h = Math.imul(h, 0x01000193) >>> 0
  }
  return `"${h.toString(16)}"`
}

export interface OpsiServerNotifikasi {
  boleh: (kode: string) => boolean
  unit: (id: string) => { id: string; kode: string; nama: string; aktif: boolean } | null
  sekarang?: () => Date
}

export function buatServerNotifikasi(opsi: OpsiServerNotifikasi) {
  const sekarang = opsi.sekarang ?? (() => new Date())
  const menitLalu = (m: number) => new Date(sekarang().getTime() - m * 60_000).toISOString()
  let nomor = 100

  // ── Jenis tugas bawaan fase 1 (angka awal = data; perlu verifikasi kebijakan RS) ──
  const jenis = new Map<string, JenisTugas & { v: number }>()
  const tambahJenis = (kode: string, nama: string, modul: string, isi: Partial<JenisTugas> = {}) => {
    const j = {
      id: id(++nomor), kode, nama, uraian: null, modul, tenggat_dari: 'dibuat' as const, batas_menit: 2880, unit_teguran: null, unit_eskalasi: null,
      eskalasi: { pengingat_menit: -240, teguran_menit: 0, eskalasi_menit: 1440 }, kritis: false, aktif: true, versi: '1', diubah_pada: '2026-10-01T02:00:00.000Z', v: 1, ...isi,
    }
    jenis.set(j.id, j)
    return j
  }
  tambahJenis('IZIN_PRAKTIK_BERAKHIR', 'Izin praktik akan berakhir', 'sdm', {
    tenggat_dari: 'objek', batas_menit: 43200, eskalasi: { pengingat_menit: -10080, teguran_menit: 0, eskalasi_menit: 1440 },
    uraian: 'Dibuat 30 hari sebelum STR/SIP berakhir; selesai saat izin pengganti dicatat.',
  })
  const jPermintaan = tambahJenis('PERMINTAAN_AKSES', 'Permintaan akses menunggu', 'akses', { uraian: 'Pelaksana: peran pemegang pengaturan.akun.perbaiki.' })
  const jPersetujuan = tambahJenis('PERSETUJUAN_AKSES_MENUNGGU', 'Persetujuan akses menunggu', 'akses', { uraian: 'Pelaksana: peran penyetuju izin sensitif.' })
  const bentukJenis = (j: JenisTugas & { v: number }): JenisTugas => {
    const { v, ...isi } = j
    return { ...isi, versi: String(v) }
  }

  // ── Notifikasi akun yang masuk ──
  const notifikasi: Notifikasi[] = []
  const tambahNotif = (isi: Partial<Notifikasi> & { judul: string; jenis: string }, menit: number) => {
    const n: Notifikasi = { id: id(++nomor), isi: '', tautan: null, prioritas: 'biasa', tugas_id: null, dibaca_pada: null, dibuat_pada: menitLalu(menit), ...isi }
    notifikasi.push(n)
    return n
  }

  // ── Tugas ──
  type TugasT = Tugas & { tab: TabTugas; riwayat: RiwayatTugas[] }
  const tugas: TugasT[] = []
  const tambahTugas = (tab: TabTugas, isi: Partial<Tugas> & { judul: string; tenggat_pada: string }, riwayat: Partial<RiwayatTugas>[] = []) => {
    const t: TugasT = {
      id: id(++nomor), jenis: { kode: jPersetujuan.kode, nama: jPersetujuan.nama }, objek: { jenis: 'persetujuan_akses', id: id(++nomor), label: 'PSA-2026-000101' },
      tautan: '/pengaturan/persetujuan', pelaksana: { jenis: 'peran', id: id(1), nama: 'Superadmin' }, tingkat_saat_ini: 0, status: 'terbuka', terlambat: false, kritis: false,
      dibuat_pada: menitLalu(600), selesai_pada: null, tab, riwayat: [], ...isi,
    }
    t.riwayat = [{ jenis: 'dibuat', tingkat: 0, terjadi_pada: t.dibuat_pada, penerima: [{ id: id(1), nama: 'Contoh Superadmin' }], kanal: ['in_app'], catatan: null }, ...riwayat.map((r) => ({
      jenis: 'pengingat' as const, tingkat: 1, terjadi_pada: menitLalu(60), penerima: [{ id: id(1), nama: 'Contoh Superadmin' }], kanal: ['in_app', 'email'] as RiwayatTugas['kanal'], catatan: null, ...r,
    }))]
    tugas.push(t)
    return t
  }
  const t1 = tambahTugas('saya', { judul: 'Putuskan persetujuan PSA-2026-000101', tenggat_pada: menitLalu(-120), tingkat_saat_ini: 1 }, [{ jenis: 'pengingat', tingkat: 1, terjadi_pada: menitLalu(30) }])
  const t2 = tambahTugas('saya', {
    judul: 'Tindak lanjuti permintaan akses contoh.dokter', jenis: { kode: jPermintaan.kode, nama: jPermintaan.nama }, objek: { jenis: 'permintaan_akses', id: id(++nomor), label: 'Rawat Jalan → Worklist' },
    tautan: '/pengaturan/persetujuan?tab=permintaan', tenggat_pada: menitLalu(-2000),
  })
  tambahTugas('dieskalasi', { judul: 'Putuskan persetujuan PSA-2026-000099', tenggat_pada: menitLalu(300), tingkat_saat_ini: 2, terlambat: true, kritis: true }, [
    { jenis: 'pengingat', tingkat: 1, terjadi_pada: menitLalu(540) },
    { jenis: 'teguran', tingkat: 2, terjadi_pada: menitLalu(300), penerima: [], kanal: [], catatan: 'Tanpa penerima: kepala unit belum ditetapkan.' },
  ])
  tambahTugas('unit', {
    judul: 'Perpanjang SIP Contoh Perawat', jenis: { kode: 'IZIN_PRAKTIK_BERAKHIR', nama: 'Izin praktik akan berakhir' }, objek: { jenis: 'izin_praktik', id: id(++nomor), label: 'SIP Contoh Perawat' },
    tautan: '/sdm/pegawai', pelaksana: { jenis: 'pengguna', id: id(3), nama: 'Contoh Perawat' }, tenggat_pada: menitLalu(-20000),
  })
  tambahTugas('selesai', { judul: 'Putuskan persetujuan PSA-2026-000090', tenggat_pada: menitLalu(1500), status: 'selesai', selesai_pada: menitLalu(1600) }, [
    { jenis: 'selesai', tingkat: 0, terjadi_pada: menitLalu(1600), penerima: [], kanal: [], catatan: 'Diputuskan: disetujui.' },
  ])
  tambahNotif({ jenis: 'AKUN_TERKUNCI', judul: 'Akun Anda sempat terkunci', isi: 'Terlalu banyak percobaan masuk gagal. Bila bukan Anda, segera ganti sandi.', prioritas: 'kritis' }, 15)
  tambahNotif({ jenis: jPersetujuan.kode, judul: 'Pengingat: putuskan PSA-2026-000101', isi: 'Tenggat 2 jam lagi.', tautan: '/pengaturan/persetujuan', prioritas: 'penting', tugas_id: t1.id }, 30)
  tambahNotif({ jenis: jPermintaan.kode, judul: 'Permintaan akses baru dari contoh.dokter', isi: 'Rawat Jalan → Worklist poliklinik.', tautan: '/pengaturan/persetujuan?tab=permintaan', tugas_id: t2.id }, 90)
  tambahNotif({ jenis: jPersetujuan.kode, judul: 'PSA-2026-000090 sudah diputuskan', isi: 'Disetujui superadmin lain.', dibaca_pada: menitLalu(1500) }, 1600)

  // ── Preferensi ──
  const prefEmail = new Map<string, boolean>()
  const daftarPreferensi = (): PreferensiNotifikasi[] => [
    { jenis: 'AKUN_TERKUNCI', nama: 'Akun terkunci', kritis: true },
    ...[...jenis.values()].filter((j) => j.aktif).map((j) => ({ jenis: j.kode, nama: j.nama, kritis: j.kritis })),
  ].map((p) => ({
    ...p, in_app: true as const,
    email: { aktif: p.kritis || (prefEmail.get(p.jenis) ?? true), dapat_diubah: !p.kritis },
    pesan_singkat: { aktif: false, dapat_diubah: false },
  }))

  function halaman<T>(daftar: T[], kueri: URLSearchParams, meta: Record<string, unknown> = {}) {
    const batas = Number(kueri.get('batas') ?? 50)
    if (batas > 200) return null
    const mulai = kueri.get('kursor') ? Number(atob(kueri.get('kursor')!)) : 0
    return { data: daftar.slice(mulai, mulai + batas), meta: { kursor_berikutnya: mulai + batas < daftar.length ? btoa(String(mulai + batas)) : null, ...meta } }
  }
  const denganEtag = (isi: unknown, header: Record<string, string | undefined>) => {
    const e = sidik(isi)
    return header['if-none-match'] === e ? ok(null, 304, { ETag: e }) : ok(isi, 200, { ETag: e })
  }
  const urutNotif = () => [...notifikasi].sort((x, y) => y.dibuat_pada.localeCompare(x.dibuat_pada))
  const belumDibaca = () => notifikasi.filter((n) => !n.dibaca_pada).length
  const bentukTugas = (t: TugasT): Tugas => {
    const { tab: _, riwayat: __, ...isi } = t
    return { ...isi, terlambat: t.status === 'terbuka' ? new Date(t.tenggat_pada) < sekarang() : t.terlambat }
  }

  function tangani(r: PermintaanTiruan): ResponsTiruan | null {
    const m = r.metode.toUpperCase()
    const isi = (r.isi ?? {}) as Isi
    const [a0, a1, a2, a3] = r.jalur.replace(/\/+$/, '').split('/').filter(Boolean)

    if (a0 === 'saya' && a1 === 'notifikasi') {
      if (m === 'GET' && !a2) {
        const status = r.kueri.get('status') ?? 'semua'
        if (!['semua', 'belum_dibaca'].includes(status)) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'status', pesan: 'Status tidak dikenal.' }])
        const h = halaman(urutNotif().filter((n) => status === 'semua' || !n.dibaca_pada), r.kueri, { jumlah_belum_dibaca: belumDibaca() })
        return h ? denganEtag(h, r.header) : error(422, 'ukuran_halaman_melebihi_batas', 'Ukuran halaman maksimum 200.')
      }
      if (m === 'POST' && a2 === 'semua-dibaca') {
        const sampai = typeof isi.sampai === 'string' ? isi.sampai : null
        let jumlah = 0
        for (const n of notifikasi) {
          if (n.dibaca_pada || n.prioritas === 'kritis' || (sampai && n.dibuat_pada > sampai)) continue
          n.dibaca_pada = sekarang().toISOString()
          jumlah++
        }
        return ok({ data: { jumlah } })
      }
      if (m === 'POST' && a3 === 'dibaca') {
        const n = notifikasi.find((x) => x.id === a2)
        if (!n) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
        n.dibaca_pada ??= sekarang().toISOString()
        return ok({ data: n })
      }
      return null
    }
    if (a0 === 'saya' && a1 === 'preferensi-notifikasi') {
      if (m === 'GET') return ok({ data: daftarPreferensi() })
      if (m === 'PUT') {
        const daftar = Array.isArray(isi.preferensi) ? (isi.preferensi as Isi[]) : []
        const kini = daftarPreferensi()
        for (const p of daftar) {
          const ada = kini.find((x) => x.jenis === p.jenis)
          if (!ada) return error(422, 'jenis_tidak_dikenal', `Jenis ${String(p.jenis)} tidak dikenal.`, [{ kolom: 'preferensi', pesan: 'Jenis tidak dikenal.' }])
          if (ada.kritis && (p.email === false || p.pesan_singkat === false)) return error(422, 'notifikasi_kritis', 'Notifikasi kritis tidak bisa dimatikan.')
          if ('pesan_singkat' in p && !ada.pesan_singkat.dapat_diubah) return error(422, 'kanal_tidak_tersedia', 'Pesan singkat belum tersedia di instalasi ini.')
        }
        for (const p of daftar) if (typeof p.email === 'boolean') prefEmail.set(String(p.jenis), p.email)
        return ok({ data: daftarPreferensi() })
      }
      return null
    }
    if (a0 === 'saya' && a1 === 'tugas' && m === 'GET') {
      const tab = r.kueri.get('tab') as TabTugas | null
      if (!tab || !['saya', 'dieskalasi', 'unit', 'selesai'].includes(tab)) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'tab', pesan: 'Pilih tab.' }])
      const per = (x: TabTugas) => tugas.filter((t) => t.tab === x && (x === 'selesai' ? t.status !== 'terbuka' : t.status === 'terbuka'))
      const urut = per(tab).sort((x, y) => (tab === 'selesai' ? (y.selesai_pada ?? '').localeCompare(x.selesai_pada ?? '') : x.tenggat_pada.localeCompare(y.tenggat_pada)))
      const jumlah = { saya: per('saya').length, dieskalasi: per('dieskalasi').length, unit: per('unit').length, selesai: per('selesai').length }
      const h = halaman(urut.map(bentukTugas), r.kueri, { jumlah })
      return h ? denganEtag(h, r.header) : error(422, 'ukuran_halaman_melebihi_batas', 'Ukuran halaman maksimum 200.')
    }
    if (a0 === 'tugas' && a1 && !a2 && m === 'GET') {
      const t = tugas.find((x) => x.id === a1)
      return t ? ok({ data: { ...bentukTugas(t), riwayat: t.riwayat } }) : error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
    }
    if (a0 === 'jenis-tugas') {
      if (!opsi.boleh('master_data.jenis_tugas.lihat')) return error(403, 'akses_ditolak', 'Anda tidak punya akses untuk tindakan ini.')
      if (m === 'GET' && !a1) {
        const aktif = r.kueri.get('aktif')
        const daftar = [...jenis.values()].filter((j) => aktif === null || String(j.aktif) === aktif).sort((x, y) => x.kode.localeCompare(y.kode)).map(bentukJenis)
        return ok(halaman(daftar, r.kueri))
      }
      const j = a1 ? jenis.get(a1) : undefined
      if (!j) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
      if (m === 'GET') return ok({ data: bentukJenis(j) }, 200, { ETag: `"${j.v}"` })
      if (m === 'PATCH') {
        if (!opsi.boleh('master_data.jenis_tugas.perbaiki')) return error(403, 'akses_ditolak', 'Anda tidak punya akses untuk tindakan ini.')
        const im = r.header['if-match']
        if (!im) return error(428, 'if_match_wajib', 'Header If-Match wajib.')
        if (im.replace(/"/g, '') !== String(j.v)) return error(412, 'versi_berubah', 'Jenis tugas ini diubah orang lain sejak dibuka.')
        if (typeof isi.dasar_perubahan !== 'string' || isi.dasar_perubahan.trim().length < 3) {
          return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'dasar_perubahan', pesan: 'Isi dasar perubahan, minimal 3 karakter.' }])
        }
        if (Object.keys(isi).length < 2) return error(422, 'validasi_gagal', 'Kirim minimal satu perubahan.')
        if ('nama' in isi && (typeof isi.nama !== 'string' || isi.nama.trim().length < 3)) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'nama', pesan: 'Nama minimal 3 karakter.' }])
        if ('batas_menit' in isi && (!Number.isInteger(isi.batas_menit) || (isi.batas_menit as number) < 1 || (isi.batas_menit as number) > 525600)) {
          return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'batas_menit', pesan: 'Batas 1 menit sampai 1 tahun.' }])
        }
        const e = isi.eskalasi as JenisTugas['eskalasi'] | undefined
        if (e) {
          if (!(e.pengingat_menit < 0)) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'eskalasi.pengingat_menit', pesan: 'Pengingat harus sebelum tenggat.' }])
          if (e.eskalasi_menit < e.teguran_menit) return error(422, 'eskalasi_tidak_urut', 'Eskalasi tidak boleh lebih awal dari teguran.', [{ kolom: 'eskalasi.eskalasi_menit', pesan: 'Eskalasi tidak boleh lebih awal dari teguran.' }])
        }
        for (const k of ['unit_teguran_id', 'unit_eskalasi_id'] as const) {
          if (k in isi && isi[k] !== null && !opsi.unit(String(isi[k]))?.aktif) return error(422, 'unit_tidak_valid', 'Unit tidak ada atau nonaktif.', [{ kolom: k, pesan: 'Pilih unit aktif.' }])
        }
        if (typeof isi.nama === 'string') j.nama = isi.nama.trim()
        if (typeof isi.batas_menit === 'number') j.batas_menit = isi.batas_menit
        if (e) j.eskalasi = { ...e }
        if (typeof isi.kritis === 'boolean') j.kritis = isi.kritis
        if (typeof isi.aktif === 'boolean') j.aktif = isi.aktif
        const ringkas = (v: unknown) => {
          const u = v ? opsi.unit(String(v)) : null
          return u ? { id: u.id, kode: u.kode, nama: u.nama } : null
        }
        if ('unit_teguran_id' in isi) j.unit_teguran = ringkas(isi.unit_teguran_id)
        if ('unit_eskalasi_id' in isi) j.unit_eskalasi = ringkas(isi.unit_eskalasi_id)
        j.v++
        j.diubah_pada = sekarang().toISOString()
        return ok({ data: bentukJenis(j) }, 200, { ETag: `"${j.v}"` })
      }
      return null
    }
    return null
  }

  return {
    tangani,
    /** Uji: notifikasi baru tiba (polling berikutnya melihatnya). */
    tambahNotifikasi: (isi: Partial<Notifikasi> & { judul: string; jenis: string }) => tambahNotif(isi, 0),
    jumlahBelumDibaca: belumDibaca,
  }
}
