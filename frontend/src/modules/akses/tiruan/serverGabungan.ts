import { buatServerLokasi } from '../../organisasi/tiruan/serverLokasi'
import { buatServerOrganisasi } from '../../organisasi/tiruan/serverOrganisasi'
import type { PermintaanTiruan, ResponsTiruan } from './serverTiruan'
import { buatServerTiruan, ID_SUPERADMIN, type OpsiServerTiruan } from './serverTiruan'

/**
 * Gabungan server tiruan akses (1.2c/1.2d/1.2e), organisasi (1.8a), dan lokasi (1.8b) untuk `vite` dev tanpa backend
 * dan unit test.
 * Akun yang masuk: superadmin (bisa diganti `penggunaId`). Superadmin di sini sekaligus diberi peran
 * Contoh Admin Data Induk agar satu akun dev bisa membuka Profil RS dan Organisasi.
 */
export function buatServerGabungan(opsi: Omit<OpsiServerTiruan, 'unit'> & { tanpaDataInduk?: boolean } = {}) {
  let unitMilikSaya: () => string[] = () => []
  const organisasi = buatServerOrganisasi({ unitMilikSaya: () => unitMilikSaya() })
  const akses = buatServerTiruan({ ...opsi, unit: { label: organisasi.labelUnit, aktif: organisasi.unitAktif } })
  if (!opsi.tanpaDataInduk && (opsi.penggunaId ?? ID_SUPERADMIN) === ID_SUPERADMIN) akses.tambahPenugasanDev('contoh_data_induk')
  unitMilikSaya = akses.unitMilikSaya
  const lokasi = buatServerLokasi({ unit: organisasi.ringkasUnit, unitContoh: organisasi.akar.irna, sekarang: opsi.sekarang })
  return {
    pohon: akses.pohon,
    organisasi,
    lokasi,
    tangani: (r: PermintaanTiruan): ResponsTiruan | Promise<ResponsTiruan> | null => akses.tangani(r) ?? organisasi.tangani(r) ?? lokasi.tangani(r),
  }
}
