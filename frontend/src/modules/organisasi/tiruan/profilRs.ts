import { HALAMAN_MASUK_BAWAAN } from '../halamanMasuk'
import type { ProfilRsApi } from '../tipe'

/** ProfilRsApi tiruan (kontrak v0.3.0; halaman_masuk v0.15.0) untuk unit test dan server tiruan; isi menimpa nilai bawaan instalasi kosong. */
export function profilRsTiruan(isi: Partial<Omit<ProfilRsApi, 'paket_identitas' | 'kontak_admin'>> & { paket_identitas?: Partial<ProfilRsApi['paket_identitas']>; kontak_admin?: Partial<ProfilRsApi['kontak_admin']> } = {}): ProfilRsApi {
  const { paket_identitas, kontak_admin, ...lain } = isi
  return {
    versi: 'p1',
    nama: null,
    instalasi_demo: false,
    halaman_masuk: { ...HALAMAN_MASUK_BAWAAN, bawaan: true },
    ...lain,
    paket_identitas: { warna: { brand: '#006937', accent: '#F3C300' }, logo_url: null, motif_url: null, foto_url: null, ...paket_identitas },
    kontak_admin: { unit: 'admin SIMRS', kontak: null, ...kontak_admin },
  }
}
