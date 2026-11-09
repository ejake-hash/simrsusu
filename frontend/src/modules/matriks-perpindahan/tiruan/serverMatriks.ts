import type { PermintaanTiruan, ResponsTiruan } from '../../akses/tiruan/serverTiruan'
import type { ButirUsulan, IsianButir, TampilanIsian } from '../../usulan/tipe'
import type { AturanPerpindahan, PosAsalPerpindahan, PosTujuanPerpindahan, StatusAturan } from '../tipe'

/**
 * Server tiruan kontrak v0.33.0 (tiket 2.26): GET /data-induk/matriks-perpindahan. Tidak ada endpoint tulis: usulan
 * `jenis_data` matriks_perpindahan dibentuk dan diterapkan lewat server usulan tiruan yang memanggil
 * `bentukButir`/`terapkan` di sini. Isi awal meniru isi migrasi (asal IGD + dua sel poliklinik); dev dan unit test saja.
 */
const error = (status: number, kode: string, pesan: string, rincian: Record<string, unknown>[] = []): ResponsTiruan => ({ status, isi: { error: { kode, pesan, rincian } } })
const id = (n: number) => `00000000-0000-7000-f000-${n.toString(16).padStart(12, '0')}`
const LABEL: Record<string, string> = {
  pendaftaran: 'Pendaftaran', poliklinik: 'Poliklinik', igd: 'IGD', penunjang: 'Penunjang', kamar_operasi: 'Kamar operasi', icu_hcu: 'ICU/HCU',
  rawat_inap: 'Rawat inap', farmasi: 'Farmasi', kasir: 'Kasir', poli_lain: 'Poli lain', rujuk_keluar: 'Rujuk keluar', pulang: 'Pulang',
  meninggal: 'Meninggal', aps: 'APS (pulang atas permintaan sendiri)', doa: 'DOA',
}
const LABEL_ISIAN: Record<string, string> = { pos_asal: 'Pos asal', pos_tujuan: 'Pos tujuan', izin_perintah: 'Izin pemberi perintah', keterangan: 'Keterangan', status: 'Status' }
const teks = (isian: string, v: unknown): string | null =>
  v === null || v === undefined ? null : isian === 'pos_asal' || isian === 'pos_tujuan' ? (LABEL[String(v)] ?? String(v)) : isian === 'status' ? (v === 'aktif' ? 'Aktif' : 'Nonaktif') : String(v)

export interface OpsiServerMatriks {
  boleh: (kode: string) => boolean
  sekarang?: () => Date
}

