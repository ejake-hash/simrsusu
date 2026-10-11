import type { Pengguna } from '../api'

/** Pengguna tiruan lengkap (skema Saya v0.2.3) untuk unit test; isi menimpa nilai bawaan. */
export function penggunaTiruan(isi: Partial<Pengguna> = {}): Pengguna {
  return {
    id: 'p-1',
    nama_pengguna: 'contoh.dokter',
    login_terakhir_pada: null,
    mfa_aktif: false,
    pegawai: null,
    peran: [],
    masuk_sebelumnya_pada: null,
    masuk_sebelumnya_dari_jaringan_rs: null,
    ...isi,
  }
}
