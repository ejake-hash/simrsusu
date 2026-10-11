import { mount } from '@vue/test-utils'
import { useForm } from 'vee-validate'
import { describe, expect, it } from 'vitest'
import { defineComponent } from 'vue'
import { z } from 'zod'
import { skemaZod } from './skemaZod'

const skema = z.object({
  dasar: z.string().trim().min(3, 'Dasar perubahan wajib diisi (nomor SK, memo, atau persetujuan).'),
  berlaku_sampai: z.coerce.date().optional(),
  izin: z.array(z.object({ kode: z.string().min(1, 'Kode izin wajib diisi.') })),
})

describe('skemaZod (adapter zod 4 → TypedSchema)', () => {
  it('lulus: nilai hasil parse bertipe (trim, coerce tanggal), tanpa error', async () => {
    const hasil = await skemaZod(skema).parse({ dasar: '  SK 12/2026 ', berlaku_sampai: '2026-12-31', izin: [{ kode: 'kasir.tagihan.lihat' }] })
    expect(hasil.errors).toEqual([])
    expect(hasil.value?.dasar).toBe('SK 12/2026')
    expect(hasil.value?.berlaku_sampai).toBeInstanceOf(Date)
    expect(hasil.value?.berlaku_sampai?.getUTCFullYear()).toBe(2026)
  })

  it('gagal: pesan per kolom, jalur bersarang dan larik gaya vee-validate', async () => {
    const hasil = await skemaZod(skema).parse({ dasar: ' x ', izin: [{ kode: 'a' }, { kode: '' }] })
    expect(hasil.value).toBeUndefined()
    expect(hasil.errors).toEqual([
      { path: 'dasar', errors: ['Dasar perubahan wajib diisi (nomor SK, memo, atau persetujuan).'] },
      { path: 'izin[1].kode', errors: ['Kode izin wajib diisi.'] },
    ])
  })

  it('beberapa isu pada kolom yang sama digabung berurutan', async () => {
    const s = z.object({ kode: z.string().min(2, 'Terlalu pendek.').regex(/^[a-z]+$/, 'Huruf kecil saja.') })
    const hasil = await skemaZod(s).parse({ kode: 'A' })
    expect(hasil.errors).toEqual([{ path: 'kode', errors: ['Terlalu pendek.', 'Huruf kecil saja.'] }])
  })

  it('pesan bawaan zod berbahasa Indonesia', async () => {
    const hasil = await skemaZod(z.object({ jumlah: z.number() })).parse({ jumlah: 'x' } as unknown as { jumlah: number })
    expect(hasil.errors[0]?.errors[0]).toMatch(/[Ii]nput tidak valid|diharapkan/)
  })

  it('terpasang di useForm: errors per kolom dan nilai bertipe di handleSubmit', async () => {
    let terkirim: unknown
    const Form = defineComponent({
      setup() {
        const form = useForm({ validationSchema: skemaZod(skema), initialValues: { dasar: '', izin: [] } })
        const kirim = form.handleSubmit((nilai) => {
          terkirim = nilai
        })
        return { form, kirim }
      },
      template: '<p />',
    })
    const w = mount(Form)

    await w.vm.kirim()
    expect(terkirim).toBeUndefined()
    expect(w.vm.form.errors.value.dasar).toBe('Dasar perubahan wajib diisi (nomor SK, memo, atau persetujuan).')

    w.vm.form.setFieldValue('dasar', ' Memo 3 ')
    await w.vm.kirim()
    expect(terkirim).toEqual({ dasar: 'Memo 3', izin: [] })
  })
})
