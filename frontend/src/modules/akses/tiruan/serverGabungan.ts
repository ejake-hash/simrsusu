import { buatServerNotifikasi } from '../../notifikasi/tiruan/serverNotifikasi'
import { buatServerInstrumenTriase } from '../../instrumen-triase/tiruan/serverInstrumenTriase'
import { buatServerKunjungan } from '../../pendaftaran/tiruan/serverKunjungan'
import { buatServerMatriks } from '../../matriks-perpindahan/tiruan/serverMatriks'
import { buatServerPapanIgd } from '../../papan-igd/tiruan/serverPapanIgd'
import { buatServerPasien } from '../../pendaftaran/tiruan/serverPasien'
import { buatServerFormularium } from '../../formularium/tiruan/serverFormularium'
import { buatServerRawatJalan } from '../../rawat-jalan/tiruan/serverRawatJalan'
import { buatServerPermintaanKamar } from '../../rawat-inap/tiruan/serverPermintaanKamar'
import { buatServerPetaTempatTidur } from '../../rawat-inap/tiruan/serverPetaTempatTidur'
import { buatServerPemulangan } from '../../rawat-inap/tiruan/serverPemulangan'
import { buatServerPenjamin } from '../../penjamin/tiruan/serverPenjamin'
import { buatServerLokasi } from '../../organisasi/tiruan/serverLokasi'
import { buatServerJadwal } from '../../jadwal-praktik/tiruan/serverJadwal'
import { buatServerIntegrasi } from '../../integrasi/tiruan/serverIntegrasi'
import { buatServerOrganisasi } from '../../organisasi/tiruan/serverOrganisasi'
import { buatServerPenyiapan } from '../../penyiapan/tiruan/serverPenyiapan'
import { buatServerSdm } from '../../sdm/tiruan/serverSdm'
import { buatServerTtd } from '../../ttd/tiruan/serverTtd'
import { buatServerTarif } from '../../tarif/tiruan/serverTarif'
import { buatServerUsulan } from '../../usulan/tiruan/serverUsulan'
import type { PermintaanTiruan, ResponsTiruan } from './serverTiruan'
import { buatServerTiruan, ID_SUPERADMIN, ID_SUPERADMIN_DUA, type OpsiServerTiruan } from './serverTiruan'

/**
 * Gabungan server tiruan akses (1.2c/1.2d/1.2e), organisasi (1.8a), lokasi (1.8b), SDM (1.8d), dan notifikasi/kotak
 * kerja (1.7) untuk `vite` dev
 * tanpa backend dan unit test. Akun yang masuk: superadmin (bisa diganti `penggunaId`). Superadmin di sini sekaligus
 * diberi peran Contoh Admin Data Induk dan Contoh Admin SDM agar satu akun dev bisa membuka Profil RS, Organisasi,
 * dan Data pegawai.
 */