export function buatServerMatriks(opsi: OpsiServerMatriks) {
  const sekarang = opsi.sekarang ?? (() => new Date())
  let n = 0
  const sel: AturanPerpindahan[] = []
  const tambah = (pos_asal: PosAsalPerpindahan, pos_tujuan: PosTujuanPerpindahan, keterangan: string, izin_perintah = 'igd.disposisi.input', status: StatusAturan = 'aktif') => {
    const a: AturanPerpindahan = { id: id(++n), pos_asal, pos_tujuan, izin_perintah, keterangan, status, versi: '1', diubah_pada: '2026-11-01T00:00:00.000Z' }
    sel.push(a)
    return a
  }
  tambah('poliklinik', 'rawat_inap', 'PRD 7.00.3: SPRI dokter poli', 'rawat_jalan.pemeriksaan.sahkan')
  tambah('poliklinik', 'pulang', 'PRD 7.00.3', 'rawat_jalan.pemeriksaan.sahkan')
  const contoh = {
    poliLain: tambah('igd', 'poli_lain', 'PRD 7.00.3: konsul spesialis'),
    penunjang: tambah('igd', 'penunjang', 'PRD 7.00.3: order'),
    kamarOperasi: tambah('igd', 'kamar_operasi', 'PRD 7.00.3: operasi cito'),
    icuHcu: tambah('igd', 'icu_hcu', 'PRD 7.00.3'),
    rawatInap: tambah('igd', 'rawat_inap', 'PRD 7.00.3: episode rawat inap sejak IGD'),
    rujukKeluar: tambah('igd', 'rujuk_keluar', 'PRD 7.00.3'),
    pulang: tambah('igd', 'pulang', 'PRD 7.00.3'),
    meninggal: tambah('igd', 'meninggal', 'PRD 7.00.3'),
    aps: tambah('igd', 'aps', 'FR-03-09: pulang atas permintaan sendiri'),
    doa: tambah('igd', 'doa', 'FR-03-09: DOA'),
  }

  /** Sel aktif untuk pasangan pos (dipakai server kunjungan tiruan untuk POST /disposisi). */
  const berlaku = (asal: PosAsalPerpindahan, tujuan: string) => sel.find((a) => a.pos_asal === asal && a.pos_tujuan === tujuan && a.status === 'aktif') ?? null

  function bentukButir(b: IsianButir): ButirUsulan | { salah: { kode: string; pesan: string } } {
    const nilai = b.nilai_baru ?? {}
    if (b.aksi === 'tambah') {
      if (sel.some((a) => a.pos_asal === nilai.pos_asal && a.pos_tujuan === nilai.pos_tujuan)) return { salah: { kode: 'pasangan_sudah_ada', pesan: 'Sel matriks untuk pasangan pos ini sudah ada. Ubah sel yang ada.' } }
      const tampilan: TampilanIsian[] = Object.entries(nilai).map(([k, v]) => ({ isian: k, label_isian: LABEL_ISIAN[k] ?? k, lama: null, baru: teks(k, v) ?? '—' }))
      return { ...b, objek_label: `Sel baru ${teks('pos_asal', nilai.pos_asal)} → ${teks('pos_tujuan', nilai.pos_tujuan)}`, objek_berubah: false, nilai_lama: null, tampilan }
    }
    const a = sel.find((x) => x.id === b.objek_id)
    if (!a) return { salah: { kode: 'objek_tidak_ditemukan', pesan: 'Sel matriks tidak ditemukan.' } }
    const label = `${LABEL[a.pos_asal]} → ${LABEL[a.pos_tujuan]}`
    if (b.aksi === 'tutup') {
      return { ...b, objek_label: label, objek_berubah: false, nilai_lama: { status: a.status }, tampilan: [{ isian: 'status', label_isian: 'Status', lama: teks('status', a.status), baru: 'Nonaktif' }] }
    }
    const lama = Object.fromEntries(Object.keys(nilai).map((k) => [k, (a as unknown as Record<string, unknown>)[k]]))
    const tampilan = Object.entries(nilai).map(([k, v]) => ({ isian: k, label_isian: LABEL_ISIAN[k] ?? k, lama: teks(k, lama[k]), baru: teks(k, v) ?? '—' }))
    return { ...b, objek_label: label, objek_berubah: false, nilai_lama: lama, tampilan }
  }

  /** Penerapan usulan yang disetujui (tanggal berlaku sudah tiba). */
  function terapkan(b: ButirUsulan) {
    const nilai = b.nilai_baru ?? {}
    if (b.aksi === 'tambah') {
      tambah(nilai.pos_asal as PosAsalPerpindahan, nilai.pos_tujuan as PosTujuanPerpindahan, (nilai.keterangan as string | null) ?? '', String(nilai.izin_perintah))
      return
    }
    const a = sel.find((x) => x.id === b.objek_id)
    if (!a) return
    if (b.aksi === 'tutup') a.status = 'nonaktif'
    else Object.assign(a, Object.fromEntries(Object.entries(nilai).filter(([k]) => ['izin_perintah', 'keterangan', 'status'].includes(k))))
    a.versi = String(Number(a.versi) + 1)
    a.diubah_pada = sekarang().toISOString()
  }

  function tangani(r: PermintaanTiruan): ResponsTiruan | null {
    if (r.jalur.replace(/\/+$/, '') !== '/data-induk/matriks-perpindahan') return null
    if (r.metode.toUpperCase() !== 'GET') return error(405, 'metode_tidak_didukung', 'Matriks hanya diubah lewat usulan data induk.')
    if (!opsi.boleh('master_data.matriks_perpindahan.lihat') && !opsi.boleh('igd.disposisi.lihat')) return error(403, 'akses_ditolak', 'Anda tidak punya akses untuk tindakan ini.')
    const asal = r.kueri.get('pos_asal')
    const status = r.kueri.get('status')
    const hasil = sel.filter((a) => (!asal || a.pos_asal === asal) && (!status || a.status === status)).sort((x, y) => x.pos_asal.localeCompare(y.pos_asal) || x.id.localeCompare(y.id))
    const batas = Number(r.kueri.get('batas') ?? 50)
    const mulai = r.kueri.get('kursor') ? Number(atob(r.kueri.get('kursor')!)) : 0
    return { status: 200, header: { 'Cache-Control': 'no-store' }, isi: { data: hasil.slice(mulai, mulai + batas), meta: { kursor_berikutnya: mulai + batas < hasil.length ? btoa(String(mulai + batas)) : null } } }
  }

  return { tangani, berlaku, bentukButir, terapkan, contoh, sel: () => sel }
}

export type ServerMatriks = ReturnType<typeof buatServerMatriks>
