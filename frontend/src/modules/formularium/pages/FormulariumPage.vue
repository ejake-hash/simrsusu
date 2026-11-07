<script setup lang="ts">
import { ref } from 'vue'
import Tabs from '@/design-system/components/Tabs.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import TabFormularium from '../components/TabFormularium.vue'
import TabKatalogBarang from '../components/TabKatalogBarang.vue'

/**
 * Farmasi → Formularium (tiket 2.11, kontrak v0.23.0): katalog obat/BMHP dan formularium RS beserta itemnya, diisi langsung
 * selama penyiapan. Sesudah siap melayani, perubahan formularium lewat Usulan perubahan (tiket 2.12); barang tetap langsung.
 */
const tab = ref('barang')
const daftarTab = [
  { nilai: 'barang', label: 'Katalog barang' },
  { nilai: 'formularium', label: 'Formularium' },
]
</script>

<template>
  <HalamanModul
    izin="farmasi.formularium.lihat"
    judul="Formularium"
    uraian="Katalog obat dan BMHP serta formularium yang dipakai e-resep. Setiap perubahan butuh dasar dan tercatat di jejak audit."
  >
    <Tabs v-model="tab" label="Bagian formularium" :daftar="daftarTab">
      <template #barang><TabKatalogBarang /></template>
      <template #formularium><TabFormularium /></template>
    </Tabs>
  </HalamanModul>
</template>
