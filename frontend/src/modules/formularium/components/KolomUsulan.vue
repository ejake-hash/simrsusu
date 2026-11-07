<script setup lang="ts">
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { hariIni } from '../usulanFormularium'

/** Isian tambahan mode usulan (tiket 2.12): tanggal berlaku dan alasan; dasar perubahan dialog menjadi dasar usulan. */
defineProps<{ kesalahan: Record<string, string> }>()
const berlaku = defineModel<string>('berlaku', { required: true })
const alasan = defineModel<string>('alasan', { required: true })
</script>

<template>
  <p class="catatan catatan-info" role="status">
    <Ikon nama="info" :ukuran="16" />
    <span>Instalasi sudah melayani pasien, jadi perubahan ini diajukan sebagai usulan: diverifikasi dan disetujui orang lain, lalu berlaku mulai tanggal yang dipilih.</span>
  </p>
  <KolomIsian v-slot="a" label="Berlaku mulai" :error="kesalahan.berlaku_dari" petunjuk="Diterapkan pukul 00.00 waktu RS." wajib>
    <input v-bind="a" v-model="berlaku" type="date" class="isian" :min="hariIni()" />
  </KolomIsian>
  <KolomIsian v-slot="a" label="Alasan usulan" :error="kesalahan.alasan" wajib>
    <textarea v-bind="a" v-model="alasan" class="isian" rows="2" maxlength="500" />
  </KolomIsian>
</template>
