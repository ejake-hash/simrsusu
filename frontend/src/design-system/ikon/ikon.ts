/**
 * Ikon garis 24×24 dari prototipe HTML (di luar repo) (stroke currentColor). Nama ikon menu = kode menu
 * (`ikon` di GET /saya/menu); nama yang tidak dikenal memakai `umum`. Isi SVG hanya dari peta ini,
 * tidak pernah dari respons server.
 */
const PETA = {
  // Menu
  beranda: '<path d="M3 11 12 4l9 7"/><path d="M5 10v10h14V10"/>',
  pendaftaran: '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
  igd: '<path d="M3 12h4l2-5 4 10 2-5h6"/>',
  rawat_jalan: '<path d="M6 3v6a6 6 0 0 0 12 0V3"/><circle cx="18" cy="16" r="2.5"/><path d="M12 15v2a4 4 0 0 0 4 4"/>',
  rawat_inap: '<path d="M3 7v12M3 13h18v6M21 13a3 3 0 0 0-3-3h-7v3"/><circle cx="7" cy="10" r="2"/>',
  kamar_operasi: '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/>',
  penunjang: '<path d="M9 3h6M10 3v6l-5 9a2 2 0 0 0 2 3h10a2 2 0 0 0 2-3l-5-9V3"/>',
  farmasi: '<rect x="3" y="9" width="18" height="6" rx="3" transform="rotate(-45 12 12)"/><path d="m8.5 8.5 7 7"/>',
  rekam_medis: '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
  kasir: '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/>',
  casemix: '<path d="M4 4h16v6H4zM4 14h10v6H4z"/><path d="m17 16 2 2 3-4"/>',
  mutu: '<path d="M12 3 4 6v6c0 4.5 3.4 8 8 9 4.6-1 8-4.5 8-9V6z"/><path d="m9 12 2 2 4-4"/>',
  sdm: '<circle cx="9" cy="8" r="3.5"/><path d="M3 20a6 6 0 0 1 12 0M16 4a3.5 3.5 0 0 1 0 7M21 20a6 6 0 0 0-4-5.6"/>',
  logistik: '<path d="M3 7 12 3l9 4-9 4z"/><path d="M3 7v10l9 4 9-4V7M12 11v10"/>',
  penelitian: '<path d="M10 3v6L4 20h16l-6-11V3M8 3h8"/><path d="M7 15h10"/>',
  pendidikan: '<path d="m2 9 10-5 10 5-10 5z"/><path d="M6 11v5c3 2 9 2 12 0v-5"/>',
  integrasi: '<path d="M8 3v4M16 3v4M6 7h12v4a6 6 0 0 1-12 0z"/><path d="M12 17v4"/>',
  dasbor:
    '<rect x="3" y="3" width="8" height="10" rx="1.5"/><rect x="13" y="3" width="8" height="6" rx="1.5"/><rect x="13" y="11" width="8" height="10" rx="1.5"/><rect x="3" y="15" width="8" height="6" rx="1.5"/>',
  master_data:
    '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
  pengaturan:
    '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-2.9-1.2l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.7 1.7 0 0 0 3 15H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.2-2.9l-.1-.1A2 2 0 1 1 7 5.2l.1.1A1.7 1.7 0 0 0 10 4.1V4a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 2.9 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0 1.2 2.9H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
  // Antarmuka
  umum: '<circle cx="12" cy="12" r="3"/>',
  menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
  tutup: '<path d="M6 6l12 12M18 6 6 18"/>',
  cari: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
  keluar: '<path d="M15 4h4v16h-4"/><path d="M10 8l-4 4 4 4M6 12h10"/>',
  kembali: '<path d="M19 12H5M11 6l-6 6 6 6"/>',
  jam: '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/>',
  lonceng: '<path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
  gembok: '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
  tambah: '<path d="M12 5v14M5 12h14"/>',
  salin: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a1 1 0 0 1 1-1h9"/>',
  peringatan: '<path d="M12 4 2.5 20h19z"/><path d="M12 10v4M12 17.5v.01"/>',
  info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.01"/>',
  centang: '<path d="m5 12 5 5 9-10"/>',
  panah_kanan: '<path d="m9 6 6 6-6 6"/>',
  panah_bawah: '<path d="m6 9 6 6 6-6"/>',
  pengguna: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
  kritis: '<path d="M8.3 3h7.4L21 8.3v7.4L15.7 21H8.3L3 15.7V8.3z"/><path d="M12 8v4.5M12 16v.01"/>',
  kisi: '<rect x="4" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.5"/>',
} as const

export type NamaIkon = keyof typeof PETA

export function isiIkon(nama: string): string {
  return Object.prototype.hasOwnProperty.call(PETA, nama) ? PETA[nama as NamaIkon] : PETA.umum
}
