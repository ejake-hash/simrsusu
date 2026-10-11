<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Tabs from '@/design-system/components/Tabs.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import TabBangsal from '../components/lokasi/TabBangsal.vue'
import TabKelasRawat from '../components/lokasi/TabKelasRawat.vue'
import TabLokasi from '../components/lokasi/TabLokasi.vue'
import TabPoliklinik from '../components/lokasi/TabPoliklinik.vue'
import TabKsm from '../components/TabKsm.vue'
import TabUnit from '../components/TabUnit.vue'

/**
 * Master Data → Organisasi, Unit, Ruang & Tempat Tidur (PRD 9.1; tiket 1.8a unit & KSM, 1.8b lokasi, bangsal, kelas
 * rawat, poliklinik). Tab di ?tab= agar bertahan saat muat ulang. Unit menjawab siapa yang mengelola; lokasi menjawab
 * di mana (gedung → lantai → ruang → TT).
 */
const route = useRoute()
const router = useRouter()
const DAFTAR = [
  { nilai: 'unit', label: 'Unit organisasi' },
  { nilai: 'lokasi', label: 'Lokasi & tempat tidur' },
  { nilai: 'bangsal', label: 'Bangsal' },
  { nilai: 'kelas', label: 'Kelas rawat' },
  { nilai: 'poli', label: 'Poliklinik' },
  { nilai: 'ksm', label: 'KSM' },
]
const tab = computed({
  get: () => (DAFTAR.some((d) => d.nilai === route.query.tab) ? (route.query.tab as string) : 'unit'),
  set: (t: string) => void router.replace({ query: { tab: t === 'unit' ? undefined : t } }),
})
</script>

<template>
  <HalamanModul
    izin="master_data.organisasi.lihat"
    judul="Organisasi, Unit, Ruang & Tempat Tidur"
    uraian="Unit kerja dan KSM (siapa yang mengelola), lokasi fisik sampai tempat tidur (di mana), bangsal, kelas rawat, dan poliklinik. Setiap perubahan butuh dasar dan tercatat di jejak audit."
  >
    <Tabs v-model="tab" label="Data organisasi" :daftar="DAFTAR">
      <template #unit><TabUnit /></template>
      <template #lokasi><TabLokasi /></template>
      <template #bangsal><TabBangsal /></template>
      <template #kelas><TabKelasRawat /></template>
      <template #poli><TabPoliklinik /></template>
      <template #ksm><TabKsm /></template>
    </Tabs>
  </HalamanModul>
</template>
