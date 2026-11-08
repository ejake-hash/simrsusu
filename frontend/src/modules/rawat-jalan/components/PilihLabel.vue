<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { ambilLabelSensitivitas } from '@/modules/akses/apiPengaturan'
import { KUNCI as KUNCI_PENGATURAN } from '@/modules/akses/pengaturan'

/**
 * Label sensitivitas catatan klinis (lapis 4, 9.2.5), dipilih penulis. Memberi label mempersempit akses, jadi tidak butuh
 * izin label. Daftar label dibaca dari GET /label-sensitivitas; akun tanpa izin membacanya tidak bisa memilih (label yang
 * sudah ada tetap tampil sebagai kodenya).
 */
const props = defineProps<{ nonaktif?: boolean }>()
const label = defineModel<string>({ required: true })

const daftar = useQuery({ queryKey: KUNCI_PENGATURAN.labelSensitivitas, queryFn: ambilLabelSensitivitas, staleTime: 300_000, retry: false })
const pilihan = computed(() => (daftar.data.value ?? []).filter((l) => l.aktif || l.kode === label.value))
const petunjuk = 'Catatan berlabel hanya terbuka bagi Anda dan petugas yang berizin label serta merawat pasien; yang lain melihat "Terbatas".'
</script>

<template>
  <KolomIsian v-if="daftar.isSuccess.value" v-slot="a" label="Label sensitivitas" :petunjuk="petunjuk">
    <select v-bind="a" v-model="label" class="isian" :disabled="props.nonaktif || undefined">
      <option value="">Tanpa label</option>
      <option v-for="l in pilihan" :key="l.kode" :value="l.kode">{{ l.nama }}</option>
    </select>
  </KolomIsian>
  <p v-else-if="daftar.isError.value" class="ket">
    Label sensitivitas: {{ label || 'tanpa label' }}. Daftar label tidak bisa dimuat untuk akun Anda, jadi label belum bisa dipilih di sini.
  </p>
</template>

<style scoped>
.ket {
  margin: 0;
  font-size: 12px;
  color: var(--muted);
}
</style>
