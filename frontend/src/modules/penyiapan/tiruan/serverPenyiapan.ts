import type { PermintaanTiruan, ResponsTiruan } from '../../akses/tiruan/serverTiruan'
import type { HasilMuatReferensi, KodeLangkah, LangkahPenyiapan, PaketTermuat, SistemReferensi, StatusLangkah, StatusPenyiapan, SyaratPenyiapan } from '../tipe'

/**
 * Server tiruan penyiapan instalasi (kontrak v0.12.0) dan pemuat paket referensi (v0.11.0) untuk `vite` dev dan uji
 * unit. Syarat dihitung dari keadaan tiruan sederhana (data contoh dev sudah mengisi langkah 1–3, 5, 9; paket belum
 * dimuat), bukan dari server tiruan modul lain. Aturan status, urutan, dan error mengikuti backend 1.8f.
 */
const error = (status: number, kode: string, pesan: string, rincian: Record<string, unknown>[] = []): ResponsTiruan => ({ status, isi: { error: { kode, pesan, rincian } } })
const ok = (isi: unknown): ResponsTiruan => ({ status: 200, isi })

const LANGKAH: [KodeLangkah, string][] = [
  ['superadmin', 'Dua superadmin dengan MFA'],
  ['profil_rs', 'Profil RS dan paket identitas'],
  ['organisasi', 'Unit, KSM, poliklinik, bangsal, kamar, TT, kelas rawat'],
  ['paket_referensi', 'Paket data referensi'],
  ['pegawai', 'Pegawai dan izin praktik'],
  ['penjamin_tarif', 'Penjamin dan tarif'],
  ['formularium', 'Formularium'],
  ['kredensial_integrasi', 'Kredensial integrasi'],
  ['modul_aktif', 'Modul aktif'],
]
const FASE_2: KodeLangkah[] = ['formularium']
const PAKET: [SistemReferensi, boolean][] = [['wilayah', true], ['referensi', true], ['icd10', false], ['icd9cm', false], ['loinc', true], ['ucum', true]]
const LABEL_PAKET: Partial<Record<SistemReferensi, string>> = { wilayah: 'Wilayah', referensi: 'Kode variabel RME', loinc: 'LOINC', ucum: 'UCUM' }
const TUNTAS: StatusLangkah[] = ['selesai', 'dilewati', 'menunggu_fase_2']

export interface OpsiServerPenyiapan {
  boleh: (kode: string) => boolean
  pelaku: () => { id: string; nama: string }
  sekarang?: () => Date
}

