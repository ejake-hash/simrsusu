<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Tabs from '@/design-system/components/Tabs.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import TabKsm from '../components/TabKsm.vue'
import TabUnit from '../components/TabUnit.vue'

/**
 * Master Data → Organisasi, unit & KSM (tiket 1.8a, FR-54-08 langkah 3). Tab di ?tab= agar bertahan saat muat
 * ulang. Unit organisasi menjadi cakupan penugasan (lapis 3) dan dasar modul aktif per unit.
 */
const route = useRoute()
const router = useRouter()
const DAFTAR = [
  { nilai: 'unit', label: 'Unit organisasi' },
  { nilai: 'ksm', label: 'KSM' },
]
const tab = computed({
  get: () => (route.query.tab === 'ksm' ? 'ksm' : 'unit'),
  set: (t: string) => void router.replace({ query: { tab: t === 'unit' ? undefined : t } }),
})
</script>

<template>
  <HalamanModul
    izin="master_data.organisasi.lihat"
    judul="Organisasi, unit & KSM"
    uraian="Struktur unit kerja (instalasi, bidang, ruang, poli, depo) dan Kelompok Staf Medis. Unit menjadi cakupan penugasan akun; setiap perubahan butuh dasar dan tercatat di jejak audit."
  >
    <Tabs v-model="tab" label="Data organisasi" :daftar="DAFTAR">
      <template #unit><TabUnit /></template>
      <template #ksm><TabKsm /></template>
    </Tabs>
  </HalamanModul>
</template>
