import type { Menu, MenuSaya } from '../tipeMenu'

/**
 * Data tiruan GET /api/v1/saya/menu: contoh di docs/api/openapi.yaml v0.2.x (beranda, igd + igd.papan,
 * pengaturan + peran/akun, izin) ditambah menu sidebar docs/html/home.html
 * agar kerangka bisa dibandingkan dengan prototipe. Fase dari PRD D.3 dan 16. Kode, rute, dan ikon
 * menu tambahan hanya perkiraan; yang berlaku adalah seeder menu 1.2a.
 * Dipakai unit test; tidak masuk build produksi. Menu yang dihitung dari peran ada di serverTiruan.ts.
 */
const segera = (kode: string, label: string, rute: string, fase: number): Menu => ({
  kode,
  label,
  ikon: kode,
  rute,
  status: 'segera',
  fase,
  sub_menu: [],
})

export const menuSayaTiruan: MenuSaya = {
  versi: 'c3f1a9',
  grup: [
    {
      kode: 'utama',
      label: null,
      menu: [{ kode: 'beranda', label: 'Beranda', ikon: 'beranda', rute: '/', status: 'aktif', fase: 1, sub_menu: [] }],
    },
    {
      kode: 'pelayanan',
      label: 'Pelayanan',
      menu: [
        segera('pendaftaran', 'Pendaftaran', '/pendaftaran', 2),
        {
          kode: 'igd',
          label: 'IGD',
          ikon: 'igd',
          rute: '/igd',
          status: 'segera',
          fase: 2,
          sub_menu: [{ kode: 'igd.papan', label: 'Papan pasien IGD', rute: '/igd/papan', status: 'segera', fase: 2 }],
        },
        segera('rawat_jalan', 'Rawat Jalan', '/rawat-jalan', 2),
        segera('rawat_inap', 'Rawat Inap', '/rawat-inap', 2),
        segera('kamar_operasi', 'Kamar Operasi', '/kamar-operasi', 3),
        segera('penunjang', 'Penunjang', '/penunjang', 2),
        segera('farmasi', 'Farmasi', '/farmasi', 2),
        segera('rekam_medis', 'Rekam Medis', '/rekam-medis', 2),
      ],
    },
    {
      kode: 'pendapatan',
      label: 'Pendapatan',
      menu: [segera('kasir', 'Kasir', '/kasir', 2), segera('casemix', 'Casemix dan Klaim', '/casemix', 3)],
    },
    {
      kode: 'tata_kelola',
      label: 'Tata Kelola',
      menu: [
        segera('mutu', 'Mutu dan Keselamatan', '/mutu', 4),
        segera('sdm', 'SDM', '/sdm', 4),
        segera('logistik', 'Logistik', '/logistik', 4),
        segera('penelitian', 'Penelitian', '/penelitian', 5),
        segera('pendidikan', 'Pendidikan Klinis', '/pendidikan', 5),
        segera('integrasi', 'Integrasi dan Laporan', '/integrasi', 4),
        segera('dasbor', 'Dasbor', '/dasbor', 4),
        {
          kode: 'pengaturan',
          label: 'Pengaturan Sistem',
          ikon: 'pengaturan',
          rute: '/pengaturan',
          status: 'aktif',
          fase: 1,
          sub_menu: [
            { kode: 'pengaturan.peran', label: 'Peran & izin', rute: '/pengaturan/peran', status: 'aktif', fase: 1 },
            { kode: 'pengaturan.akun', label: 'Akun & akses', rute: '/pengaturan/akun', status: 'aktif', fase: 1 },
            { kode: 'pengaturan.audit', label: 'Jejak audit', rute: '/pengaturan/audit', status: 'segera', fase: 1 },
          ],
        },
      ],
    },
  ],
  izin: ['pengaturan.peran.lihat', 'pengaturan.akun.lihat'],
}
