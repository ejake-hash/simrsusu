import type { PermintaanTiruan, ResponsTiruan } from '../../akses/tiruan/serverTiruan'
import type { ServerTarif } from '../../tarif/tiruan/serverTarif'
import type { ButirUsulan, IsianButir, ObjekDataInduk, TahapUsulan, TampilanIsian, UsulanDataInduk, VersiDataInduk } from '../tipe'

/**
 * Server tiruan kontrak v0.9.0 (tiket 1.8c): /usulan-data-induk (+ verifikasi, setujui, kembalikan, kirim-ulang) dan
 * /data-induk/{jenis}/{id}/riwayat-versi. Alur tahap dan aturan tiga orang berbeda mengikuti kontrak; penerapan
 * hanya mengubah tahap dan mencatat versi (data lokasi tiruan tidak diubah). Dev dan unit test; nama sintetis.
 */
const error = (status: number, kode: string, pesan: string, rincian: Record<string, unknown>[] = []): ResponsTiruan => ({ status, isi: { error: { kode, pesan, rincian } } })
const ok = (isi: unknown, status = 200): ResponsTiruan => ({ status, isi })
const id = (n: number) => `00000000-0000-7000-d000-${n.toString(16).padStart(12, '0')}`
type Isi = Record<string, unknown>
type Pelaku = { id: string; nama: string } | null

const LABEL_ISIAN: Record<string, string> = {
  kamar_id: 'Kamar', kode: 'Kode', kelas_rawat_id: 'Kelas rawat', aturan_jenis_kelamin: 'Aturan jenis kelamin', isolasi: 'Isolasi', ekstra: 'Ekstra',
  alasan: 'Alasan penutupan', bangsal_id: 'Bangsal', lokasi_id: 'Ruang', kapasitas_maksimum: 'Kapasitas maksimum', atribut_standar: 'Atribut standar',
  induk_id: 'Induk', nama: 'Nama', fungsi: 'Fungsi', unit_organisasi_id: 'Unit pengelola', status: 'Status',
}
const ISIAN_BOLEH: Record<string, string[]> = {
  'tempat_tidur.tambah': ['kamar_id', 'kode', 'kelas_rawat_id', 'aturan_jenis_kelamin', 'isolasi', 'ekstra'],
  'tempat_tidur.ubah': ['kelas_rawat_id', 'aturan_jenis_kelamin', 'isolasi', 'ekstra'],
  'tempat_tidur.tutup': ['alasan'],
  'kamar.tambah': ['bangsal_id', 'lokasi_id', 'kapasitas_maksimum', 'atribut_standar'],
  'kamar.ubah': ['bangsal_id', 'kapasitas_maksimum', 'atribut_standar'],
  'ruang.tambah': ['induk_id', 'kode', 'nama', 'fungsi', 'unit_organisasi_id'],
  'ruang.ubah': ['induk_id', 'kode', 'nama', 'fungsi', 'unit_organisasi_id', 'status'],
}
const TERBUKA: TahapUsulan[] = ['diusulkan', 'diverifikasi', 'disetujui', 'dikembalikan']

export interface OpsiServerUsulan {
  penggunaSaatIni: () => string
  namaAkun: (id: string) => string | null
  boleh: (kode: string) => boolean
  potret: (jenis: ObjekDataInduk, id: string) => { label: string; nilai: Record<string, unknown> } | null
  labelNilai: (isian: string, nilai: unknown) => string | null
  /** Akun sudah menandatangani isi usulan saat ini (server tiruan TTE). */
  sudahTtd?: (objekId: string, akunId: string) => boolean
  /** Jenis data `tarif` (2.2): butir dibentuk dan diterapkan oleh server tarif tiruan. */
  tarif?: ServerTarif
  contoh: { kamarA: string; kamarB: string; ruangB: string; kelas: string }
  pengusulLain: string
  sekarang?: () => Date
}

interface UsulanT {
  id: string
  nomor: string
  ringkasan: string
  perubahan: ButirUsulan[]
  berlaku_dari: string
  alasan: string
  dasar: string
  tahap: TahapUsulan
  putaran: number
  pengusul: string
  diusulkan_pada: string
  pemverifikasi: string | null
  penyetuju: string | null
  gagal_alasan: string | null
  riwayat: UsulanDataInduk['riwayat']
}

