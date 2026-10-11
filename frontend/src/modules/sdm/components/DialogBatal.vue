<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'

/**
 * Batalkan catatan izin praktik atau RKK dengan alasan (≥ 5 karakter). Baris tetap sebagai riwayat; perbaikan =
 * catat ulang. `batalkan` dipanggil dengan alasan dan melempar ApiError bila ditolak (409 sudah_batal → pesan).
 */
const props = defineProps<{ judul: string; uraian: string; batalkan: ((alasan: string) => Promise<unknown>) | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [] }>()

const alasan = ref('')
const kesalahan = ref<string | null>(null)
const error = ref<string | null>(null)
const sibuk = ref(false)

watch(buka, (b) => {
  if (!b) return
  alasan.value = ''
  kesalahan.value = null
  error.value = null
})

async function kirim() {
  if (!props.batalkan) return
  if (alasan.value.trim().length < 5) {
    kesalahan.value = 'Isi alasan pembatalan, minimal 5 karakter.'
    return
  }
  kesalahan.value = null
  sibuk.value = true
  error.value = null
  try {
    await props.batalkan(alasan.value.trim())
    buka.value = false
    emit('selesai')
  } catch (err) {
    const kolom = errorKolom(err, ['alasan_batal'])
    if (kolom.alasan_batal) kesalahan.value = kolom.alasan_batal
    else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="judul" :sibuk="sibuk" peringatan>
    <form id="form-batal-sdm" novalidate @submit.prevent="kirim">
      <p class="uraian">{{ uraian }}</p>
      <KolomIsian v-slot="a" label="Alasan pembatalan" :error="kesalahan ?? undefined" wajib>
        <textarea v-bind="a" v-model="alasan" class="isian" rows="2" maxlength="500" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Kembali</button>
      <Tombol type="submit" form="form-batal-sdm" varian="bahaya" :sibuk="sibuk">{{ sibuk ? 'Membatalkan…' : 'Batalkan catatan' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.uraian {
  margin: 0 0 var(--space-3);
  color: var(--muted);
  font-size: 13px;
}
</style>
