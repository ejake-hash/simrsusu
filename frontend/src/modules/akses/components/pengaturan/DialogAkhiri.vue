<script setup lang="ts">
import { useForm } from 'vee-validate'
import { ref, watch } from 'vue'
import { z } from 'zod'
import Dialog from '@/design-system/components/Dialog.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { skemaZod } from '@/design-system/form/skemaZod'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '../../pengaturan'

/**
 * Mengakhiri penugasan atau pengecualian per akun (MD-1): bukan tombol hapus, wajib alasan, kalimat akibat
 * ditampilkan, baris tetap ada sebagai riwayat berstatus batal.
 */
const props = defineProps<{ judul: string; akibat: string; aksi: (alasan: string) => Promise<unknown> }>()
const buka = defineModel<boolean>('open', { required: true })

const sibuk = ref(false)
const error = ref<string | null>(null)
const { defineField, handleSubmit, errors, resetForm, setErrors } = useForm({
  validationSchema: skemaZod(z.object({ alasan_batal: z.string().trim().min(5, 'Tulis alasan, minimal 5 karakter.').max(500) })),
})
const [alasan] = defineField('alasan_batal')

watch(buka, (b) => {
  if (b) {
    resetForm({ values: { alasan_batal: '' } })
    error.value = null
  }
})

const kirim = handleSubmit(async (v) => {
  sibuk.value = true
  error.value = null
  try {
    await props.aksi(v.alasan_batal)
    buka.value = false
  } catch (e) {
    const kolom = errorKolom(e, ['alasan_batal'])
    if (Object.keys(kolom).length) setErrors(kolom)
    else error.value = pesanError(e)
  } finally {
    sibuk.value = false
  }
})
</script>

<template>
  <Dialog v-model:open="buka" :judul="judul" :sibuk="sibuk" peringatan>
    <form id="form-akhiri" novalidate @submit.prevent="kirim">
      <p class="catatan catatan-peringatan akibat">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ akibat }} Baris ini tetap tersimpan sebagai riwayat.</span>
      </p>
      <KolomIsian v-slot="a" label="Alasan mengakhiri" :error="errors.alasan_batal" wajib>
        <textarea v-bind="a" v-model="alasan" class="isian" rows="3" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <button type="submit" form="form-akhiri" class="tombol tombol-bahaya" :disabled="sibuk">{{ sibuk ? 'Memproses…' : 'Akhiri' }}</button>
    </template>
  </Dialog>
</template>

<style scoped>
.akibat {
  margin: 0 0 var(--space-4);
}
</style>
