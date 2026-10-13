<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { cabutPerangkatAkun, cabutPerangkatSaya } from '../../apiPerangkat'
import { errorKolom, pesanError } from '../../pengaturan'
import type { PerangkatTepercaya } from '../../tipePerangkat'

/**
 * Cabut perangkat tepercaya (tiket 1.13): milik sendiri tanpa alasan; akun lain oleh admin dengan alasan wajib.
 * Masuk berikutnya dari perangkat itu diminta kode MFA lagi; sesi yang sedang berjalan tidak diputus.
 */
const props = defineProps<{ perangkat: PerangkatTepercaya | null; akunId?: string; namaAkun?: string }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [perangkat: PerangkatTepercaya] }>()

const alasan = ref('')
const errorAlasan = ref<string | undefined>()
const error = ref<string | null>(null)
const sibuk = ref(false)
watch(buka, (b) => {
  if (!b) return
  alasan.value = ''
  errorAlasan.value = undefined
  error.value = null
})

const admin = computed(() => !!props.akunId)
const judul = computed(() => `Cabut ${props.perangkat?.nama_perangkat ?? 'perangkat'}?`)

async function kirim() {
  if (!props.perangkat) return
  if (admin.value && alasan.value.trim().length < 5) {
    errorAlasan.value = 'Tulis alasan, minimal 5 karakter.'
    return
  }
  sibuk.value = true
  error.value = null
  try {
    const hasil = admin.value ? await cabutPerangkatAkun(props.akunId!, props.perangkat.id, alasan.value.trim()) : await cabutPerangkatSaya(props.perangkat.id)
    buka.value = false
    emit('selesai', hasil)
  } catch (e) {
    const kolom = errorKolom(e, ['alasan'])
    if (kolom.alasan) errorAlasan.value = kolom.alasan
    else error.value = pesanError(e)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="judul" :sibuk="sibuk" peringatan>
    <form id="form-cabut-perangkat" novalidate @submit.prevent="kirim">
      <p class="catatan catatan-peringatan akibat">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>
          <template v-if="admin">Masuk berikutnya akun {{ namaAkun }} dari perangkat ini diminta kode MFA lagi.</template>
          <template v-else-if="perangkat?.perangkat_ini">Ini perangkat yang sedang Anda pakai. Masuk berikutnya dari sini diminta kode MFA lagi.</template>
          <template v-else>Masuk berikutnya dari perangkat itu diminta kode MFA lagi.</template>
          Sesi yang sedang berjalan tidak diputus.
        </span>
      </p>
      <KolomIsian v-if="admin" v-slot="a" label="Alasan" :error="errorAlasan" wajib>
        <textarea v-bind="a" v-model="alasan" class="isian" rows="2" maxlength="500" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-cabut-perangkat" varian="bahaya" :sibuk="sibuk">{{ sibuk ? 'Memproses…' : 'Cabut perangkat' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.akibat {
  margin: 0 0 var(--space-4);
}
</style>
