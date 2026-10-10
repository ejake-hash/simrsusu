export interface IsianSandi {
  nama_pengguna: string
  sandi: string
}

export type GalatIsian = Partial<Record<keyof IsianSandi, string>>

/** Sama dengan MasukRequest di server: wajib diisi, batas panjang 150 dan 1024. */
export function validasiIsianSandi(isian: IsianSandi): GalatIsian {
  const galat: GalatIsian = {}

  if (isian.nama_pengguna.trim() === '') galat.nama_pengguna = 'Nama pengguna wajib diisi.'
  else if (isian.nama_pengguna.length > 150) galat.nama_pengguna = 'Nama pengguna terlalu panjang.'

  if (isian.sandi === '') galat.sandi = 'Kata sandi wajib diisi.'
  else if (isian.sandi.length > 1024) galat.sandi = 'Kata sandi terlalu panjang.'

  return galat
}
