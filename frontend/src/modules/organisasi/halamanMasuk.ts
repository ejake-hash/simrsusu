import type { HalamanMasuk, HalamanMasukApi, ProfilRsApi } from './tipe'

/**
 * Panel merek halaman masuk yang dapat diatur (kontrak v0.15.0, tiket 1.15). Tanpa impor nilai, supaya server tiruan
 * memakai teks bawaan yang sama. Teks bawaan = deskripsi skema HalamanMasuk di kontrak.
 */
export const HALAMAN_MASUK_BAWAAN: HalamanMasuk = {
  lencana: 'Satu data untuk seluruh rumah sakit',
  judul: 'Pelayanan, pendapatan, dan mutu dalam',
  judul_sorot: 'satu layar kerja.',
  uraian: 'Dari pendaftaran sampai klaim, setiap pencatatan langsung menjadi rekam medis, tagihan, dan laporan, tanpa entri ulang.',
  fitur: [
    { judul: 'Paperless', uraian: 'Pengesahan elektronik dengan PIN, catatan terkunci, perubahan jadi adendum.' },
    { judul: 'Terhubung', uraian: 'BPJS dan SATUSEHAT berjalan dari layar yang sama.' },
    { judul: 'Pendidikan', uraian: 'Akun peserta didik mengikuti masa rotasi dan supervisi.' },
  ],
  foto_keterangan: null,
  tampilkan_motif: true,
}

/** Nilai efektif dari profil; backend sebelum 1.15-be belum mengirimnya = teks bawaan. */
export const halamanMasukEfektif = (p: ProfilRsApi): HalamanMasukApi => p.halaman_masuk ?? { ...HALAMAN_MASUK_BAWAAN, bawaan: true }

/** Batas panjang (karakter sesudah dibersihkan) sesuai kontrak. */
export const BATAS_HALAMAN_MASUK = { lencana: 60, judul: 80, judul_sorot: 40, uraian: 240, fitur_judul: 30, fitur_uraian: 120, foto_keterangan: 80 } as const

/**
 * Sama dengan server: karakter kendali dibuang, spasi dan baris baru diringkas menjadi satu spasi, tepi dipangkas.
 * HTML tidak ditafsirkan (tetap teks).
 */
export function bersihkanTeks(teks: string): string {
  return teks.replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '').replace(/\s+/g, ' ').trim()
}

/** Panjang dalam code point (sama dengan mb_strlen server), bukan unit UTF-16. */
export const panjangTeks = (teks: string): number => [...bersihkanTeks(teks)].length
