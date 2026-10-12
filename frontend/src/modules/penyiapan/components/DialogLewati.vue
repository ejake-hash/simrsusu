<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { ApiError } from '@/shared/api/klien'
import { konfirmasiLangkah } from '../api'
import type { LangkahPenyiapan, StatusPenyiapan } from '../tipe'

/** Lewati langkah kredensial integrasi dengan alasan wajib (kontrak v0.12.0; hanya langkah 8, sampai tiket 1.9). */
const props = defineProps<{ langkah: LangkahPenyiapan }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [status: StatusPenyiapan] }>()

const alasan = ref('')
const kesalahan = ref<string | undefined>()
const error = ref<string | null>(null)
const sibuk = ref(false)

watch(buka, (b) => {
  if (!b) return
  alasan.value = ''
  kesalahan.value = undefined
  error.value = null
})

async function kirim() {
  kesalahan.value = alasan.value.trim().length < 5 ? 'Tulis alasan, minimal 5 karakter.' : undefined
  if (kesalahan.value) return
  sibuk.value = true
  error.value = null
  try {
    const status = await konfirmasiLangkah(props.langkah.kode, { alasan: alasan.value.trim() })
    buka.value = false
    emit('selesai', status)
  } catch (err) {
    if (err instanceof ApiError && err.kode === 'validasi_gagal') kesalahan.value = String(err.rincian.find((r) => r.kolom === 'alasan')?.pesan ?? err.message)
    else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="`Lewati langkah ${langkah.no}?`" :sibuk="sibuk" peringatan>
    <form id="form-lewati" novalidate @submit.prevent="kirim">
      <p class="catatan catatan-peringatan">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ langkah.judul }} ditandai dilewati dan tercatat di jejak audit. Langkah ini bisa dikonfirmasi selesai nanti setelah kredensial diuji.</span>
      </p>
      <KolomIsian v-slot="a" label="Alasan" :error="kesalahan" petunjuk="Mis. belum ada kredensial dari RS." wajib>
        <textarea v-bind="a" v-model="alasan" class="isian" rows="3" maxlength="500" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-lewati" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : 'Lewati langkah' }}</Tombol>
    </template>
  </Dialog>
</template>
