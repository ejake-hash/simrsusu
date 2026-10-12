import type { KodeLangkah, SistemReferensi, StatusLangkah } from './tipe'

export const KUNCI_PENYIAPAN = { status: ['penyiapan'] as const }

export const LABEL_STATUS: Record<StatusLangkah, string> = {
  belum: 'Belum',
  sebagian: 'Sebagian',
  selesai: 'Selesai',
  dilewati: 'Dilewati',
  menunggu_fase_2: 'Menunggu fase 2',
}
export const PIL_STATUS: Record<StatusLangkah, string> = {
  belum: 'pil-netral',
  sebagian: 'pil-peringatan',
  selesai: 'pil-ok',
  dilewati: 'pil-info',
  menunggu_fase_2: 'pil-netral',
}

/**
 * Layar pengisi data tiap langkah (tiket fe: tautan, bukan formulir duplikat). Langkah 4 diisi di halaman ini (unggah
 * paket, endpoint wizard 1.8e); null = belum ada layarnya (fase 2, integrasi 1.9, layar modul aktif = utang).
 */
export const TAUTAN_LANGKAH: Record<KodeLangkah, { rute: string; label: string } | null> = {
  superadmin: { rute: '/pengaturan/akun', label: 'Buka Akun & akses' },
  profil_rs: { rute: '/pengaturan/profil-rs', label: 'Buka Profil rumah sakit' },
  organisasi: { rute: '/master-data/organisasi', label: 'Buka Organisasi, Unit, Ruang & TT' },
  paket_referensi: null,
  pegawai: { rute: '/sdm/pegawai', label: 'Buka Data pegawai' },
  penjamin_tarif: null,
  formularium: null,
  kredensial_integrasi: null,
  modul_aktif: null,
}
/** Penjelasan langkah yang belum punya layar pengisi. */
export const KET_LANGKAH: Partial<Record<KodeLangkah, string>> = {
  superadmin: 'Superadmin kedua dibuat dari konsol server (akses:superadmin-pertama) atau dari akun pegawai; keduanya wajib MFA.',
  penjamin_tarif: 'Diisi di awal fase 2, sebelum pendaftaran pasien. Instalasi belum bisa melayani pasien sampai langkah ini lengkap.',
  formularium: 'Diisi di awal fase 2 bersama modul farmasi.',
  kredensial_integrasi: 'Kredensial BPJS/SATUSEHAT disimpan dan diuji di layar Konfigurasi integrasi (tiket 1.9). Sampai itu, langkah ini boleh dilewati dengan alasan.',
  modul_aktif: 'Modul inti fase 1 sudah aktif sejak pemasangan; layar pengaturan modul aktif menyusul.',
}

export const LABEL_SISTEM: Record<SistemReferensi, string> = {
  wilayah: 'Wilayah (Kemendagri)',
  referensi: 'Kode variabel RME',
  icd10: 'ICD-10',
  icd9cm: 'ICD-9-CM',
  loinc: 'LOINC',
  ucum: 'UCUM',
}
/** Judul kolom CSV per sistem (kontrak v0.11.0). */
export const KOLOM_SISTEM: Record<SistemReferensi, string> = {
  wilayah: 'kode,nama',
  referensi: 'kelompok,kode,label',
  icd10: 'kode,tampilan,kode_induk',
  icd9cm: 'kode,tampilan,kode_induk',
  loinc: 'kode,tampilan,kode_induk',
  ucum: 'kode,tampilan,kode_induk',
}
