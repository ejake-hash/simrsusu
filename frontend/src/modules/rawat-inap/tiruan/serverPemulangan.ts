import type { PermintaanTiruan, ResponsTiruan } from '../../akses/tiruan/serverTiruan'
import { PIN_TIRUAN } from '../../ttd/tiruan/serverTtd'
import type { Pelaku } from '@/modules/pendaftaran/tipeKunjungan'
import { hariIniRs } from '../admisi'
import type { ButirDaftarPeriksaPulang, DaftarPeriksaPulangDataInduk, IsianResumeMedis, RawatInap, RencanaPulang, ResumeMedis, TempatTidurPeta, TujuanPulang } from '../tipe'

/**
 * Server tiruan kontrak v0.39.0 (tiket 2.40): rencana pulang (GET/PUT, If-Match), pulang (POST, alasan per butir wajib),
 * resume medis (GET/POST susun, POST sahkan PIN T1 DPJP), data induk daftar periksa pulang (GET kursor, PATCH If-Match).
 * Pasien rawat inap = TT berpasien server tiruan peta TT; pulang mengubah TT itu jadi dibersihkan. Data SINTETIS.
 */
const NO_STORE = { 'Cache-Control': 'no-store' }
const error = (status: number, kode: string, pesan: string, rincian: Record<string, unknown>[] = []): ResponsTiruan => ({ status, header: NO_STORE, isi: { error: { kode, pesan, rincian } } })
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i
const id = (n: number) => `00000000-0000-7000-b240-${n.toString(16).padStart(12, '0')}`
const TUJUAN: readonly TujuanPulang[] = ['pulang', 'aps', 'rujuk_keluar', 'meninggal']

export interface OpsiServerPemulangan {
  boleh: (izin: string) => boolean
  /** TT peta tiruan (objek asli, diubah saat pulang). */
  tempatTidur: () => TempatTidurPeta[]
  /** DPJP semua kunjungan contoh (pegawai). */
  dpjp: Pelaku
  /** Pegawai akun yang masuk; sahkan hanya bila sama dengan DPJP. */
  pegawaiSaya: () => string | null
  pelaku: () => Pelaku
  sekarang?: () => Date
}

interface Rencana {
  id: string
  status: 'direncanakan' | 'pulang'
  tanggal: string
  lama: number | null
  butir: ButirDaftarPeriksaPulang[]
  versi: number
}
interface Kunjungan {
  id: string
  status: 'dilayani' | 'selesai'
  pasien: RencanaPulang['pasien']
  tt: TempatTidurPeta | null
  hari: number | null
  rencana: Rencana | null
  resume: ResumeMedis | null
}

