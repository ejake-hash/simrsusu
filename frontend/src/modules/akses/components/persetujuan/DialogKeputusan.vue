<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import type { PersetujuanAkses } from '../../tipePersetujuan'

/**
 * Konfirmasi keputusan atas satu pengajuan: setujui (catatan opsional) atau tolak (alasan wajib, seluruh grup ikut
 * ditolak). Teks akibat menyebut apa yang berlaku dan untuk berapa akun.
 */
const props = defineProps<{ mode: 'setujui' | 'tolak'; persetujuan: PersetujuanAkses; aksi: (teks: string | null) => Promise<void> }>()
const buka = defineModel<boolean>('open', { required: true })

const teks = ref('')
const errorTeks = ref<string | undefined>()
const error = ref<string | null>(null)
const sibuk = ref(false)

watch(buka, (b) => {
  if (!b) return
  teks.value = ''
  errorTeks.value = undefined
  error.value = null
})

const akibat = computed(() => {
  const p = props.persetujuan
  if (props.mode === 'tolak') return `Pengajuan “${p.ringkasan}” ditolak${p.grup.jumlah > 1 ? ' untuk semua penyetujunya' : ''} dan tidak berlaku.`
  const sisa = p.grup.jumlah - p.grup.disetujui - 1
  return sisa > 0
    ? `Persetujuan Anda dicatat. Perubahan baru berlaku setelah ${sisa} penyetuju lain juga menyetujui.`
    : `Perubahan langsung berlaku untuk ${p.akun_terdampak} akun: ${p.ringkasan}.`
})

async function kirim() {
  const t = teks.value.trim()
  if (props.mode === 'tolak' && t.length < 5) {
    errorTeks.value = 'Tulis alasan penolakan, minimal 5 karakter.'
    return
  }
  errorTeks.value = undefined
  sibuk.value = true
  error.value = null
  try {
    await props.aksi(t || null)
    buka.value = false
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="mode === 'setujui' ? `Setujui ${persetujuan.nomor}?` : `Tolak ${persetujuan.nomor}?`" :sibuk="sibuk" :peringatan="mode === 'tolak'">
    <form id="form-keputusan" novalidate @submit.prevent="kirim">
      <p class="catatan akibat" :class="mode === 'tolak' ? 'catatan-peringatan' : 'catatan-info'">
        <Ikon :nama="mode === 'tolak' ? 'peringatan' : 'info'" :ukuran="16" />
        <span>{{ akibat }}</span>
      </p>
      <KolomIsian v-slot="a" :label="mode === 'tolak' ? 'Alasan penolakan' : 'Catatan (opsional)'" :error="errorTeks" :wajib="mode === 'tolak'">
        <textarea v-bind="a" v-model="teks" class="isian" rows="3" maxlength="500" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-keputusan" :varian="mode === 'tolak' ? 'bahaya' : 'utama'" :sibuk="sibuk">
        {{ sibuk ? 'Memproses…' : mode === 'setujui' ? 'Setujui' : 'Tolak' }}
      </Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.akibat {
  margin: 0 0 var(--space-4);
}
</style>
