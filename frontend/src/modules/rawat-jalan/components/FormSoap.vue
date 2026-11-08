<script setup lang="ts">
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import type { IsianSoap } from '../rawatJalan'
import PilihLabel from './PilihLabel.vue'

/**
 * Formulir SOAP polos (FR-07-04). Templat SOAP per poli menunggu data induk templat RS; sampai ada, semua poli memakai
 * empat bagian teks bebas ini. Minimal satu bagian terisi (diperiksa server).
 */
const f = defineModel<IsianSoap>({ required: true })
const BAGIAN = [
  { kunci: 'subjektif', label: 'S · Subjektif', petunjuk: 'Keluhan dan riwayat menurut pasien atau keluarga.' },
  { kunci: 'objektif', label: 'O · Objektif', petunjuk: 'Hasil pemeriksaan fisik dan penunjang.' },
  { kunci: 'asesmen', label: 'A · Asesmen', petunjuk: 'Penilaian atau diagnosis kerja.' },
  { kunci: 'plan', label: 'P · Plan', petunjuk: 'Rencana tata laksana, edukasi, dan tindak lanjut.' },
] as const
</script>

<template>
  <div class="soap">
    <KolomIsian v-for="b in BAGIAN" :key="b.kunci" v-slot="a" :label="b.label" :petunjuk="b.petunjuk">
      <textarea v-bind="a" v-model="f[b.kunci]" class="isian" rows="3" maxlength="4000"></textarea>
    </KolomIsian>
    <PilihLabel v-model="f.label" />
  </div>
</template>

<style scoped>
.soap {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: var(--space-3);
}
</style>