export function buatServerUsulan(opsi: OpsiServerUsulan) {
  const sekarang = opsi.sekarang ?? (() => new Date())
  const iso = () => sekarang().toISOString()
  const hariIni = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit' }).format(sekarang())
  let nomor = 400
  const usulan: UsulanT[] = []
  const versi: VersiDataInduk[] = []
  const pelaku = (akunId: string | null): Pelaku => (akunId ? { id: akunId, nama: opsi.namaAkun(akunId) ?? 'Akun' } : null)

  function bentukButir(b: IsianButir): ButirUsulan | { salah: { kode: string; pesan: string } } {
    if (b.objek_jenis === 'tarif') return opsi.tarif ? opsi.tarif.bentukButir(b) : { salah: { kode: 'aksi_tidak_dikenal', pesan: 'Jenis data tarif tidak tersedia.' } }
    const kunci = `${b.objek_jenis}.${b.aksi}`
    const boleh = ISIAN_BOLEH[kunci]
    if (!boleh) return { salah: { kode: 'aksi_tidak_dikenal', pesan: `Aksi ${kunci} tidak dikenal.` } }
    const nilaiBaru = Object.fromEntries(Object.entries(b.nilai_baru ?? {}).filter(([k]) => boleh.includes(k)))
    if (b.aksi === 'tambah') {
      if (b.objek_id !== null) return { salah: { kode: 'aksi_tidak_dikenal', pesan: 'objek_id harus null untuk tambah.' } }
      const label = b.objek_jenis === 'tempat_tidur' ? `TT baru ${String(nilaiBaru.kode ?? '')} di ${opsi.labelNilai('kamar_id', nilaiBaru.kamar_id) ?? '?'}` : b.objek_jenis === 'kamar' ? `Kamar baru di ${opsi.labelNilai('lokasi_id', nilaiBaru.lokasi_id) ?? '?'}` : `Ruang baru ${String(nilaiBaru.nama ?? '')}`
      const tampilan: TampilanIsian[] = Object.entries(nilaiBaru).map(([k, v]) => ({ isian: k, label_isian: LABEL_ISIAN[k] ?? k, lama: null, baru: opsi.labelNilai(k, v) }))
      return { ...b, objek_id: null, nilai_baru: nilaiBaru, objek_label: label, objek_berubah: false, nilai_lama: null, tampilan }
    }
    const p = b.objek_id ? opsi.potret(b.objek_jenis, b.objek_id) : null
    if (!p) return { salah: { kode: 'objek_tidak_ditemukan', pesan: 'Objek tidak ditemukan.' } }
    if (b.aksi === 'ubah' && !Object.keys(nilaiBaru).length) return { salah: { kode: 'validasi_gagal', pesan: 'Ubah minimal satu isian.' } }
    const nilaiLama = Object.fromEntries(Object.keys(nilaiBaru).filter((k) => k in p.nilai).map((k) => [k, p.nilai[k]]))
    const tampilan: TampilanIsian[] =
      b.aksi === 'tutup'
        ? [{ isian: 'status', label_isian: 'Status', lama: 'Aktif', baru: `Ditutup (${String(nilaiBaru.alasan ?? '')})` }]
        : Object.entries(nilaiBaru).map(([k, v]) => ({ isian: k, label_isian: LABEL_ISIAN[k] ?? k, lama: opsi.labelNilai(k, p.nilai[k]), baru: opsi.labelNilai(k, v) }))
    return { ...b, nilai_baru: nilaiBaru, objek_label: p.label, objek_berubah: false, nilai_lama: nilaiLama, tampilan }
  }
  const ringkas = (butir: ButirUsulan[]) => (butir.length === 1 ? `${butir[0]!.objek_label}: ${butir[0]!.tampilan.map((t) => `${t.label_isian} ${t.lama ?? '—'} → ${t.baru ?? '—'}`).join('; ')}` : `${butir.length} perubahan: ${butir.map((b) => b.objek_label).join(', ')}`)

  function bentuk(u: UsulanT): UsulanDataInduk {
    const saya = opsi.penggunaSaatIni()
    const pihak = [u.pengusul, u.pemverifikasi, u.penyetuju].filter(Boolean) as string[]
    return {
      id: u.id, nomor: u.nomor, jenis_data: u.perubahan[0]?.objek_jenis === 'tarif' ? 'tarif' : 'ruang_tt', ringkasan: u.ringkasan, perubahan: u.perubahan, berlaku_dari: u.berlaku_dari, alasan: u.alasan, dasar: u.dasar,
      tahap: u.tahap, putaran: u.putaran, diusulkan_oleh: pelaku(u.pengusul), diusulkan_pada: u.diusulkan_pada, diverifikasi_oleh: pelaku(u.pemverifikasi),
      disetujui_oleh: pelaku(u.penyetuju), gagal_alasan: u.gagal_alasan, riwayat: u.riwayat,
      dapat: {
        verifikasi: u.tahap === 'diusulkan' && saya !== u.pengusul && opsi.boleh('master_data.usulan.verifikasi'),
        setujui: u.tahap === 'diverifikasi' && saya !== u.pengusul && saya !== u.pemverifikasi && opsi.boleh('master_data.usulan.setujui'),
        kembalikan: saya !== u.pengusul && ((u.tahap === 'diusulkan' && opsi.boleh('master_data.usulan.verifikasi')) || (u.tahap === 'diverifikasi' && saya !== u.pemverifikasi && opsi.boleh('master_data.usulan.setujui'))),
        kirim_ulang: u.tahap === 'dikembalikan' && saya === u.pengusul && opsi.boleh('master_data.usulan.input'),
        tandatangani: pihak.includes(saya) && !(opsi.sudahTtd?.(u.id, saya) ?? false),
      },
    }
  }
  const catat = (u: UsulanT, aksi: UsulanDataInduk['riwayat'][number]['aksi'], oleh: string | null, catatan: string | null = null) => u.riwayat.push({ aksi, oleh: pelaku(oleh), pada: iso(), catatan, putaran: u.putaran })
  function terapkan(u: UsulanT) {
    u.tahap = 'berlaku'
    catat(u, 'diterapkan', null)
    for (const b of u.perubahan) {
      if (!b.objek_id || b.objek_jenis === 'tarif') continue
      const n = versi.filter((v) => v.objek_id === b.objek_id).length + 1
      versi.push({
        id: id(++nomor), versi: n, objek_jenis: b.objek_jenis, objek_id: b.objek_id, berlaku_dari: u.berlaku_dari, sebelum: b.nilai_lama, sesudah: b.nilai_baru,
        tampilan: b.tampilan, usulan: { id: u.id, nomor: u.nomor }, dasar: `${u.nomor}; ${u.dasar}`, diubah_oleh: pelaku(u.penyetuju), diubah_pada: iso(),
      })
    }
  }
  /** Validasi isian usulan; mengembalikan butir terbentuk atau respons galat. */
  function periksa(isi: Isi, kecuali: string | null): ButirUsulan[] | ResponsTiruan {
    const tgl = String(isi.berlaku_dari ?? '')
    if (!/^\d{4}-\d{2}-\d{2}$/.test(tgl) || tgl < hariIni()) return error(422, 'tanggal_tidak_valid', 'Tanggal berlaku tidak boleh sebelum hari ini.', [{ kolom: 'berlaku_dari', pesan: 'Pilih hari ini atau sesudahnya.' }])
    if (typeof isi.alasan !== 'string' || isi.alasan.trim().length < 5) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'alasan', pesan: 'Isi alasan, minimal 5 karakter.' }])
    if (typeof isi.dasar !== 'string' || isi.dasar.trim().length < 3) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'dasar', pesan: 'Isi dasar, minimal 3 karakter.' }])
    const daftar = Array.isArray(isi.perubahan) ? (isi.perubahan as IsianButir[]) : []
    if (!daftar.length || daftar.length > 50) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'perubahan', pesan: '1–50 perubahan.' }])
    const butir: ButirUsulan[] = []
    const salah: Record<string, unknown>[] = []
    daftar.forEach((b, i) => {
      const h = bentukButir(b)
      if ('salah' in h) salah.push({ kolom: `perubahan.${i}`, ...h.salah })
      else butir.push(h)
    })
    if (salah.length) return error(422, 'perubahan_tidak_valid', 'Ada perubahan yang tidak valid.', salah)
    if (new Set(butir.map((b) => (b.objek_jenis === 'tarif' ? 'tarif' : 'ruang_tt'))).size > 1) return error(422, 'perubahan_tidak_valid', 'Satu usulan hanya memuat satu jenis data.', [{ kolom: 'perubahan', kode: 'jenis_campur', pesan: 'Pisahkan perubahan tarif dari ruang dan tempat tidur.' }])
    const bentrok = butir.flatMap((b, i) => {
      const lain = usulan.find((u) => u.id !== kecuali && ['diusulkan', 'diverifikasi', 'disetujui'].includes(u.tahap) && u.perubahan.some((x) => x.objek_id && x.objek_id === b.objek_id))
      return lain ? [{ kolom: `perubahan.${i}`, kode: 'objek_sedang_diusulkan', pesan: `${b.objek_label} sedang diusulkan di ${lain.nomor}.`, usulan: { id: lain.id, nomor: lain.nomor } }] : []
    })
    if (bentrok.length) return error(409, 'objek_sedang_diusulkan', 'Objek yang sama ada di usulan lain yang belum final.', bentrok)
    return butir
  }
  const salah = (v: unknown): v is ResponsTiruan => !Array.isArray(v)

  // ── Data awal sintetis ──
  function benih(pengusul: string, butir: IsianButir[], isi: Partial<UsulanT>, tahap: TahapUsulan) {
    const terbentuk = butir.map((b) => bentukButir(b)).filter((b): b is ButirUsulan => !('salah' in b))
    const u: UsulanT = {
      id: id(++nomor), nomor: `UDI-2026-${String(nomor).padStart(6, '0')}`, ringkasan: ringkas(terbentuk), perubahan: terbentuk, berlaku_dari: hariIni(),
      alasan: 'Penyesuaian kapasitas (sintetis)', dasar: 'Memo Bidang Keperawatan (sintetis)', tahap: 'diusulkan', putaran: 1, pengusul,
      diusulkan_pada: new Date(sekarang().getTime() - nomor * 60_000).toISOString(), pemverifikasi: null, penyetuju: null, gagal_alasan: null, riwayat: [], ...isi,
    }
    u.riwayat.push({ aksi: 'diajukan', oleh: pelaku(pengusul), pada: u.diusulkan_pada, catatan: null, putaran: 1 })
    u.tahap = tahap
    usulan.push(u)
    return u
  }
  const { kamarA, kamarB, ruangB, kelas } = opsi.contoh
  const besok = () => {
    const d = new Date(`${hariIni()}T00:00:00Z`)
    d.setUTCDate(d.getUTCDate() + 1)
    return d.toISOString().slice(0, 10)
  }
  benih(opsi.pengusulLain, [{ objek_jenis: 'kamar', aksi: 'ubah', objek_id: kamarA, nilai_baru: { kapasitas_maksimum: 3 } }], { berlaku_dari: besok() }, 'diusulkan')
  const u2 = benih(opsi.pengusulLain, [{ objek_jenis: 'tempat_tidur', aksi: 'tambah', objek_id: null, nilai_baru: { kamar_id: kamarB, kode: '3', kelas_rawat_id: kelas, aturan_jenis_kelamin: 'campur', isolasi: false, ekstra: false } }], { berlaku_dari: besok() }, 'diverifikasi')
  u2.pemverifikasi = 'pemverifikasi-lain'
  u2.riwayat.push({ aksi: 'diverifikasi', oleh: { id: 'pemverifikasi-lain', nama: 'Contoh Kepala Keperawatan' }, pada: iso(), catatan: 'Sesuai kebutuhan bangsal', putaran: 1 })
  const u3 = benih(opsi.penggunaSaatIni(), [{ objek_jenis: 'ruang', aksi: 'ubah', objek_id: ruangB, nilai_baru: { nama: 'Ruang Melati 102 (renovasi)' } }], { berlaku_dari: besok() }, 'dikembalikan')
  u3.riwayat.push({ aksi: 'dikembalikan', oleh: pelaku(opsi.pengusulLain), pada: iso(), catatan: 'Lampirkan memo renovasi dari IPSRS.', putaran: 1 })

  // Usulan tarif terverifikasi (2.2): menunggu penyetuju; tanggal berlaku besok agar harga lama tetap berlaku hari ini.
  if (opsi.tarif) {
    const t = opsi.tarif.contoh.tindakan
    const harga = t.versi[t.versi.length - 1]!.isi.harga.map((h, i) => ({ ...h, jasa_sarana: String(80000 + i * 25000) }))
    const u4 = benih(opsi.pengusulLain, [{ objek_jenis: 'tarif', aksi: 'ubah', objek_id: t.id, nilai_baru: { harga } }], { berlaku_dari: besok(), alasan: 'Penyesuaian tarif tahunan (sintetis)', dasar: 'SK Direktur Contoh (sintetis)' }, 'diverifikasi')
    u4.pemverifikasi = 'pemverifikasi-lain'
    u4.riwayat.push({ aksi: 'diverifikasi', oleh: { id: 'pemverifikasi-lain', nama: 'Contoh Kepala Keuangan' }, pada: iso(), catatan: 'Sesuai SK', putaran: 1 })
  }

  function tangani(r: PermintaanTiruan): ResponsTiruan | null {
    const m = r.metode.toUpperCase()
    const isi = (r.isi ?? {}) as Isi
    const [a0, a1, a2, a3, a4] = r.jalur.replace(/\/+$/, '').split('/').filter(Boolean)
    const saya = opsi.penggunaSaatIni()
    const tolak = () => error(403, 'akses_ditolak', 'Anda tidak punya akses untuk tindakan ini.')

    if (a0 === 'data-induk' && a3 === 'riwayat-versi' && m === 'GET' && a1 && a2) {
      if (!opsi.boleh('master_data.organisasi.lihat')) return tolak()
      return ok({ data: versi.filter((v) => v.objek_jenis === a1 && v.objek_id === a2).sort((x, y) => y.versi - x.versi), meta: { kursor_berikutnya: null } })
    }
    if (a0 !== 'usulan-data-induk') return null
    if (!opsi.boleh('master_data.usulan.lihat')) return tolak()

    if (!a1) {
      if (m === 'GET') {
        const tahap = r.kueri.get('tahap')
        const perluSaya = r.kueri.get('perlu_saya') === 'true'
        const daftar = usulan
          .filter((u) => !tahap || (tahap === 'terbuka' ? TERBUKA.includes(u.tahap) : u.tahap === tahap))
          .filter((u) => !perluSaya || Object.entries(bentuk(u).dapat).some(([k, v]) => k !== 'tandatangani' && v))
          .sort((x, y) => y.diusulkan_pada.localeCompare(x.diusulkan_pada))
          .map(bentuk)
        const n = (t: TahapUsulan) => usulan.filter((u) => u.tahap === t).length
        return ok({ data: daftar, meta: { kursor_berikutnya: null, jumlah: { diusulkan: n('diusulkan'), diverifikasi: n('diverifikasi'), disetujui: n('disetujui'), dikembalikan: n('dikembalikan'), gagal_diterapkan: n('gagal_diterapkan') } } })
      }
      if (m === 'POST') {
        if (!opsi.boleh('master_data.usulan.input')) return tolak()
        const butir = periksa(isi, null)
        if (salah(butir)) return butir
        const u: UsulanT = {
          id: id(++nomor), nomor: `UDI-2026-${String(nomor).padStart(6, '0')}`, ringkasan: ringkas(butir), perubahan: butir, berlaku_dari: String(isi.berlaku_dari),
          alasan: String(isi.alasan).trim(), dasar: String(isi.dasar).trim(), tahap: 'diusulkan', putaran: 1, pengusul: saya, diusulkan_pada: iso(),
          pemverifikasi: null, penyetuju: null, gagal_alasan: null, riwayat: [],
        }
        catat(u, 'diajukan', saya)
        usulan.push(u)
        return ok({ data: bentuk(u) }, 201)
      }
      return null
    }
    const u = usulan.find((x) => x.id === a1)
    if (!u) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
    void a4
    if (m === 'GET' && !a2) return ok({ data: bentuk(u) })
    if (m !== 'POST') return null
    const catatan = typeof isi.catatan === 'string' && isi.catatan.trim() ? isi.catatan.trim() : null
    if (a2 === 'verifikasi') {
      if (!opsi.boleh('master_data.usulan.verifikasi')) return tolak()
      if (u.pengusul === saya) return error(403, 'pengusul_sendiri', 'Pengusul tidak memverifikasi usulannya sendiri.')
      if (u.tahap !== 'diusulkan') return error(409, 'tahap_tidak_sesuai', 'Usulan ini tidak lagi menunggu verifikasi.')
      u.tahap = 'diverifikasi'
      u.pemverifikasi = saya
      catat(u, 'diverifikasi', saya, catatan)
      return ok({ data: bentuk(u) })
    }
    if (a2 === 'setujui') {
      if (!opsi.boleh('master_data.usulan.setujui')) return tolak()
      if (u.pengusul === saya) return error(403, 'pengusul_sendiri', 'Pengusul tidak menyetujui usulannya sendiri.')
      if (u.pemverifikasi === saya) return error(403, 'sudah_memverifikasi', 'Pemverifikasi tidak menyetujui usulan yang ia verifikasi.')
      if (u.tahap !== 'diverifikasi') return error(409, 'tahap_tidak_sesuai', 'Usulan ini tidak lagi menunggu persetujuan.')
      u.tahap = 'disetujui'
      u.penyetuju = saya
      catat(u, 'disetujui', saya, catatan)
      // Tarif berversi per tanggal: versi terjadwal langsung tercatat dan baru tampil sebagai harga berlaku pada tanggalnya.
      for (const b of u.perubahan) if (b.objek_jenis === 'tarif') opsi.tarif?.terapkan(b, u.berlaku_dari, { id: u.id, nomor: u.nomor }, `${u.nomor}; ${u.dasar}`, opsi.namaAkun(saya) ?? 'Akun')
      if (u.berlaku_dari <= hariIni()) terapkan(u)
      return ok({ data: bentuk(u) })
    }
    if (a2 === 'kembalikan') {
      if (u.pengusul === saya) return error(403, 'pengusul_sendiri', 'Pengusul tidak mengembalikan usulannya sendiri.')
      if (u.tahap === 'diusulkan' ? !opsi.boleh('master_data.usulan.verifikasi') : u.tahap === 'diverifikasi' ? !opsi.boleh('master_data.usulan.setujui') : false) return tolak()
      if (u.tahap !== 'diusulkan' && u.tahap !== 'diverifikasi') return error(409, 'tahap_tidak_sesuai', 'Usulan ini tidak bisa dikembalikan pada tahap ini.')
      if (!catatan || catatan.length < 5) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'catatan', pesan: 'Tulis catatan pengembalian, minimal 5 karakter.' }])
      u.tahap = 'dikembalikan'
      catat(u, 'dikembalikan', saya, catatan)
      return ok({ data: bentuk(u) })
    }
    if (a2 === 'kirim-ulang') {
      if (u.pengusul !== saya || !opsi.boleh('master_data.usulan.input')) return error(403, 'bukan_pengusul', 'Hanya pengusul yang mengirim ulang.')
      if (u.tahap !== 'dikembalikan') return error(409, 'tahap_tidak_sesuai', 'Usulan ini tidak sedang dikembalikan.')
      const butir = periksa(isi, u.id)
      if (salah(butir)) return butir
      Object.assign(u, { perubahan: butir, ringkasan: ringkas(butir), berlaku_dari: String(isi.berlaku_dari), alasan: String(isi.alasan).trim(), dasar: String(isi.dasar).trim(), tahap: 'diusulkan', pemverifikasi: null, penyetuju: null })
      u.putaran++
      catat(u, 'dikirim_ulang', saya)
      return ok({ data: bentuk(u) })
    }
    return null
  }

  /** Keadaan usulan untuk TTE (pihak putaran ini). */
  function usulanUntukTtd(usulanId: string) {
    const u = usulan.find((x) => x.id === usulanId)
    return u ? { nomor: u.nomor, tahap: u.tahap, pihak: [u.pengusul, u.pemverifikasi, u.penyetuju].filter(Boolean) as string[], putaran: u.putaran } : null
  }
  /** Usulan yang sedang berjalan untuk satu objek (tahap diusulkan, diverifikasi, disetujui). */
  const usulanTerbuka = (objekId: string) => {
    const u = usulan.find((x) => ['diusulkan', 'diverifikasi', 'disetujui'].includes(x.tahap) && x.perubahan.some((b) => b.objek_id === objekId))
    return u ? { id: u.id, nomor: u.nomor } : null
  }
  return { tangani, usulanUntukTtd, usulanTerbuka }
}
