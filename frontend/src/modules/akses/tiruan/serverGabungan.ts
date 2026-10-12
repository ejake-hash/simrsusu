import { buatServerNotifikasi } from '../../notifikasi/tiruan/serverNotifikasi'
import { buatServerLokasi } from '../../organisasi/tiruan/serverLokasi'
import { buatServerOrganisasi } from '../../organisasi/tiruan/serverOrganisasi'
import { buatServerSdm } from '../../sdm/tiruan/serverSdm'
import type { PermintaanTiruan, ResponsTiruan } from './serverTiruan'
import { buatServerTiruan, ID_SUPERADMIN, type OpsiServerTiruan } from './serverTiruan'

/**
 * Gabungan server tiruan akses (1.2c/1.2d/1.2e), organisasi (1.8a), lokasi (1.8b), SDM (1.8d), dan notifikasi/kotak
 * kerja (1.7) untuk `vite` dev
 * tanpa backend dan unit test. Akun yang masuk: superadmin (bisa diganti `penggunaId`). Superadmin di sini sekaligus
 * diberi peran Contoh Admin Data Induk dan Contoh Admin SDM agar satu akun dev bisa membuka Profil RS, Organisasi,
 * dan Data pegawai.
 */
export function buatServerGabungan(opsi: Omit<OpsiServerTiruan, 'unit' | 'pegawaiKeluar'> & { tanpaDataInduk?: boolean } = {}) {
  let unitMilikSaya: () => string[] = () => []
  let pegawaiKeluar: (pegawaiId: string) => boolean = () => false
  let ringkasPegawai: (pegawaiId: string) => ReturnType<ReturnType<typeof buatServerSdm>['ringkasPegawai']> = () => null
  const organisasi = buatServerOrganisasi({ unitMilikSaya: () => unitMilikSaya(), pegawai: (id) => ringkasPegawai(id) })
  const akses = buatServerTiruan({ ...opsi, unit: { label: organisasi.labelUnit, aktif: organisasi.unitAktif }, pegawaiKeluar: (id) => pegawaiKeluar(id) })
  if (!opsi.tanpaDataInduk && (opsi.penggunaId ?? ID_SUPERADMIN) === ID_SUPERADMIN) {
    akses.tambahPenugasanDev('contoh_data_induk')
    akses.tambahPenugasanDev('contoh_sdm')
  }
  unitMilikSaya = akses.unitMilikSaya
  const lokasi = buatServerLokasi({ unit: organisasi.ringkasUnit, unitContoh: organisasi.akar.irna, sekarang: opsi.sekarang })
  const sdm = buatServerSdm({
    boleh: akses.bolehSaya,
    unit: organisasi.ringkasUnit,
    unitContoh: organisasi.akar,
    akun: {
      ringkas: akses.ringkasAkunPegawai,
      buat: akses.buatAkunPegawai,
      superadmin: akses.akunSuperadminPegawai,
      nonaktifkan: akses.nonaktifkanAkunPegawai,
      tautkan: akses.tautkanPegawai,
    },
    pelaku: () => ({ id: opsi.penggunaId ?? ID_SUPERADMIN, nama: 'Contoh Superadmin' }),
    sekarang: opsi.sekarang,
  })
  const notifikasi = buatServerNotifikasi({ boleh: akses.bolehSaya, unit: organisasi.ringkasUnit, sekarang: opsi.sekarang })
  pegawaiKeluar = sdm.pegawaiKeluar
  ringkasPegawai = sdm.ringkasPegawai

  /** Selama wajib ganti sandi, endpoint bersesi selain daftar ini 403 wajib_ganti_sandi (kontrak v0.6.0, /saya/sandi). */
  const bebasGantiSandi = (r: PermintaanTiruan) => {
    const m = r.metode.toUpperCase()
    return (
      (m === 'GET' && ['/saya', '/saya/menu'].includes(r.jalur)) ||
      (m === 'POST' && ['/saya/sandi', '/autentikasi/keluar'].includes(r.jalur)) ||
      (m === 'GET' && (r.jalur === '/profil-rs' || r.jalur.startsWith('/profil-rs/berkas/')))
    )
  }
  return {
    pohon: akses.pohon,
    organisasi,
    lokasi,
    akses,
    sdm,
    notifikasi,
    tangani: (r: PermintaanTiruan): ResponsTiruan | Promise<ResponsTiruan> | null => {
      if (akses.wajibGantiSandi() && !bebasGantiSandi(r)) {
        return { status: 403, isi: { error: { kode: 'wajib_ganti_sandi', pesan: 'Ganti sandi awal Anda lebih dulu.', rincian: [] } } }
      }
      return akses.tangani(r) ?? organisasi.tangani(r) ?? lokasi.tangani(r) ?? sdm.tangani(r) ?? notifikasi.tangani(r)
    },
  }
}
