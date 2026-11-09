<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'
import { computed, watch } from 'vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { daftarPenjamin } from '@/modules/penjamin/api'
import { LABEL_CARA_BAYAR } from '../kunjungan'
import type { CaraBayar } from '../tipeKunjungan'

/**
 * Cara bayar (JKN/non-JKN) dan penjamin BPJS untuk pendaftaran kunjungan poli (2.6) dan IGD (2.23). Penjamin dari
 * data induk (butuh master_data.penjamin.lihat); gagal dimuat tampil sebagai error di kolomnya. Dua elemen akar agar
 * ikut kisi pemanggil.
 */
const props = defineProps<{ nama: string; errorPenjamin?: string }>()
const caraBayar = defineModel<CaraBayar>('caraBayar', { required: true })
const penjaminId = defineModel<string>('penjaminId', { required: true })

const penjaminBpjs = useQuery({
  queryKey: ['kunjungan', 'penjamin-bpjs'],
  queryFn: async () => (await daftarPenjamin({ status: 'aktif', jenis: 'bpjs' }, null, 200)).data,
  enabled: computed(() => caraBayar.value === 'jkn'),
  staleTime: 60_000,
})
// Bila hanya satu penjamin BPJS, pilih otomatis supaya petugas tidak menebak.
watch([caraBayar, () => penjaminBpjs.data.value], () => {
  const d = penjaminBpjs.data.value ?? []
  if (caraBayar.value === 'jkn' && !penjaminId.value && d.length === 1) penjaminId.value = d[0]!.id
  if (caraBayar.value !== 'jkn') penjaminId.value = ''
}, { immediate: true })

const errorKolom = computed(() => props.errorPenjamin ?? (penjaminBpjs.error.value ? `Daftar penjamin tidak dapat dimuat: ${pesanError(penjaminBpjs.error.value)}` : undefined))
</script>

<template>
  <fieldset class="kolom-bayar">
    <legend class="kolom-label">Cara bayar <span class="kolom-wajib" aria-hidden="true">*</span></legend>
    <label v-for="(label, nilai) in LABEL_CARA_BAYAR" :key="nilai" class="radio">
      <input v-model="caraBayar" type="radio" :name="nama" :value="nilai" />
      <span>{{ label }}</span>
    </label>
  </fieldset>
  <KolomIsian v-if="caraBayar === 'jkn'" v-slot="a" label="Penjamin (BPJS)" wajib :error="errorKolom">
    <select v-bind="a" v-model="penjaminId" class="isian">
      <option value="" disabled>Pilih penjamin…</option>
      <option v-for="pj in penjaminBpjs.data.value ?? []" :key="pj.id" :value="pj.id">{{ pj.nama }}</option>
    </select>
  </KolomIsian>
</template>

<style scoped>
.kolom-bayar {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
  margin: 0;
  padding: 0;
  border: 0;
}
.radio {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  min-height: 32px;
  font-size: 14px;
  cursor: pointer;
}
</style>