export function buatServerPemulangan(opsi: OpsiServerPemulangan) {
  const sekarang = opsi.sekarang ?? (() => new Date())
  const iso = () => sekarang().toISOString()
  let nomor = 0
  const dataInduk: DaftarPeriksaPulangDataInduk[] = (
    [
      ['OBAT_EDUKASI', 'Obat pulang dan edukasi', 'manual', 1],
      ['SURAT_KONTROL', 'Surat kontrol', 'manual', 2],
      ['RUJUK_BALIK', 'Rujuk balik', 'manual', 3],
      ['TAGIHAN_JAMINAN', 'Tagihan lunas atau jaminan', 'kejadian', 4],
      ['LEPAS_INFUS_GELANG', 'Lepas infus dan gelang', 'manual', 5],
    ] as const
  ).map(([kode, nama, sumber, urutan]) => ({ id: id(++nomor), kode, nama, sumber, wajib: true, urutan, status: 'aktif', versi: '1', diubah_pada: '2026-11-01T00:00:00.000Z' }))

  const salinDataInduk = (): ButirDaftarPeriksaPulang[] =>
    dataInduk
      .filter((d) => d.status === 'aktif')
      .sort((a, b) => a.urutan - b.urutan || a.kode.localeCompare(b.kode))
      .map((d) => ({ kode: d.kode, nama: d.nama, sumber: d.sumber, wajib: d.wajib, status: d.sumber === 'kejadian' ? 'menunggu_kasir' : 'belum', selesai_pada: null, selesai_oleh: null, alasan_tidak_selesai: null }))

  // Kunjungan RI = TT berpasien di peta tiruan; dibuat sekali saat pertama dibaca agar status tetap sesudah pulang.
  const kunjungan = new Map<string, Kunjungan>()
  function cari(kunjunganId: string): Kunjungan | null {
    if (!UUID.test(kunjunganId)) return null
    const ada = kunjungan.get(kunjunganId)
    if (ada) return ada
    const tt = opsi.tempatTidur().find((x) => x.kunjungan_rawat_inap_id === kunjunganId)
    if (!tt?.pasien_rawat_inap) return null
    const p = tt.pasien_rawat_inap
    const k: Kunjungan = { id: kunjunganId, status: 'dilayani', pasien: { id: `${kunjunganId.slice(0, -4)}aaaa`, no_rm: p.no_rm, nama: p.nama, jenis_kelamin: 'perempuan', tanggal_lahir: p.tanggal_lahir }, tt, hari: tt.hari_rawat, rencana: null, resume: null }
    kunjungan.set(kunjunganId, k)
    return k
  }

  const bentukRencana = (k: Kunjungan): RencanaPulang => ({
    kunjungan_id: k.id, kunjungan_status: k.status, pasien: structuredClone(k.pasien), hari_rawat: k.status === 'dilayani' ? k.hari : null,
    id: k.rencana?.id ?? null, status: k.rencana?.status ?? null, rencana_pulang_pada: k.rencana?.tanggal ?? null, rencana_lama_rawat: k.rencana?.lama ?? null,
    daftar_periksa: structuredClone(k.rencana?.butir ?? salinDataInduk()), versi: k.rencana ? String(k.rencana.versi) : null,
  })
  const okRencana = (k: Kunjungan, status = 200): ResponsTiruan => ({ status, header: { ...NO_STORE, ...(k.rencana ? { ETag: `"${k.rencana.versi}"` } : {}) }, isi: { data: bentukRencana(k) } })
  const okResume = (r: ResumeMedis | null, status = 200): ResponsTiruan => ({ status, header: { ...NO_STORE, ...(r ? { ETag: `"${r.versi}"` } : {}) }, isi: { data: structuredClone(r) } })
  const versiDari = (r: PermintaanTiruan) => (r.header['if-match'] ?? '').replace(/^W\//, '').replace(/"/g, '')
  const statusPeta = (k: Kunjungan) => {
    if (k.tt && k.status === 'dilayani') k.tt.status = k.rencana?.tanggal === hariIniRs(sekarang()) ? 'rencana_pulang' : k.rencana?.lama && (k.hari ?? 0) > k.rencana.lama ? 'melewati_rencana_lama_rawat' : 'terisi'
  }

  function simpanRencana(k: Kunjungan, r: PermintaanTiruan): ResponsTiruan {
    const bolehInput = opsi.boleh('rawat_inap.rencana_pulang.input')
    const bolehPerbaiki = opsi.boleh('rawat_inap.rencana_pulang.perbaiki')
    if (!bolehInput && !bolehPerbaiki) return error(403, 'akses_ditolak', 'Anda tidak berhak mengubah rencana pulang.')
    if (k.status !== 'dilayani') return error(409, 'status_tidak_sesuai', 'Pemulangan hanya untuk kunjungan rawat inap yang masih berjalan.', [{ jenis_kunjungan: 'rawat_inap', status: k.status }])
    if (k.rencana) {
      if (!bolehPerbaiki) return error(403, 'akses_ditolak', 'Anda tidak berhak mengubah rencana pulang.')
      if (!r.header['if-match']) return error(428, 'if_match_wajib', 'Versi data tidak dikirim. Muat ulang halaman lalu coba lagi.')
      if (versiDari(r) !== String(k.rencana.versi)) return error(412, 'versi_berubah', 'Rencana pulang ini diubah orang lain sejak Anda membukanya.')
    } else if (!bolehInput) return error(403, 'akses_ditolak', 'Anda tidak berhak membuat rencana pulang.')
    const isi = (r.isi ?? {}) as { rencana_pulang_pada?: string; rencana_lama_rawat?: number | null; daftar_periksa?: { kode: string; selesai: boolean }[] }
    if (!isi.rencana_pulang_pada || !/^\d{4}-\d{2}-\d{2}$/.test(isi.rencana_pulang_pada)) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'rencana_pulang_pada', pesan: 'Tanggal rencana pulang wajib.' }])
    if (isi.rencana_lama_rawat !== undefined && isi.rencana_lama_rawat !== null && (!Number.isInteger(isi.rencana_lama_rawat) || isi.rencana_lama_rawat < 1 || isi.rencana_lama_rawat > 365)) {
      return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'rencana_lama_rawat', pesan: 'Rencana lama rawat 1 sampai 365 hari.' }])
    }
    const baru = !k.rencana
    const rencana: Rencana = k.rencana ?? { id: id(++nomor), status: 'direncanakan', tanggal: isi.rencana_pulang_pada, lama: null, butir: salinDataInduk(), versi: 0 }
    const butir = structuredClone(rencana.butir)
    for (const [n, c] of (isi.daftar_periksa ?? []).entries()) {
      const b = butir.find((x) => x.kode === c.kode)
      if (!b) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: `daftar_periksa.${n}.kode`, pesan: 'Butir tidak ada di daftar periksa rencana ini.' }])
      if (b.sumber === 'kejadian') return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: `daftar_periksa.${n}.kode`, pesan: 'Butir ini diselesaikan kasir (Tahap D), bukan dicentang.' }])
      if (c.selesai && !b.selesai_pada) Object.assign(b, { status: 'selesai', selesai_pada: iso(), selesai_oleh: opsi.pelaku() })
      if (!c.selesai) Object.assign(b, { status: 'belum', selesai_pada: null, selesai_oleh: null })
    }
    rencana.tanggal = isi.rencana_pulang_pada
    if (isi.rencana_lama_rawat !== undefined) rencana.lama = isi.rencana_lama_rawat
    rencana.butir = butir
    rencana.versi++
    k.rencana = rencana
    statusPeta(k)
    return okRencana(k, baru ? 201 : 200)
  }

  function pulang(k: Kunjungan, r: PermintaanTiruan): ResponsTiruan {
    const isi = (r.isi ?? {}) as { tujuan?: string; alasan?: string; tanggapan?: { kode: string; alasan: string }[] }
    if (!TUJUAN.includes(isi.tujuan as TujuanPulang)) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'tujuan', pesan: 'Pilih tujuan keluar.' }])
    if (!opsi.boleh('rawat_inap.pemulangan.input')) return error(403, 'akses_ditolak', 'Anda tidak berhak memulangkan pasien.')
    if (k.status !== 'dilayani' || !k.tt) return error(409, 'status_tidak_sesuai', 'Pasien ini tidak menempati TT.', [{ jenis_kunjungan: 'rawat_inap', status: k.status }])
    if (!isi.alasan || isi.alasan.trim().length < 5) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'alasan', pesan: 'Alasan minimal 5 karakter.' }])
    k.rencana ??= { id: id(++nomor), status: 'direncanakan', tanggal: hariIniRs(sekarang()), lama: null, butir: salinDataInduk(), versi: 1 }
    const tanggapan = new Map((isi.tanggapan ?? []).map((t) => [t.kode, t.alasan]))
    const kurang = k.rencana.butir
      .filter((b) => b.wajib && b.sumber === 'manual' && b.status === 'belum' && (tanggapan.get(b.kode) ?? '').trim().length < 5)
      .map((b) => ({ kolom: 'tanggapan', kode: b.kode, nama: b.nama, pesan: 'Selesaikan butir ini atau tulis alasan belum selesai.' }))
    if (kurang.length) return error(422, 'daftar_periksa_belum_lengkap', 'Ada butir wajib daftar periksa pulang yang belum selesai dan belum diberi alasan.', kurang)
    for (const b of k.rencana.butir) if (b.wajib && b.sumber === 'manual' && b.status === 'belum') b.alasan_tidak_selesai = tanggapan.get(b.kode)!.trim()
    const tt = k.tt
    const hasil: RawatInap = {
      kunjungan: { id: k.id, nomor: `RI-20261102-${k.id.slice(-4)}`, status: 'selesai' }, pasien: structuredClone(k.pasien), episode: { id: id(++nomor), jenis: 'rawat_inap', mulai_tanggal: '2026-10-29' },
      permintaan_tempat_tidur_id: id(++nomor), asal: 'igd', kelas_dirawat: tt.kelas_rawat, mulai_pada: '2026-10-29T03:00:00.000Z', penempatan: null, hari_rawat: null,
    }
    k.status = 'selesai'
    k.rencana.status = 'pulang'
    k.rencana.versi++
    k.tt = null
    Object.assign(tt, { status: 'dibersihkan', status_sejak: iso(), hari_rawat: null, dapat_dipilih: false })
    delete tt.kunjungan_rawat_inap_id
    delete tt.pasien_rawat_inap
    return { status: 200, header: NO_STORE, isi: { data: hasil } }
  }

  const KOLOM_ISIAN: (keyof IsianResumeMedis)[] = ['ringkasan_perjalanan', 'kondisi_pulang', 'instruksi_pulang', 'obat_pulang', 'kontrol']
  function susun(k: Kunjungan, r: PermintaanTiruan): ResponsTiruan {
    if (!opsi.boleh('rawat_inap.pemulangan.input')) return error(403, 'akses_ditolak', 'Anda tidak berhak menyusun resume medis.')
    if (k.resume?.status === 'sah') return error(409, 'sudah_disahkan', 'Resume medis ini sudah disahkan dan tidak bisa diubah.')
    const isi = (r.isi ?? {}) as Partial<IsianResumeMedis>
    const baru = !k.resume
    const lama = k.resume?.isian_dpjp ?? { ringkasan_perjalanan: null, kondisi_pulang: null, instruksi_pulang: null, obat_pulang: null, kontrol: null }
    const isian = { ...lama }
    for (const kolom of KOLOM_ISIAN) if (kolom in isi) isian[kolom] = isi[kolom] ? String(isi[kolom]) : null
    k.resume = {
      id: k.resume?.id ?? id(++nomor), kunjungan_id: k.id, status: 'draf', pasien: structuredClone(k.pasien), dpjp: opsi.dpjp,
      sumber: {
        cppt: [{ id: id(++nomor), kunjungan_id: k.id, profesi: 'dokter', penulis: opsi.dpjp.nama, disahkan_pada: '2026-10-30T02:00:00.000Z', subjektif: 'Demam tiga hari (contoh)', objektif: 'Suhu 38,5 °C (contoh)', asesmen: 'Observasi febris (contoh)', plan: 'Rawat inap, cairan (contoh)' }],
        cppt_berlabel: 1,
        diagnosis: [{ kunjungan_id: k.id, sistem: 'icd10', kode: 'R50.9', tampilan: 'Demam (contoh)', jenis: 'utama' }],
        resep: [],
        alergi: [{ zat: 'Contoh zat', jenis: 'obat', reaksi: 'Ruam (contoh)', tingkat: 'ringan' }],
        tindakan: { tersedia: false },
        terpotong: false,
        dipotret_pada: iso(),
      },
      isian_dpjp: isian, disusun_oleh: opsi.pelaku(), disusun_pada: iso(), disahkan_oleh: null, disahkan_pada: null, tanda_tangan_id: null,
      versi: String(Number(k.resume?.versi ?? 0) + 1),
    }
    return okResume(k.resume, baru ? 201 : 200)
  }

  function sahkan(resumeId: string, r: PermintaanTiruan): ResponsTiruan {
    if (!opsi.boleh('rawat_inap.pemulangan.sahkan')) return error(403, 'akses_ditolak', 'Anda tidak berhak mengesahkan resume medis.')
    const k = UUID.test(resumeId) ? [...kunjungan.values()].find((x) => x.resume?.id === resumeId) : undefined
    if (!k?.resume) return error(404, 'tidak_ditemukan', 'Resume medis tidak ditemukan.')
    if (opsi.pegawaiSaya() !== k.resume.dpjp?.id) return error(403, 'bukan_pemutus', 'Resume medis hanya bisa disahkan DPJP pasien ini.')
    if (k.resume.status === 'sah') return error(409, 'sudah_disahkan', 'Resume medis ini sudah disahkan.')
    if (!r.header['if-match']) return error(428, 'if_match_wajib', 'Versi data tidak dikirim.')
    if (versiDari(r) !== k.resume.versi) return error(412, 'versi_berubah', 'Resume medis ini disusun ulang sejak Anda membukanya.')
    const isi = (r.isi ?? {}) as { pin?: string | null; pernyataan_disetujui?: boolean }
    if (isi.pernyataan_disetujui !== true) return error(422, 'validasi_gagal', 'Pernyataan wajib disetujui.', [{ kolom: 'pernyataan_disetujui' }])
    if (!isi.pin) return error(422, 'pin_wajib', 'PIN wajib.', [{ kolom: 'pin' }])
    if (isi.pin !== PIN_TIRUAN) return error(422, 'pin_salah', 'PIN salah.', [{ kolom: 'pin', sisa_percobaan: 4 }])
    Object.assign(k.resume, { status: 'sah', disahkan_oleh: opsi.pelaku(), disahkan_pada: iso(), tanda_tangan_id: id(++nomor), versi: String(Number(k.resume.versi) + 1) })
    return okResume(k.resume)
  }

  function dataIndukDaftar(r: PermintaanTiruan): ResponsTiruan {
    if (!opsi.boleh('master_data.daftar_periksa_pulang.lihat')) return error(403, 'akses_ditolak', 'Anda tidak berhak melihat daftar periksa pulang.')
    const status = r.kueri.get('status')
    const data = dataInduk.filter((d) => !status || d.status === status).sort((a, b) => a.kode.localeCompare(b.kode))
    return { status: 200, isi: { data: structuredClone(data), meta: { kursor_berikutnya: null, batas: 50 } } }
  }
  function dataIndukUbah(butirId: string, r: PermintaanTiruan): ResponsTiruan {
    if (!opsi.boleh('master_data.daftar_periksa_pulang.perbaiki')) return error(403, 'akses_ditolak', 'Anda tidak berhak mengubah daftar periksa pulang.')
    const d = dataInduk.find((x) => x.id === butirId)
    if (!d) return error(404, 'tidak_ditemukan', 'Butir daftar periksa tidak ditemukan.')
    if (!r.header['if-match']) return error(428, 'if_match_wajib', 'Versi data tidak dikirim.')
    if (versiDari(r) !== d.versi) return error(412, 'versi_berubah', 'Butir ini diubah orang lain.')
    const isi = (r.isi ?? {}) as Partial<DaftarPeriksaPulangDataInduk> & { dasar_perubahan?: string }
    if (!isi.dasar_perubahan || isi.dasar_perubahan.trim().length < 3) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'dasar_perubahan', pesan: 'Isi dasar perubahan.' }])
    if (Object.keys(isi).length < 2) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'dasar_perubahan', pesan: 'Isi minimal satu perubahan.' }])
    for (const k of ['nama', 'wajib', 'urutan', 'status'] as const) if (k in isi) Object.assign(d, { [k]: isi[k] })
    d.versi = String(Number(d.versi) + 1)
    d.diubah_pada = iso()
    return { status: 200, header: { ETag: `"${d.versi}"` }, isi: { data: structuredClone(d) } }
  }

  function tangani(r: PermintaanTiruan): ResponsTiruan | null {
    const b = r.jalur.replace(/\/+$/, '').split('/').filter(Boolean)
    const m = r.metode.toUpperCase()
    if (b[0] === 'data-induk' && b[1] === 'daftar-periksa-pulang') {
      if (b.length === 2 && m === 'GET') return dataIndukDaftar(r)
      if (b.length === 3 && m === 'PATCH') return dataIndukUbah(b[2]!, r)
      return null
    }
    if (b[0] === 'resume-medis' && b[2] === 'sahkan' && b.length === 3 && m === 'POST') return sahkan(b[1]!, r)
    if (b[0] !== 'kunjungan' || b.length !== 3 || !['rencana-pulang', 'pulang', 'resume-medis'].includes(b[2]!)) return null
    // Izin sebelum kunjungan dibaca (kontrak): tanpa izin selalu 403, juga untuk id tak dikenal.
    if (b[2] === 'rencana-pulang' && m === 'GET' && !opsi.boleh('rawat_inap.rencana_pulang.lihat')) return error(403, 'akses_ditolak', 'Anda tidak berhak melihat rencana pulang.')
    if (b[2] === 'resume-medis' && m === 'GET' && !opsi.boleh('rawat_inap.pemulangan.lihat')) return error(403, 'akses_ditolak', 'Anda tidak berhak melihat resume medis.')
    const k = cari(b[1]!)
    if (!k) return error(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.')
    if (b[2] === 'rencana-pulang' && m === 'GET') return okRencana(k)
    if (b[2] === 'rencana-pulang' && m === 'PUT') return simpanRencana(k, r)
    if (b[2] === 'pulang' && m === 'POST') return pulang(k, r)
    if (b[2] === 'resume-medis' && m === 'GET') return okResume(k.resume)
    if (b[2] === 'resume-medis' && m === 'POST') return susun(k, r)
    return null
  }

  return { tangani, dataInduk: () => dataInduk, kunjungan: (kunjunganId: string) => cari(kunjunganId) }
}
