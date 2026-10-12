import type { PermintaanTiruan, ResponsTiruan } from '../../akses/tiruan/serverTiruan'
import type { HasilUjiKoneksi, Kiriman, KredensialLingkungan, LingkunganIntegrasi, LogIntegrasi, SistemIntegrasi, StatusKiriman, StatusSistemIntegrasi } from '../tipe'

/**
 * Server tiruan gateway integrasi (kontrak v0.13.0) untuk `vite` dev dan uji unit: kredensial tulis-saja (nilai rahasia
 * tidak pernah dikembalikan), uji koneksi (berhasil bila kredensial lingkungan lengkap dan secret tidak berawalan
 * "salah"), kotak keluar dan log contoh dengan izin per tujuan. Data contoh sintetis, tanpa data pasien.
 */
const error = (status: number, kode: string, pesan: string, rincian: Record<string, unknown>[] = []): ResponsTiruan => ({ status, isi: { error: { kode, pesan, rincian } } })
const ok = (isi: unknown): ResponsTiruan => ({ status: 200, isi })

const SISTEM: [SistemIntegrasi, string][] = [['bpjs_vclaim', 'BPJS VClaim'], ['aplicares', 'Aplicares'], ['satusehat', 'SATUSEHAT']]
const KOLOM: Record<SistemIntegrasi, [string, boolean][]> = {
  bpjs_vclaim: [['cons_id', false], ['secret_key', true], ['user_key', true]],
  aplicares: [['cons_id', false], ['secret_key', true], ['user_key', true]],
  satusehat: [['client_id', false], ['organization_id', false], ['client_secret', true]],
}
const LINGKUNGAN: LingkunganIntegrasi[] = ['sandbox', 'produksi']
const izinTujuan = (t: SistemIntegrasi) => (t === 'satusehat' ? 'integrasi.satusehat' : 'integrasi.bpjs')

export interface OpsiServerIntegrasi {
  boleh: (kode: string) => boolean
  pelaku: () => { id: string; nama: string }
  sekarang?: () => Date
}