export function buatServerPenyiapan(opsi: OpsiServerPenyiapan) {
  const iso = () => (opsi.sekarang?.() ?? new Date()).toISOString()
  /** Keadaan data contoh; uji unit boleh mengubahnya. */
  const keadaan = { superadmin: 2, logo: true, organisasiLengkap: true, pegawai: 3, penjaminAktif: 1, kelasTanpaTarif: 0 }
  const paket = new Map<SistemReferensi, { versi: string; sumber: string; dimuat_pada: string; jumlah: number; isi: string }[]>()
  const konfirmasi = new Map<KodeLangkah, NonNullable<LangkahPenyiapan['konfirmasi_terakhir']>>()
  let siapSejak: string | null = null

  const s = (kode: string, label: string, terpenuhi: boolean, keterangan: string | null): SyaratPenyiapan => ({ kode, label, terpenuhi, keterangan })
  const akhir = <T>(xs: T[]): T | undefined => xs[xs.length - 1]
  const terakhir = (sistem: SistemReferensi) => akhir(paket.get(sistem) ?? []) ?? null
  function syarat(kode: KodeLangkah): SyaratPenyiapan[] {
    switch (kode) {
      case 'superadmin':
        return [s('superadmin_mfa', 'Dua superadmin dengan MFA', keadaan.superadmin >= 2, keadaan.superadmin >= 2 ? `${keadaan.superadmin} superadmin` : `${keadaan.superadmin} dari 2`), s('jaringan_rs', 'Jaringan RS terisi di server', true, null)]
      case 'profil_rs':
        return [s('profil', 'Nama RS tersimpan', true, null), s('logo', 'Logo paket identitas terunggah', keadaan.logo, null)]
      case 'organisasi':
        return ([['unit', 'Unit organisasi'], ['ksm', 'KSM'], ['kelas_rawat', 'Kelas rawat'], ['bangsal', 'Bangsal'], ['tempat_tidur', 'Tempat tidur'], ['poliklinik', 'Poliklinik']] as const).map(([k, l]) =>
          s(k, l, keadaan.organisasiLengkap, keadaan.organisasiLengkap ? '2 aktif' : 'Belum ada yang aktif'),
        )
      case 'paket_referensi':
        return PAKET.filter(([, w]) => w).map(([sis]) => s(sis, LABEL_PAKET[sis]!, terakhir(sis) !== null, terakhir(sis) ? `Versi ${terakhir(sis)!.versi}` : 'Belum dimuat'))
      case 'pegawai':
        return [s('pegawai', 'Pegawai aktif', keadaan.pegawai > 0, keadaan.pegawai ? `${keadaan.pegawai} pegawai` : 'Belum ada'), s('izin_praktik', 'Izin praktik yang berlaku', keadaan.pegawai > 0, keadaan.pegawai ? '2 izin' : 'Belum ada')]
      case 'penjamin_tarif':
        return [
          s('penjamin', 'Penjamin aktif', keadaan.penjaminAktif > 0, keadaan.penjaminAktif ? `${keadaan.penjaminAktif} penjamin` : 'Belum ada'),
          s('tarif_per_kelas', 'Tarif untuk setiap kelas rawat aktif', keadaan.kelasTanpaTarif === 0, keadaan.kelasTanpaTarif ? `${keadaan.kelasTanpaTarif} kelas belum bertarif` : 'Semua kelas bertarif'),
        ]
      case 'kredensial_integrasi':
        return [s('kredensial', 'Kredensial tersimpan dan uji koneksi berhasil', false, 'Dibangun di tiket 1.9')]
      case 'modul_aktif':
        return [s('modul', 'Modul aktif untuk seluruh RS', true, '2 modul')]
      default:
        return []
    }
  }
  const semua = (x: SyaratPenyiapan[]) => x.length > 0 && x.every((y) => y.terpenuhi)
  function statusLangkah(kode: KodeLangkah): StatusLangkah {
    const sy = syarat(kode)
    const k = konfirmasi.get(kode)
    if (FASE_2.includes(kode)) return 'menunggu_fase_2'
    if (k?.dilewati) return 'dilewati'
    if (k && semua(sy)) return 'selesai'
    if (k || sy.some((y) => y.terpenuhi)) return 'sebagian'
    return 'belum'
  }
  function daftarPaket(): PaketTermuat[] {
    return PAKET.map(([sistem, wajib]) => {
      const p = terakhir(sistem)
      return { sistem, wajib, versi: p?.versi ?? null, sumber: p?.sumber ?? null, dimuat_pada: p?.dimuat_pada ?? null, jumlah_kode_aktif: p?.jumlah ?? 0 }
    })
  }
  function status(): StatusPenyiapan {
    const bolehUbah = opsi.boleh('pengaturan.penyiapan.perbaiki')
    let urutan = true
    const langkah = LANGKAH.map(([kode, judul], i): LangkahPenyiapan => {
      const st = statusLangkah(kode)
      const sy = syarat(kode)
      const l: LangkahPenyiapan = {
        no: i + 1, kode, judul, status: st, wajib_untuk_melayani: i < 6, syarat: sy,
        dapat_dikonfirmasi: bolehUbah && urutan && !FASE_2.includes(kode) && semua(sy),
        dapat_dilewati: bolehUbah && urutan && kode === 'kredensial_integrasi' && st !== 'selesai',
        konfirmasi_terakhir: konfirmasi.get(kode) ?? null,
        paket: kode === 'paket_referensi' ? daftarPaket() : null,
      }
      urutan = urutan && TUNTAS.includes(st)
      return l
    })
    const st = langkah.map((l) => l.status)
    const ringkas = !st.some((x) => x === 'selesai' || x === 'dilewati') ? 'belum' : st.every((x) => TUNTAS.includes(x)) ? 'selesai' : 'sebagian'
    return { status: ringkas, siap_melayani: siapSejak !== null, siap_melayani_sejak: siapSejak, langkah }
  }

  function konfirmasikan(kode: KodeLangkah, isi: { dilewati?: boolean; alasan?: string | null }): ResponsTiruan {
    const dilewati = isi.dilewati === true
    const alasan = (isi.alasan ?? '').trim()
    if (dilewati && (alasan.length < 5 || alasan.length > 500)) return error(422, 'validasi_gagal', 'Data tidak valid.', [{ kolom: 'alasan', pesan: 'Alasan wajib, 5–500 karakter.' }])
    if (dilewati && (kode !== 'kredensial_integrasi' || statusLangkah(kode) === 'selesai')) return error(422, 'tidak_bisa_dilewati', 'Hanya langkah kredensial integrasi yang belum selesai yang bisa dilewati.')
    if (FASE_2.includes(kode)) return error(409, 'langkah_menunggu_fase_2', 'Langkah ini diisi di fase 2 (penjamin, tarif, formularium).')
    const sebelum = LANGKAH.slice(0, LANGKAH.findIndex(([k]) => k === kode)).map(([k]) => ({ langkah: k, status: statusLangkah(k) })).filter((x) => !TUNTAS.includes(x.status))
    if (sebelum.length) return error(409, 'langkah_sebelumnya_belum_selesai', 'Selesaikan langkah sebelumnya lebih dulu.', sebelum)
    const kurang = syarat(kode).filter((y) => !y.terpenuhi).map(({ kode: k, label, keterangan }) => ({ kode: k, label, keterangan }))
    if (!dilewati && kurang.length) return error(409, 'syarat_belum_lengkap', 'Syarat langkah ini belum lengkap.', kurang)
    konfirmasi.set(kode, { oleh: opsi.pelaku(), pada: iso(), dilewati, alasan: dilewati ? alasan : null })
    if (!siapSejak && LANGKAH.slice(0, 6).every(([k]) => statusLangkah(k) === 'selesai')) siapSejak = iso()
    return ok({ data: status() })
  }

  /** Pemuat paket tiruan: baris `kode,...` dihitung; versi sama + isi lain atau versi lama → 409 (kontrak v0.11.0). */
  function muat(sistem: SistemReferensi, mode: 'pratinjau' | 'terapkan', versi: string, sumber: string, teks: string): ResponsTiruan {
    const baris = teks.replace(/^﻿/, '').split(/\r?\n/).filter((b) => b.trim())
    if (baris.length < 1) return error(422, 'berkas_tidak_valid', 'Berkas kosong.', [{ pesan: 'Baris judul kolom tidak ada.' }])
    const data = baris.slice(1)
    const kesalahan = data.map((b, i) => ({ b, i })).filter(({ b }) => !b.split(/[,;]/)[0]?.trim()).map(({ i }) => ({ baris: i + 2, kolom: 'kode', kode: 'kolom_wajib', pesan: 'Kode wajib diisi.' }))
    const riwayat = paket.get(sistem) ?? []
    const sama = riwayat.find((p) => p.versi === versi)
    if (sama && (sama.isi !== teks || sama !== akhir(riwayat))) return error(409, 'versi_sudah_dimuat', 'Versi ini sudah dimuat dengan isi lain atau sudah digantikan.')
    const sebelumnya = akhir(riwayat)?.jumlah ?? 0
    const idempoten = sama !== undefined
    const hasil: HasilMuatReferensi = {
      sistem, versi, mode, diterapkan: false, jumlah_baris: data.length, jumlah_salah: kesalahan.length,
      // Ada baris salah → ringkasan perubahan 0 (sama dengan backend).
      jumlah_baru: idempoten || kesalahan.length ? 0 : Math.max(data.length - sebelumnya, 0), jumlah_diubah: 0,
      jumlah_dinonaktifkan: idempoten || kesalahan.length ? 0 : Math.max(sebelumnya - data.length, 0), jumlah_diaktifkan_lagi: 0,
      kesalahan: kesalahan.slice(0, 500), kesalahan_terpotong: kesalahan.length > 500,
    }
    if (mode === 'pratinjau') return ok({ data: hasil })
    if (kesalahan.length) return error(422, 'muat_tidak_valid', 'Ada baris yang salah; tidak ada yang disimpan.', kesalahan.slice(0, 500))
    if (!idempoten) paket.set(sistem, [...riwayat, { versi, sumber, dimuat_pada: iso(), jumlah: data.length, isi: teks }])
    return ok({ data: { ...hasil, diterapkan: true } })
  }

  function tangani(r: PermintaanTiruan): ResponsTiruan | Promise<ResponsTiruan> | null {
    const [a0, a1, a2] = r.jalur.replace(/\/+$/, '').split('/').filter(Boolean)
    const m = r.metode.toUpperCase()
    if (a0 === 'penyiapan') {
      if (m === 'GET' && !a1) return opsi.boleh('pengaturan.penyiapan.lihat') ? ok({ data: status() }) : error(403, 'akses_ditolak', 'Anda tidak memiliki izin.')
      if (m === 'POST' && a2 === 'selesai') {
        if (!opsi.boleh('pengaturan.penyiapan.perbaiki')) return error(403, 'akses_ditolak', 'Anda tidak memiliki izin.')
        if (!LANGKAH.some(([k]) => k === a1)) return error(404, 'tidak_ditemukan', 'Langkah tidak dikenal.')
        return konfirmasikan(a1 as KodeLangkah, (r.isi ?? {}) as { dilewati?: boolean; alasan?: string | null })
      }
    }
    if (a0 === 'paket-referensi' && a2 === 'muat' && m === 'POST') {
      if (!opsi.boleh('master_data.katalog.input')) return error(403, 'akses_ditolak', 'Anda tidak memiliki izin.')
      const sistem = a1 as SistemReferensi
      if (!PAKET.some(([x]) => x === sistem)) return error(404, 'tidak_ditemukan', 'Sistem tidak dikenal.')
      const fd = r.isi instanceof FormData ? r.isi : null
      const berkas = fd?.get('berkas')
      const versi = String(fd?.get('versi') ?? '').trim()
      const sumber = String(fd?.get('sumber') ?? '').trim()
      const lisensi = String(fd?.get('catatan_lisensi') ?? '').trim()
      const mode = fd?.get('mode') === 'terapkan' ? 'terapkan' : 'pratinjau'
      if (!(berkas instanceof Blob)) return error(422, 'validasi_gagal', 'Data tidak valid.', [{ kolom: 'berkas', pesan: 'Pilih berkas CSV.' }])
      if (!versi) return error(422, 'validasi_gagal', 'Data tidak valid.', [{ kolom: 'versi', pesan: 'Versi wajib diisi.' }])
      if (sumber.length < 3) return error(422, 'validasi_gagal', 'Data tidak valid.', [{ kolom: 'sumber', pesan: 'Sumber minimal 3 karakter.' }])
      if (sistem === 'loinc' && !lisensi) return error(422, 'validasi_gagal', 'Data tidak valid.', [{ kolom: 'catatan_lisensi', pesan: 'Atribusi LOINC wajib.' }])
      return berkas.text().then((teks) => muat(sistem, mode, versi, sumber, teks))
    }
    return null
  }

  return { tangani, keadaan, status }
}
