import { ref } from 'vue'
import { tampilkanToast } from '@/design-system/components/toast'
import { buatUsulan } from '@/modules/usulan/api'
import type { IsianButir } from '@/modules/usulan/tipe'
import { ApiError } from '@/shared/api/klien'
import { harusLewatUsulan } from './formularium'

/**
 * Mode usulan dialog tulis formularium (tiket 2.12, FR-54-02). Dialog mencoba tulis langsung; bila server menjawab 409
 * `harus_lewat_usulan` (instalasi sudah melayani), dialog meminta tanggal berlaku dan alasan, lalu mengajukan perubahan
 * yang sama sebagai satu usulan berjenjang (jenis data `formularium`).
 */
export function useModeUsulan() {
  const aktif = ref(false)
  const berlaku = ref('')
  const alasan = ref('')

  function reset() {
    aktif.value = false
    berlaku.value = ''
    alasan.value = ''
  }
  /** true bila galat ini memindahkan dialog ke mode usulan. */
  function tangkap(e: unknown): boolean {
    if (!harusLewatUsulan(e)) return false
    aktif.value = true
    return true
  }
  function periksa(): Record<string, string> {
    const e: Record<string, string> = {}
    if (!berlaku.value) e.berlaku_dari = 'Pilih tanggal berlaku.'
    else if (berlaku.value < hariIni()) e.berlaku_dari = 'Tidak boleh sebelum hari ini.'
    if (alasan.value.trim().length < 5) e.alasan = 'Isi alasan, minimal 5 karakter.'
    return e
  }
  async function ajukan(butir: IsianButir, dasar: string): Promise<void> {
    const u = await buatUsulan({ jenis_data: 'formularium', perubahan: [butir], berlaku_dari: berlaku.value, alasan: alasan.value.trim(), dasar })
    tampilkanToast(`Usulan ${u.nomor} diajukan. Perubahan berlaku sesudah diverifikasi dan disetujui.`)
  }
  return { aktif, berlaku, alasan, reset, tangkap, periksa, ajukan }
}

/** Tanggal hari ini (peramban); server tetap memeriksa terhadap zona RS. */
export function hariIni(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/**
 * Galat 422 usulan → kolom dialog (`perubahan.0.nilai_baru.<isian>` → `<isian>`, `dasar` → `dasar_perubahan`). Galat yang
 * tidak punya kolom di dialog dikembalikan sebagai teks agar tetap tampil; [{}, null] = bukan galat 422.
 */
export function galatUsulan(e: unknown, kolomDialog: readonly string[]): [Record<string, string>, string | null] {
  if (!(e instanceof ApiError) || e.status !== 422) return [{}, null]
  const kolom: Record<string, string> = {}
  const sisa: string[] = []
  for (const r of e.rincian) {
    const k = String(r.kolom ?? '').replace(/^perubahan\.0\.nilai_baru\./, '').replace(/^dasar$/, 'dasar_perubahan')
    const pesan = String(r.pesan ?? r.kode ?? 'Tidak valid.')
    if ([...kolomDialog, 'berlaku_dari', 'alasan'].includes(k)) kolom[k] = pesan
    else sisa.push(pesan)
  }
  return [kolom, sisa.length ? sisa.join(' ') : Object.keys(kolom).length ? null : e.message]
}
