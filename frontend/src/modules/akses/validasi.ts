export interface IsianSandi {
  nama_pengguna: string
  sandi: string
}

export type FieldError = Partial<Record<keyof IsianSandi, string>>

/** Sama dengan MasukRequest di server: wajib diisi, batas panjang 150 dan 1024. */
export function validasiIsianSandi(isian: IsianSandi): FieldError {
  const error: FieldError = {}

  if (isian.nama_pengguna.trim() === '') error.nama_pengguna = 'Nama pengguna wajib diisi.'
  else if (isian.nama_pengguna.length > 150) error.nama_pengguna = 'Nama pengguna terlalu panjang.'

  if (isian.sandi === '') error.sandi = 'Kata sandi wajib diisi.'
  else if (isian.sandi.length > 1024) error.sandi = 'Kata sandi terlalu panjang.'

  return error
}
