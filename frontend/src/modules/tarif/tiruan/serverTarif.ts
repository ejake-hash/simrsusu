import type { PermintaanTiruan, ResponsTiruan } from '../../akses/tiruan/serverTiruan'
import type { ButirUsulan, IsianButir, TampilanIsian, VersiDataInduk } from '../../usulan/tipe'
import { jumlahKomponen, periksaHarga } from '../tarif'
import type { HargaTarif, IsianHargaTarif, Tarif } from '../tipe'

/**
 * Server tiruan kontrak v0.18.0 (tiket 2.2): GET /data-induk/tarif dan /data-induk/tarif/{id}/versi. Tidak ada
 * endpoint tulis: usulan bertarif (`jenis_data` tarif) dibentuk dan diterapkan lewat server usulan tiruan yang
 * memanggil `bentukButir`/`jadwalkan`/`terapkan` di sini. Tarif berlaku per tanggal (versi dengan `berlaku_dari`).
 * Nama dan harga sintetis.
 */
const error = (status: number, kode: string, pesan: string, rincian: Record<string, unknown>[] = []): ResponsTiruan => ({ status, isi: { error: { kode, pesan, rincian } } })
const ok = (isi: unknown): ResponsTiruan => ({ status: 200, isi })
const id = (n: number) => `00000000-0000-7000-e000-${n.toString(16).padStart(12, '0')}`
const TANGGAL_RS = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit' })

type Isi = Record<string, unknown>
interface IsiVersi {
  nama: string
  kelompok: string
  status: 'aktif' | 'nonaktif'
  harga: IsianHargaTarif[]
}
interface VersiT {
  berlaku_dari: string
  isi: IsiVersi
  usulan: { id: string; nomor: string } | null
  dasar: string
  oleh: string
  pada: string
}
interface TarifT {
  id: string
  kode: string
  versi: VersiT[]
}

export interface OpsiServerTarif {
  boleh: (kode: string) => boolean
  kelas: () => { id: string; label: string }[]
  usulanTerbuka: (tarifId: string) => { id: string; nomor: string } | null
  sekarang?: () => Date
}