export function buatServerIntegrasi(opsi: OpsiServerIntegrasi) {
  const kini = () => opsi.sekarang?.() ?? new Date()
  const iso = (menitLalu = 0) => new Date(kini().getTime() - menitLalu * 60_000).toISOString()
  interface SistemT {
    aktif: LingkunganIntegrasi | null
    nilai: Record<LingkunganIntegrasi, Record<string, string>>
    diubah: Record<LingkunganIntegrasi, { pada: string; oleh: { id: string; nama: string } } | null>
    uji: HasilUjiKoneksi | null
    cb: StatusSistemIntegrasi['circuit_breaker']
    error: { pesan: string; pada: string } | null
  }
  const sistem = new Map<SistemIntegrasi, SistemT>()
  for (const [k] of SISTEM) {
    sistem.set(k, { aktif: null, nilai: { sandbox: {}, produksi: {} }, diubah: { sandbox: null, produksi: null }, uji: null, cb: { status: 'tertutup', gagal_beruntun: 0, sejak: null, coba_lagi_pada: null }, error: null })
  }
  // Contoh: SATUSEHAT sandbox aktif dan tersambung; Aplicares terputus sementara.
  const ss = sistem.get('satusehat')!
  ss.nilai.sandbox = { client_id: 'contoh-client-sandbox', organization_id: '10000004', client_secret: 'rahasia-sintetis-0000-abcd' }
  ss.aktif = 'sandbox'
  ss.uji = { lingkungan: 'sandbox', berhasil: true, kode_http: 200, latensi_ms: 412, pesan: 'Token OAuth2 diterima.', pada: iso(30) }
  const ap = sistem.get('aplicares')!
  ap.nilai.sandbox = { cons_id: '12345', secret_key: 'rahasia-sintetis-1111-wxyz', user_key: 'kunci-pengguna-sintetis-9876' }
  ap.aktif = 'sandbox'
  ap.cb = { status: 'terbuka', gagal_beruntun: 5, sejak: iso(12), coba_lagi_pada: new Date(kini().getTime() + 3 * 60_000).toISOString() }
  ap.error = { pesan: 'Batas waktu 15 detik terlewati (bed/update).', pada: iso(12) }

  let urut = 0
  const id = () => `0199c000-0000-7000-8000-${String(++urut).padStart(12, '0')}`
  const kiriman: Kiriman[] = []
  const tambahKiriman = (k: Partial<Kiriman> & Pick<Kiriman, 'tujuan' | 'status' | 'jenis_resource' | 'objek_jenis'>, menitLalu: number) =>
    kiriman.push({
      id: id(), lingkungan: 'sandbox', objek_id: id(), percobaan: 0, coba_lagi_pada: null, terkirim_pada: null, error_terakhir: null, penyebab_tertahan: null,
      pemilik_unit: null, id_luar: null, dapat_kirim_ulang: false, dibuat_pada: iso(menitLalu), ...k,
    })
  tambahKiriman({ tujuan: 'satusehat', status: 'terkirim', jenis_resource: 'Organization', objek_jenis: 'unit_organisasi', percobaan: 1, terkirim_pada: iso(55), id_luar: 'org-sintetis-1' }, 56)
  tambahKiriman({ tujuan: 'satusehat', status: 'terkirim', jenis_resource: 'Location', objek_jenis: 'lokasi', percobaan: 1, terkirim_pada: iso(50), id_luar: 'loc-sintetis-1' }, 51)
  tambahKiriman({ tujuan: 'satusehat', status: 'tertahan', jenis_resource: 'Location', objek_jenis: 'lokasi', penyebab_tertahan: { kode: 'induk_belum_terdaftar', pesan: 'Lokasi induk belum terdaftar di SATUSEHAT.' }, pemilik_unit: { id: id(), kode: 'IRNA', nama: 'Instalasi Rawat Inap' } }, 40)
  tambahKiriman({ tujuan: 'satusehat', status: 'gagal', jenis_resource: 'Location', objek_jenis: 'lokasi', percobaan: 6, error_terakhir: 'HTTP 500 dari server tujuan.' }, 35)
  tambahKiriman({ tujuan: 'aplicares', status: 'menunggu', jenis_resource: 'bed_update', objek_jenis: 'bangsal', percobaan: 2, coba_lagi_pada: new Date(kini().getTime() + 3 * 60_000).toISOString() }, 12)
  tambahKiriman({ tujuan: 'aplicares', status: 'tertahan', jenis_resource: 'bed_update', objek_jenis: 'bangsal', penyebab_tertahan: { kode: 'kode_kelas_aplicares_kosong', pesan: 'Kelas rawat belum punya kode Aplicares.' }, pemilik_unit: { id: id(), kode: 'IRNA', nama: 'Instalasi Rawat Inap' } }, 20)

  const log: LogIntegrasi[] = []
  const catatLog = (l: Omit<LogIntegrasi, 'id' | 'terjadi_pada' | 'terpotong'> & { menitLalu?: number }) =>
    log.unshift({ id: id(), terjadi_pada: iso(l.menitLalu ?? 0), terpotong: false, ...l })
  catatLog({ tujuan: 'satusehat', lingkungan: 'sandbox', metode: 'POST', endpoint: '/fhir-r4/v1/Location', kode_http: 500, berhasil: false, latensi_ms: 230, pesan: 'HTTP 500 dari server tujuan.', kotak_keluar_id: kiriman[3]!.id, permintaan: '{"resourceType":"Location","name":"***"}', respons: '{"issue":[{"severity":"error"}]}', menitLalu: 35 })
  catatLog({ tujuan: 'aplicares', lingkungan: 'sandbox', metode: 'POST', endpoint: '/aplicaresws/rest/bed/update/***', kode_http: null, berhasil: false, latensi_ms: null, pesan: 'Batas waktu 15 detik terlewati.', kotak_keluar_id: kiriman[4]!.id, permintaan: '{"kodekelas":"***","kapasitas":"***"}', respons: null, menitLalu: 12 })

  function hitungDapat(k: Kiriman): boolean {
    const aktif = sistem.get(k.tujuan)!.aktif
    return (k.status === 'gagal' || k.status === 'tertahan') && opsi.boleh(`${izinTujuan(k.tujuan)}.perbaiki`) && (k.lingkungan === null ? aktif !== null : k.lingkungan === aktif)
  }
  function kredensial(k: SistemIntegrasi): KredensialLingkungan[] {
    const s = sistem.get(k)!
    return LINGKUNGAN.map((l) => {
      const kolom = KOLOM[k].map(([nama, rahasia]) => {
        const v = s.nilai[l][nama]
        return { nama, rahasia, wajib: true, terisi: !!v, nilai: !rahasia && v ? v : null, akhir: rahasia && v && v.length >= 16 ? v.slice(-4) : null }
      })
      return { lingkungan: l, aktif: s.aktif === l, lengkap: kolom.every((x) => x.terisi), kolom, diubah_pada: s.diubah[l]?.pada ?? null, diubah_oleh: s.diubah[l]?.oleh ?? null }
    })
  }
  function status(k: SistemIntegrasi, denganKredensial: boolean): StatusSistemIntegrasi {
    const s = sistem.get(k)!
    const milik = kiriman.filter((x) => x.tujuan === k)
    const n = (st: StatusKiriman) => milik.filter((x) => x.status === st).length
    const menunggu = milik.filter((x) => x.status === 'menunggu').map((x) => x.dibuat_pada).sort()
    const peringatan = s.aktif === null ? [{ kode: 'integrasi_nonaktif', pesan: 'Belum ada lingkungan aktif; kiriman baru tertahan.' }] : []
    return {
      kode: k, nama: SISTEM.find(([x]) => x === k)![1], lingkungan_aktif: s.aktif, kredensial: denganKredensial ? kredensial(k) : null, peringatan,
      circuit_breaker: s.cb,
      queue: { menunggu: n('menunggu'), tertahan: n('tertahan'), gagal: n('gagal'), terkirim_24_jam: n('terkirim'), menunggu_tertua_pada: menunggu[0] ?? null },
      error_terakhir: s.error, uji_koneksi_terakhir: s.uji,
    }
  }
  const halaman = <T>(xs: T[], q: URLSearchParams, maks: number): ResponsTiruan => {
    const batas = Number(q.get('batas') ?? 50)
    if (batas > maks) return error(422, 'ukuran_halaman_melebihi_batas', `Batas paling banyak ${maks}.`)
    const mulai = Number(q.get('kursor') ?? 0)
    const isi = xs.slice(mulai, mulai + batas)
    return ok({ data: isi, meta: { kursor_berikutnya: mulai + batas < xs.length ? String(mulai + batas) : null } })
  }
  const dalamRentang = (pada: string, q: URLSearchParams) => {
    const tgl = pada.slice(0, 10)
    return (!q.get('dari') || tgl >= q.get('dari')!) && (!q.get('sampai') || tgl <= q.get('sampai')!)
  }

  function tangani(r: PermintaanTiruan): ResponsTiruan | Promise<ResponsTiruan> | null {
    const [a0, a1, a2, a3] = r.jalur.replace(/\/+$/, '').split('/').filter(Boolean)
    if (a0 !== 'integrasi') return null
    const m = r.metode.toUpperCase()
    const q = r.kueri
    const tolak = () => error(403, 'akses_ditolak', 'Anda tidak memiliki izin.')

    if (a1 === 'sistem' && !a2 && m === 'GET') {
      const konfigurasi = opsi.boleh('pengaturan.integrasi.lihat')
      const daftar = SISTEM.map(([k]) => k).filter((k) => konfigurasi || opsi.boleh(`${izinTujuan(k)}.lihat`))
      if (!daftar.length) return tolak()
      return ok({ data: daftar.map((k) => status(k, konfigurasi)) })
    }
    if (a1 === 'sistem' && a2 && a3) {
      const k = a2 as SistemIntegrasi
      if (!sistem.has(k)) return error(404, 'tidak_ditemukan', 'Sistem tidak dikenal.')
      if (!opsi.boleh('pengaturan.integrasi.perbaiki')) return tolak()
      const s = sistem.get(k)!
      const isi = (r.isi ?? {}) as { lingkungan?: LingkunganIntegrasi; kolom?: Record<string, string>; aktifkan?: boolean; nonaktifkan?: boolean }
      if (a3 === 'kredensial' && m === 'PUT') {
        const l = isi.lingkungan
        if (!l || !LINGKUNGAN.includes(l)) return error(422, 'validasi_gagal', 'Data tidak valid.', [{ kolom: 'lingkungan', pesan: 'Pilih lingkungan.' }])
        if (isi.aktifkan && isi.nonaktifkan) return error(422, 'validasi_gagal', 'Data tidak valid.', [{ kolom: 'aktifkan', pesan: 'Pilih aktifkan atau nonaktifkan.' }])
        const salah = Object.entries(isi.kolom ?? {}).flatMap(([nama, v]) =>
          !KOLOM[k].some(([n]) => n === nama) ? [{ kolom: `kolom.${nama}`, pesan: 'Kolom tidak dikenal.' }]
            : !v || v.length > 500 || v !== v.trim() ? [{ kolom: `kolom.${nama}`, pesan: 'Isi 1–500 karakter tanpa spasi di awal/akhir.' }] : [],
        )
        if (salah.length) return error(422, 'validasi_gagal', 'Data tidak valid.', salah)
        const baru = { ...s.nilai[l], ...(isi.kolom ?? {}) }
        const kosong = KOLOM[k].filter(([n]) => !baru[n]).map(([n]) => ({ kolom: `kolom.${n}`, pesan: 'Wajib diisi sebelum diaktifkan.' }))
        if (isi.aktifkan && kosong.length) return error(422, 'kredensial_belum_lengkap', 'Kredensial lingkungan ini belum lengkap.', kosong)
        s.nilai[l] = baru
        if (isi.kolom && Object.keys(isi.kolom).length) s.diubah[l] = { pada: iso(), oleh: opsi.pelaku() }
        if (isi.aktifkan && s.aktif !== l) {
          for (const x of kiriman) if (x.tujuan === k && x.lingkungan === s.aktif && (x.status === 'menunggu' || x.status === 'gagal')) Object.assign(x, { status: 'tertahan', penyebab_tertahan: { kode: 'lingkungan_berubah', pesan: 'Lingkungan aktif berubah.' } })
          s.aktif = l
          s.cb = { status: 'tertutup', gagal_beruntun: 0, sejak: null, coba_lagi_pada: null }
        }
        if (isi.nonaktifkan) s.aktif = null
        return ok({ data: status(k, true) })
      }
      if (a3 === 'uji-koneksi' && m === 'POST') {
        const l = isi.lingkungan ?? s.aktif
        if (!l) return error(422, 'lingkungan_belum_aktif', 'Belum ada lingkungan aktif; pilih lingkungan yang diuji.')
        const kosong = KOLOM[k].filter(([n]) => !s.nilai[l][n]).map(([n]) => ({ kolom: `kolom.${n}`, pesan: 'Belum diisi.' }))
        if (kosong.length) return error(422, 'kredensial_belum_lengkap', 'Kredensial lingkungan ini belum lengkap.', kosong)
        const rahasia = KOLOM[k].find(([, x]) => x)![0]
        const berhasil = !s.nilai[l][rahasia]!.startsWith('salah')
        s.uji = berhasil
          ? { lingkungan: l, berhasil, kode_http: 200, latensi_ms: 380, pesan: k === 'satusehat' ? 'Token OAuth2 diterima.' : 'Referensi diterima.', pada: iso() }
          : { lingkungan: l, berhasil, kode_http: 401, latensi_ms: 210, pesan: 'Kredensial ditolak server tujuan (HTTP 401).', pada: iso() }
        catatLog({ tujuan: k, lingkungan: l, metode: k === 'satusehat' ? 'POST' : 'GET', endpoint: k === 'satusehat' ? '/oauth2/v1/accesstoken' : '/referensi/propinsi', kode_http: s.uji.kode_http, berhasil, latensi_ms: s.uji.latensi_ms, pesan: berhasil ? null : s.uji.pesan, kotak_keluar_id: null, permintaan: null, respons: berhasil ? '{"status":"***"}' : '{"error":"***"}' })
        return ok({ data: s.uji })
      }
    }
    if (a1 === 'kotak-keluar' && !a2 && m === 'GET') {
      const tujuan = q.get('tujuan') as SistemIntegrasi | null
      if (tujuan && !opsi.boleh(`${izinTujuan(tujuan)}.lihat`)) return tolak()
      const boleh = SISTEM.map(([k]) => k).filter((k) => opsi.boleh(`${izinTujuan(k)}.lihat`))
      if (!boleh.length) return tolak()
      if (q.get('dari') && q.get('sampai') && q.get('dari')! > q.get('sampai')!) return error(422, 'validasi_gagal', 'Data tidak valid.', [{ kolom: 'sampai', pesan: 'Tidak boleh sebelum tanggal awal.' }])
      const daftar = kiriman
        .filter((x) => boleh.includes(x.tujuan) && (!tujuan || x.tujuan === tujuan) && (!q.get('status') || x.status === q.get('status')) && (!q.get('lingkungan') || x.lingkungan === q.get('lingkungan')) && dalamRentang(x.dibuat_pada, q))
        .sort((a, b) => b.dibuat_pada.localeCompare(a.dibuat_pada))
        .map((x) => ({ ...x, dapat_kirim_ulang: hitungDapat(x) }))
      return halaman(daftar, q, 200)
    }
    if (a1 === 'kotak-keluar' && a2 && a3 === 'kirim-ulang' && m === 'POST') {
      const k = kiriman.find((x) => x.id === a2)
      if (!k || !opsi.boleh(`${izinTujuan(k.tujuan)}.lihat`)) return error(404, 'tidak_ditemukan', 'Kiriman tidak ditemukan.')
      if (!opsi.boleh(`${izinTujuan(k.tujuan)}.perbaiki`)) return tolak()
      if (k.status === 'menunggu' || k.status === 'terkirim') return error(409, 'kiriman_tidak_bisa_dikirim_ulang', 'Kiriman ini sedang menunggu atau sudah terkirim.')
      const aktif = sistem.get(k.tujuan)!.aktif
      if (k.lingkungan === null ? aktif === null : k.lingkungan !== aktif) return error(409, 'lingkungan_tidak_aktif', 'Lingkungan kiriman bukan lingkungan aktif sistem ini.')
      Object.assign(k, { status: 'menunggu', lingkungan: aktif, percobaan: 0, coba_lagi_pada: iso(), penyebab_tertahan: null, error_terakhir: null })
      return ok({ data: { ...k, dapat_kirim_ulang: hitungDapat(k) } })
    }
    if (a1 === 'log' && !a2 && m === 'GET') {
      if (!opsi.boleh('integrasi.log.lihat')) return tolak()
      const berhasil = q.get('berhasil')
      const daftar = log.filter((x) => (!q.get('tujuan') || x.tujuan === q.get('tujuan')) && (berhasil === null || String(x.berhasil) === berhasil) && (!q.get('kotak_keluar_id') || x.kotak_keluar_id === q.get('kotak_keluar_id')) && dalamRentang(x.terjadi_pada, q))
      return halaman(daftar, q, 50)
    }
    return null
  }

  return { tangani, sistem, kiriman }
}
