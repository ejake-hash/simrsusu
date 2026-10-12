import type { LingkunganIntegrasi, SistemIntegrasi, StatusKiriman, StatusSistemIntegrasi } from './tipe'

export const KUNCI_INTEGRASI = {
  semua: ['integrasi'] as const,
  sistem: ['integrasi', 'sistem'] as const,
  kiriman: (f: object) => ['integrasi', 'kiriman', f] as const,
  log: (f: object) => ['integrasi', 'log', f] as const,
}

export const LABEL_SISTEM: Record<SistemIntegrasi, string> = { bpjs_vclaim: 'BPJS VClaim', aplicares: 'Aplicares', satusehat: 'SATUSEHAT' }
export const LABEL_LINGKUNGAN: Record<LingkunganIntegrasi, string> = { sandbox: 'Sandbox', produksi: 'Produksi' }
/** Sistem per sub-menu pemantauan (izin per tujuan, kontrak v0.13.0). */
export const SISTEM_TUJUAN: Record<'satusehat' | 'bpjs', SistemIntegrasi[]> = { satusehat: ['satusehat'], bpjs: ['bpjs_vclaim', 'aplicares'] }

export const LABEL_STATUS_KIRIMAN: Record<StatusKiriman, string> = { menunggu: 'Menunggu', terkirim: 'Terkirim', gagal: 'Gagal', tertahan: 'Tertahan' }
export const PIL_STATUS_KIRIMAN: Record<StatusKiriman, string> = { menunggu: 'pil-info', terkirim: 'pil-ok', gagal: 'pil-bahaya', tertahan: 'pil-peringatan' }

export const LABEL_CB: Record<StatusSistemIntegrasi['circuit_breaker']['status'], string> = {
  tertutup: 'Normal',
  terbuka: 'Terputus sementara',
  setengah_terbuka: 'Mencoba lagi',
}
export const PIL_CB: Record<StatusSistemIntegrasi['circuit_breaker']['status'], string> = { tertutup: 'pil-ok', terbuka: 'pil-bahaya', setengah_terbuka: 'pil-peringatan' }

/** Label isian kredensial per nama kolom (kontrak: cons_id, secret_key, user_key, client_id, client_secret, organization_id). */
export const LABEL_KOLOM: Record<string, string> = {
  cons_id: 'Cons ID',
  secret_key: 'Secret key',
  user_key: 'User key',
  client_id: 'Client ID',
  client_secret: 'Client secret',
  organization_id: 'Organization ID',
}