export function buatServerTarif(opsi: OpsiServerTarif) {
  const sekarang = opsi.sekarang ?? (() => new Date())
  const hariIni = () => TANGGAL_RS.format(sekarang())
  let nomor = 700
  const daftar: TarifT[] = []
  const labelKelas = (kid: string) => opsi.kelas().find((k) => k.id === kid)?.label ?? kid

  const harga = (h: IsianHargaTarif[]): HargaTarif[] =>
    h.map((x) => ({ ...x, kelas_rawat_label: labelKelas(x.kelas_rawat_id), total: jumlahKomponen(x) ?? '0' })).sort((a, b) => a.kelas_rawat_label.localeCompare(b.kelas_rawat_label))
  const versiBerlaku = (t: TarifT, tgl: string) => [...t.versi].filter((v) => v.berlaku_dari <= tgl).sort((a, b) => b.berlaku_dari.localeCompare(a.berlaku_dari))[0] ?? null
  const bentuk = (t: TarifT, tgl: string, kelasId: string | null): Tarif | null => {
    const v = versiBerlaku(t, tgl)
    if (!v) return null
    const depan = [...t.versi].filter((x) => x.berlaku_dari > tgl).sort((a, b) => a.berlaku_dari.localeCompare(b.berlaku_dari))[0]
    const saring = (h: IsianHargaTarif[]) => harga(kelasId ? h.filter((x) => x.kelas_rawat_id === kelasId) : h)
    const terakhir = t.versi[t.versi.length - 1]!
    return {
      id: t.id, kode: t.kode, nama: v.isi.nama, kelompok: v.isi.kelompok, status: v.isi.status, berlaku_dari: v.berlaku_dari, harga: saring(v.isi.harga),
      akan_berlaku: depan ? { berlaku_dari: depan.berlaku_dari, harga: saring(depan.isi.harga) } : null,
      usulan_terbuka: opsi.boleh('master_data.usulan.lihat') ? opsi.usulanTerbuka(t.id) : null,
      versi: String(t.versi.length), diubah_pada: terakhir.pada,
    }
  }
  function tambah(kode: string, nama: string, kelompok: string, h: IsianHargaTarif[], berlakuDari: string, dasar: string) {
    const t: TarifT = { id: id(++nomor), kode, versi: [] }
    t.versi.push({ berlaku_dari: berlakuDari, isi: { nama, kelompok, status: 'aktif', harga: h }, usulan: null, dasar, oleh: 'Sistem', pada: '2026-10-01T02:00:00.000Z' })
    daftar.push(t)
    return t
  }

  // ── Data awal sintetis ──
  const kelasAwal = opsi.kelas()
  const hargaContoh = (dasarSarana: number): IsianHargaTarif[] =>
    kelasAwal.map((k, i) => ({ kelas_rawat_id: k.id, jasa_sarana: String(dasarSarana + i * 25000), jasa_pelayanan: String(Math.round(dasarSarana / 2)), bhp: '10000.50' }))
  tambah('KON-001', 'Konsultasi dokter spesialis (sintetis)', 'Konsultasi', hargaContoh(100000), '2026-01-01', 'SK Tarif Contoh 2026')
  tambah('LAB-001', 'Pemeriksaan darah rutin (sintetis)', 'Laboratorium', hargaContoh(40000), '2026-01-01', 'SK Tarif Contoh 2026')
  tambah('RAD-001', 'Foto toraks (sintetis)', 'Radiologi', hargaContoh(80000), '2026-01-01', 'SK Tarif Contoh 2026')
  const lama = tambah('TND-001', 'Perawatan luka sedang (sintetis)', 'Tindakan', hargaContoh(60000), '2026-01-01', 'SK Tarif Contoh 2026')
  lama.versi.push({ berlaku_dari: '2026-07-01', isi: { ...lama.versi[0]!.isi, harga: hargaContoh(65000) }, usulan: null, dasar: 'SK Tarif Contoh Juli 2026', oleh: 'Contoh Superadmin', pada: '2026-06-20T02:00:00.000Z' })
  const kode = (c: string) => daftar.find((t) => t.kode === c)!

  // ── Dipanggil server usulan ──
  const LABEL: Record<string, string> = { kode: 'Kode', nama: 'Nama', kelompok: 'Kelompok', status: 'Status', harga: 'Harga per kelas', alasan: 'Alasan penutupan' }
  const ringkasHarga = (h: IsianHargaTarif[] | undefined) => (h ? h.map((x) => `${labelKelas(x.kelas_rawat_id)} ${jumlahKomponen(x) ?? '?'}`).join('; ') : null)
  const nilaiHarga = (t: TarifT) => versiBerlaku(t, '9999-12-31')!.isi
  function bentukButir(b: IsianButir): ButirUsulan | { salah: { kode: string; pesan: string } } {
    const salah = (k: string, pesan: string) => ({ salah: { kode: k, pesan } })
    const n = b.nilai_baru ?? {}
    const hargaBaru = n.harga as IsianHargaTarif[] | undefined
    if (b.aksi === 'tambah' && !hargaBaru?.length) return salah('validasi_gagal', 'Isi harga minimal satu kelas.')
    if (hargaBaru !== undefined) {
      if (!Array.isArray(hargaBaru)) return salah('validasi_gagal', 'Isi harga minimal satu kelas.')
      const g = periksaHarga(hargaBaru)
      if (g) return salah(g.includes('sudah ada') ? 'kelas_ganda' : 'validasi_gagal', g)
    }
    const tampil = (k: string, lamaV: unknown, baruV: unknown): TampilanIsian => ({ isian: k, label_isian: LABEL[k] ?? k, lama: k === 'harga' ? ringkasHarga(lamaV as IsianHargaTarif[] | undefined) : (lamaV as string | null) ?? null, baru: k === 'harga' ? ringkasHarga(baruV as IsianHargaTarif[]) : (baruV as string | null) ?? null })
    if (b.aksi === 'tambah') {
      const kd = String(n.kode ?? '').trim().toUpperCase()
      if (!/^[A-Z0-9-]{1,20}$/.test(kd)) return salah('validasi_gagal', 'Kode tarif 1–20 huruf besar, angka, atau tanda hubung.')
      if (daftar.some((t) => t.kode === kd)) return salah('kode_sudah_dipakai', `Kode ${kd} sudah dipakai.`)
      if (String(n.nama ?? '').trim().length < 2) return salah('validasi_gagal', 'Nama tarif minimal 2 karakter.')
      const nilai = { kode: kd, nama: String(n.nama).trim(), kelompok: String(n.kelompok ?? '').trim(), harga: hargaBaru ?? [] }
      return { ...b, objek_id: null, nilai_baru: nilai, objek_label: `Tarif baru ${kd} · ${nilai.nama}`, objek_berubah: false, nilai_lama: null, tampilan: Object.entries(nilai).map(([k, v]) => tampil(k, null, v)) }
    }
    const t = daftar.find((x) => x.id === b.objek_id)
    if (!t) return salah('objek_tidak_ditemukan', 'Tarif tidak ditemukan.')
    const skrg = nilaiHarga(t)
    const label = `${t.kode} · ${skrg.nama}`
    if (b.aksi === 'tutup') {
      if (typeof n.alasan !== 'string' || n.alasan.trim().length < 5) return salah('validasi_gagal', 'Isi alasan penutupan, minimal 5 karakter.')
      return { ...b, nilai_baru: { alasan: n.alasan.trim() }, objek_label: label, objek_berubah: false, nilai_lama: { status: skrg.status }, tampilan: [{ isian: 'status', label_isian: 'Status', lama: 'Aktif', baru: `Ditutup (${n.alasan.trim()})` }] }
    }
    const nilai = Object.fromEntries(Object.entries(n).filter(([k]) => ['nama', 'kelompok', 'status', 'harga'].includes(k)))
    if (!Object.keys(nilai).length) return salah('validasi_gagal', 'Ubah minimal satu isian.')
    const nilaiLama = Object.fromEntries(Object.keys(nilai).map((k) => [k, skrg[k as keyof IsiVersi]]))
    return { ...b, nilai_baru: nilai, objek_label: label, objek_berubah: false, nilai_lama: nilaiLama, tampilan: Object.keys(nilai).map((k) => tampil(k, skrg[k as keyof IsiVersi], nilai[k])) }
  }
  /** Versi terjadwal (usulan disetujui, belum berlaku) tampil di `akan_berlaku`; versi yang sama diganti bila diterapkan. */
  function terapkan(b: ButirUsulan, berlakuDari: string, usulan: { id: string; nomor: string }, dasar: string, oleh: string) {
    let t = daftar.find((x) => x.id === b.objek_id)
    const pada = sekarang().toISOString()
    if (b.aksi === 'tambah') {
      const n = b.nilai_baru as { kode: string; nama: string; kelompok: string; harga: IsianHargaTarif[] }
      t = tambah(n.kode, n.nama, n.kelompok, n.harga, berlakuDari, dasar)
      t.versi[0]!.usulan = usulan
      t.versi[0]!.oleh = oleh
      t.versi[0]!.pada = pada
      b.objek_id = t.id
      return
    }
    if (!t) return
    const dasarIsi = { ...versiBerlaku(t, '9999-12-31')!.isi }
    const isi: IsiVersi = b.aksi === 'tutup' ? { ...dasarIsi, status: 'nonaktif' } : { ...dasarIsi, ...(b.nilai_baru as Partial<IsiVersi>) }
    t.versi = t.versi.filter((v) => !(v.usulan?.id === usulan.id))
    t.versi.push({ berlaku_dari: berlakuDari, isi, usulan, dasar, oleh, pada })
  }
  const potret = (tarifId: string) => {
    const t = daftar.find((x) => x.id === tarifId)
    return t ? { label: `${t.kode} · ${nilaiHarga(t).nama}`, nilai: nilaiHarga(t) as unknown as Record<string, unknown> } : null
  }

  function tangani(r: PermintaanTiruan): ResponsTiruan | null {
    const [a0, a1, a2, a3] = r.jalur.replace(/\/+$/, '').split('/').filter(Boolean)
    if (a0 !== 'data-induk' || a1 !== 'tarif' || r.metode.toUpperCase() !== 'GET') return null
    if (!opsi.boleh('master_data.tarif.lihat')) return error(403, 'akses_ditolak', 'Anda tidak punya akses untuk tindakan ini.')
    if (!a2) {
      const tgl = r.kueri.get('tanggal') || hariIni()
      if (!/^\d{4}-\d{2}-\d{2}$/.test(tgl)) return error(422, 'validasi_gagal', 'Isian belum benar.', [{ kolom: 'tanggal', pesan: 'Format tanggal tidak valid.' }])
      const cari = (r.kueri.get('cari') ?? '').toLowerCase()
      const status = r.kueri.get('status')
      const kelompok = r.kueri.get('kelompok')
      const kelasId = r.kueri.get('kelas_rawat_id')
      const baris = daftar
        .filter((t) => !cari || t.kode.toLowerCase().includes(cari) || (versiBerlaku(t, tgl)?.isi.nama.toLowerCase().includes(cari) ?? false))
        .map((t) => bentuk(t, tgl, kelasId))
        .filter((t): t is Tarif => !!t && (!status || t.status === status) && (!kelompok || t.kelompok === kelompok) && (!kelasId || t.harga.length > 0))
        .sort((x, y) => x.kode.localeCompare(y.kode))
      return ok({ data: baris, meta: { kursor_berikutnya: null } })
    }
    const t = daftar.find((x) => x.id === a2)
    if (a3 !== 'versi') return null
    if (!t) return error(404, 'tidak_ditemukan', 'Data tidak ditemukan.')
    const semua = [...t.versi].sort((x, y) => x.berlaku_dari.localeCompare(y.berlaku_dari))
    const hasil: VersiDataInduk[] = semua.map((v, i) => {
      const sebelum = i ? semua[i - 1]!.isi : null
      const tampilan: TampilanIsian[] = (['nama', 'kelompok', 'status', 'harga'] as const)
        .filter((k) => JSON.stringify(sebelum?.[k] ?? null) !== JSON.stringify(v.isi[k]))
        .map((k) => ({ isian: k, label_isian: LABEL[k] ?? k, lama: k === 'harga' ? ringkasHarga(sebelum?.harga) : ((sebelum?.[k] as string | undefined) ?? null), baru: k === 'harga' ? ringkasHarga(v.isi.harga) : String(v.isi[k]) }))
      return {
        id: id(1000 + i + t.versi.length), versi: i + 1, objek_jenis: 'tarif', objek_id: t.id, berlaku_dari: v.berlaku_dari, sebelum: sebelum as unknown as Record<string, unknown> | null,
        sesudah: v.isi as unknown as Record<string, unknown>, tampilan, usulan: v.usulan, dasar: v.dasar, diubah_oleh: v.oleh === 'Sistem' ? null : { id: id(1), nama: v.oleh }, diubah_pada: v.pada,
      }
    })
    return ok({ data: hasil.reverse(), meta: { kursor_berikutnya: null } })
  }
  return { tangani, bentukButir, terapkan, potret, contoh: { konsultasi: kode('KON-001'), tindakan: kode('TND-001'), laboratorium: kode('LAB-001') } }
}

export type ServerTarif = ReturnType<typeof buatServerTarif>
