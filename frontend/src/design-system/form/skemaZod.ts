import type { TypedSchema, TypedSchemaError } from 'vee-validate'
import { z } from 'zod'

/**
 * Adapter zod 4 → TypedSchema vee-validate 4.15, dipakai `useForm({ validationSchema: skemaZod(skema) })`.
 *
 * Sementara: vee-validate 4 belum membaca Standard Schema dan `@vee-validate/zod` hanya untuk zod 3.
 * Hapus berkas ini saat vee-validate 5 stabil (Standard Schema bawaan); lihat utang di backlog.
 */

// Pesan bawaan zod dalam bahasa Indonesia; pesan khusus tetap ditulis di skema masing-masing.
z.config(z.locales.id())

/** Jalur kolom gaya vee-validate: `penugasan[0].dasar`. */
function keJalur(jalur: readonly PropertyKey[]): string {
  return jalur.reduce<string>((hasil, bagian) => {
    if (typeof bagian === 'number') return `${hasil}[${bagian}]`
    return hasil ? `${hasil}.${String(bagian)}` : String(bagian)
  }, '')
}

export function skemaZod<S extends z.ZodType>(skema: S): TypedSchema<z.input<S>, z.output<S>> {
  return {
    __type: 'VVTypedSchema',
    async parse(nilai) {
      const hasil = await skema.safeParseAsync(nilai)
      if (hasil.success) return { value: hasil.data, errors: [] }

      // Satu entri per kolom, pesan berurutan sesuai isu zod.
      const perKolom = new Map<string, string[]>()
      for (const isu of hasil.error.issues) {
        const jalur = keJalur(isu.path)
        perKolom.set(jalur, [...(perKolom.get(jalur) ?? []), isu.message])
      }
      const errors: TypedSchemaError[] = [...perKolom].map(([path, pesan]) => ({ path, errors: pesan }))
      return { errors }
    },
  }
}
