<script setup lang="ts">
import HalamanModul from '@/layouts/HalamanModul.vue'
import DaftarLangkah from '../components/DaftarLangkah.vue'

/** Pengaturan → Penyiapan instalasi (tiket 1.8f fe 1, FR-54-08). */
</script>

<template>
  <HalamanModul
    izin="pengaturan.penyiapan.lihat"
    judul="Penyiapan instalasi"
    uraian="Langkah wajib sebelum instalasi melayani pasien (FR-54-08). Data diisi di layar masing-masing; setiap langkah dikonfirmasi berurutan dan tercatat di jejak audit."
  >
    <DaftarLangkah />
  </HalamanModul>
</template>
