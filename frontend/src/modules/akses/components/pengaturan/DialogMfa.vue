<script setup lang="ts">
import { useForm } from 'vee-validate'
import { computed, ref, watch } from 'vue'
import { z } from 'zod'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { skemaZod } from '@/design-system/form/skemaZod'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { aturWajibMfaAkun, resetMfaAkun } from '../../apiPengaturan'
import { errorKolom, pesanError } from '../../pengaturan'
import type { StatusMfaAkun } from '../../tipePengaturan'

/**
 * Ubah sakelar wajib MFA akun (alasan wajib) atau reset MFA (alasan + dasar wajib; sesi akun diputus dan akun
 * mendaftar ulang dari jaringan RS, PRD 10.3.2).
 */
const props = defineProps<{ mode: 'wajib' | 'reset'; akunId: string; namaAkun: string; wajibBaru: boolean; mfaAktif: boolean }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [status: StatusMfaAkun] }>()

const sibuk = ref(false)
const error = ref<string | null>(null)
const alasanSkema = z.string().trim().min(5, 'Tulis alasan, minimal 5 karakter.').max(500)
const skema = computed(() =>
  props.mode === 'reset'
    ? skemaZod(z.object({ alasan: alasanSkema, dasar: z.string().trim().min(3, 'Isi dasar (mis. nomor tiket layanan), minimal 3 karakter.').max(200) }))
    : skemaZod(z.object({ alasan: alasanSkema, dasar: z.string().optional() })),
)
const { defineField, handleSubmit, errors, resetForm, setErrors } = useForm({ validationSchema: skema })
const [alasan] = defineField('alasan')
const [dasar] = defineField('dasar')

watch(buka, (b) => {
  if (!b) return
  error.value = null
  resetForm({ values: { alasan: '', dasar: '' } })
})

const judul = computed(() =>
  props.mode === 'reset' ? `Reset MFA ${props.namaAkun}?` : props.wajibBaru ? `Wajibkan MFA untuk ${props.namaAkun}?` : `Matikan sakelar wajib MFA ${props.namaAkun}?`,
)
const akibat = computed(() => {
  if (props.mode === 'reset') {
    return 'Autentikator dan kode cadangan akun ini dibatalkan dan semua sesinya diputus. Akun harus mendaftar MFA lagi saat masuk berikutnya, dan pendaftaran hanya bisa dari jaringan RS.'
  }
  if (props.wajibBaru) {
    return props.mfaAktif
      ? 'Akun ini diminta kode MFA setiap masuk, juga dari dalam jaringan RS.'
      : 'Akun ini belum punya MFA: sesinya diputus dan masuk berikutnya harus mendaftar MFA dari jaringan RS.'
  }
  return 'Hanya sakelar tingkat akun yang dimatikan. Kewajiban dari peran, izin sensitif, dan akses dari luar jaringan RS tetap berlaku.'
})

const kirim = handleSubmit(async (v) => {
  sibuk.value = true
  error.value = null
  try {
    const status = props.mode === 'reset' ? await resetMfaAkun(props.akunId, v.alasan, v.dasar ?? '') : await aturWajibMfaAkun(props.akunId, props.wajibBaru, v.alasan)
    buka.value = false
    emit('selesai', status)
  } catch (e) {
    const kolom = errorKolom(e, ['alasan', 'dasar'])
    if (Object.keys(kolom).length) setErrors(kolom)
    else error.value = pesanError(e)
  } finally {
    sibuk.value = false
  }
})
</script>

<template>
  <Dialog v-model:open="buka" :judul="judul" :sibuk="sibuk" peringatan>
    <form id="form-mfa" novalidate @submit.prevent="kirim">
      <p class="catatan catatan-peringatan akibat">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ akibat }}</span>
      </p>
      <KolomIsian v-slot="a" label="Alasan" :error="errors.alasan" wajib>
        <textarea v-bind="a" v-model="alasan" class="isian" rows="2" />
      </KolomIsian>
      <KolomIsian v-if="mode === 'reset'" v-slot="a" label="Dasar" :error="errors.dasar" petunjuk="Mis. nomor tiket layanan atau formulir permintaan reset." wajib>
        <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-mfa" :varian="mode === 'reset' ? 'bahaya' : 'utama'" :sibuk="sibuk">
        {{ sibuk ? 'Memproses…' : mode === 'reset' ? 'Reset MFA' : wajibBaru ? 'Wajibkan MFA' : 'Matikan sakelar' }}
      </Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.akibat {
  margin: 0 0 var(--space-4);
}
</style>