export function buatServerGabungan(opsi: Omit<OpsiServerTiruan, 'unit' | 'pegawaiKeluar' | 'statusTtd'> & { tanpaDataInduk?: boolean } = {}) {
  let unitMilikSaya: () => string[] = () => []
  let pegawaiKeluar: (pegawaiId: string) => boolean = () => false
  let ringkasPegawai: (pegawaiId: string) => ReturnType<ReturnType<typeof buatServerSdm>['ringkasPegawai']> = () => null
  const organisasi = buatServerOrganisasi({ unitMilikSaya: () => unitMilikSaya(), pegawai: (id) => ringkasPegawai(id) })
  let statusTtd: NonNullable<OpsiServerTiruan['statusTtd']> = () => ({ aktif: false, diaktifkan_pada: null, dicabut_pada: null, terkunci_sampai: null, sesi_berlaku_sampai: null, reset_menunggu: false })
  const akses = buatServerTiruan({
    ...opsi,
    unit: { label: organisasi.labelUnit, aktif: organisasi.unitAktif },
    pegawaiKeluar: (id) => pegawaiKeluar(id),
    statusTtd: (id) => statusTtd(id),
  })
  if (!opsi.tanpaDataInduk && (opsi.penggunaId ?? ID_SUPERADMIN) === ID_SUPERADMIN) {
    akses.tambahPenugasanDev('contoh_data_induk')
    akses.tambahPenugasanDev('contoh_sdm')
    akses.tambahPenugasanDev('penyiap_instalasi')
    akses.tambahPenugasanDev('contoh_integrasi')
    akses.tambahPenugasanDev('contoh_pendaftaran')
    akses.tambahPenugasanDev('contoh_perawat')
    akses.tambahPenugasanDev('contoh_dpjp_rawat_inap')
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
  const jadwal = buatServerJadwal({
    boleh: akses.bolehSaya,
    pegawai: sdm.ringkasPegawai,
    sipAktif: sdm.sipAktif,
    poli: lokasi.ringkasPoli,
    ruang: (id) => lokasi.ringkasRuang(id),
    contoh: { dokter: sdm.contoh.dokter, poli: lokasi.contoh.poliAnak.id },
    sekarang: opsi.sekarang,
  })
  const notifikasi = buatServerNotifikasi({ boleh: akses.bolehSaya, unit: organisasi.ringkasUnit, sekarang: opsi.sekarang })
  const ttd = buatServerTtd({
    penggunaSaatIni: akses.penggunaSaatIni,
    namaAkun: akses.namaAkunUntukTtd,
    persetujuan: akses.persetujuanUntukTtd,
    ajukanReset: akses.ajukanResetTtd,
    bolehSaya: akses.bolehSaya,
    usulan: (id) => usulan.usulanUntukTtd(id),
    sekarang: opsi.sekarang,
  })
  let usulanTerbuka: (objekId: string) => { id: string; nomor: string } | null = () => null
  const matriks = buatServerMatriks({ boleh: akses.bolehSaya, sekarang: opsi.sekarang })
  const tarif = buatServerTarif({
    boleh: akses.bolehSaya,
    kelas: () => [lokasi.contoh.k1.id, lokasi.contoh.k3.id].map((kid) => ({ id: kid, label: lokasi.labelNilai('kelas_rawat_id', kid) ?? kid })),
    usulanTerbuka: (tarifId) => usulanTerbuka(tarifId),
    sekarang: opsi.sekarang,
  })
  const usulan = buatServerUsulan({
    tarif,
    matriks,
    penggunaSaatIni: akses.penggunaSaatIni,
    namaAkun: akses.namaAkunUntukTtd,
    boleh: akses.bolehSaya,
    potret: (jenis, id) => (jenis === 'tarif' ? tarif.potret(id) : jenis === 'formularium' || jenis === 'formularium_item' || jenis === 'aturan_perpindahan' ? null : lokasi.potretObjek(jenis, id)),
    labelNilai: lokasi.labelNilai,
    sudahTtd: (objekId, akunId) => ttd.sudahTtd(objekId, akunId),
    contoh: { kamarA: lokasi.contoh.km101.id, kamarB: lokasi.contoh.km102.id, ruangB: lokasi.contoh.r102.id, kelas: lokasi.contoh.k1.id },
    pengusulLain: ID_SUPERADMIN_DUA,
    sekarang: opsi.sekarang,
  })
  usulanTerbuka = usulan.usulanTerbuka
  const penyiapan = buatServerPenyiapan({ boleh: akses.bolehSaya, pelaku: () => ({ id: opsi.penggunaId ?? ID_SUPERADMIN, nama: 'Contoh Superadmin' }), sekarang: opsi.sekarang })
  const penjamin = buatServerPenjamin({ boleh: akses.bolehSaya, sekarang: opsi.sekarang })
  const formularium = buatServerFormularium({ boleh: akses.bolehSaya, penjamin: penjamin.ringkas, sekarang: opsi.sekarang })
  const pasien = buatServerPasien({ boleh: akses.bolehSaya, penggunaId: () => akses.penggunaSaatIni(), sekarang: opsi.sekarang })
  const instrumenTriase = buatServerInstrumenTriase({ boleh: akses.bolehSaya, sekarang: opsi.sekarang })
  const papanIgd = buatServerPapanIgd({ boleh: akses.bolehSaya, sekarang: opsi.sekarang })
  const petaTempatTidur = buatServerPetaTempatTidur({ boleh: akses.bolehSaya, sekarang: opsi.sekarang })
  const permintaanKamar = buatServerPermintaanKamar({ boleh: akses.bolehSaya, tempatTidur: petaTempatTidur.daftar, sekarang: opsi.sekarang })
  // 2.40: DPJP kunjungan RI contoh = dokter contoh SDM; akun yang masuk dianggap pegawai itu kecuali uji menggantinya.
  const dpjpRawatInap = { id: sdm.contoh.dokter, nama: sdm.ringkasPegawai(sdm.contoh.dokter)?.nama ?? 'Contoh Dokter' }
  let pegawaiPemulangan: () => string | null = () => dpjpRawatInap.id
  const pemulangan = buatServerPemulangan({
    boleh: akses.bolehSaya,
    tempatTidur: petaTempatTidur.daftar,
    dpjp: dpjpRawatInap,
    pegawaiSaya: () => pegawaiPemulangan(),
    pelaku: () => ({ id: opsi.penggunaId ?? ID_SUPERADMIN, nama: 'Contoh Superadmin' }),
    sekarang: opsi.sekarang,
  })
  const kunjungan = buatServerKunjungan({
    instrumenTriase: instrumenTriase.aktif,
    matriks,
    papanIgd,
    boleh: akses.bolehSaya,
    jadwal: { ambil: jadwal.ambil, keterisian: jadwal.keterisian, konsumsi: jadwal.konsumsi, slotPoli: jadwal.slotPoli },
    pasien: pasien.ringkas,
    pasienDigabung: pasien.digabung,
    penjamin: penjamin.ringkas,
    pelaku: () => ({ id: opsi.penggunaId ?? ID_SUPERADMIN, nama: 'Contoh Superadmin' }),
    sekarang: opsi.sekarang,
  })
  const dokterRawatJalan = sdm.ringkasPegawai(sdm.contoh.dokter)
  const rawatJalan = buatServerRawatJalan({
    boleh: akses.bolehSaya,
    poliId: lokasi.contoh.poliAnak.id,
    dokter: dokterRawatJalan ?? { id: sdm.contoh.dokter, nama: 'Contoh Dokter', status: 'aktif' },
    pasien: [pasien.contoh.satu, pasien.contoh.dua, pasien.contoh.tiga].map((p) => pasien.ringkas(p.id)!),
    pelaku: () => ({ id: opsi.penggunaId ?? ID_SUPERADMIN, nama: 'Contoh Superadmin' }),
    sekarang: opsi.sekarang,
  })
  const integrasi = buatServerIntegrasi({ boleh: akses.bolehSaya, pelaku: () => ({ id: opsi.penggunaId ?? ID_SUPERADMIN, nama: 'Contoh Superadmin' }), sekarang: opsi.sekarang })
  // TtdSintetisSeeder: superadmin kedua (penyetuju kontrol ganda) sudah punya TTE aktif.
  ttd.aktifkan(ID_SUPERADMIN_DUA)
  statusTtd = ttd.status
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
    jadwal,
    tarif,
    notifikasi,
    ttd,
    usulan,
    penyiapan,
    integrasi,
    penjamin,
    formularium,
    pasien,
    kunjungan,
    instrumenTriase,
    matriks,
    papanIgd,
    petaTempatTidur,
    permintaanKamar,
    pemulangan,
    /** Uji: pegawai akun yang masuk (bukan DPJP → sahkan 403 bukan_pemutus). */
    aturPegawaiPemulangan: (f: () => string | null) => (pegawaiPemulangan = f),
    dpjpRawatInap,
    rawatJalan,
    tangani: (r: PermintaanTiruan): ResponsTiruan | Promise<ResponsTiruan> | null => {
      if (akses.wajibGantiSandi() && !bebasGantiSandi(r)) {
        return { status: 403, isi: { error: { kode: 'wajib_ganti_sandi', pesan: 'Ganti sandi awal Anda lebih dulu.', rincian: [] } } }
      }
      // Peta TT sebelum lokasi: server lokasi menangani seluruh /tempat-tidur/* (data induk), termasuk /status.
      return akses.tangani(r) ?? organisasi.tangani(r) ?? petaTempatTidur.tangani(r) ?? permintaanKamar.tangani(r) ?? pemulangan.tangani(r) ?? lokasi.tangani(r) ?? sdm.tangani(r) ?? jadwal.tangani(r) ?? tarif.tangani(r) ?? matriks.tangani(r) ?? notifikasi.tangani(r) ?? ttd.tangani(r) ?? usulan.tangani(r) ?? penyiapan.tangani(r) ?? integrasi.tangani(r) ?? penjamin.tangani(r) ?? instrumenTriase.tangani(r) ?? papanIgd.tangani(r) ?? formularium.tangani(r) ?? pasien.tangani(r) ?? kunjungan.tangani(r) ?? rawatJalan.tangani(r)
    },
  }
}
