<script setup lang="ts">
import HalamanModul from '@/layouts/HalamanModul.vue'
import DaftarLog from '../components/DaftarLog.vue'

/** Integrasi dan Laporan → Log integrasi (tiket 1.9 fe 1; izin integrasi.log.lihat). */
</script>

<template>
  <HalamanModul izin="integrasi.log.lihat" judul="Log integrasi" uraian="Jejak panggilan ke BPJS dan SATUSEHAT dengan data pribadi disamarkan, untuk menelusuri kiriman yang gagal.">
    <DaftarLog />
  </HalamanModul>
</template>
