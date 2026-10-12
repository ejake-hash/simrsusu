<script setup lang="ts">
import HalamanModul from '@/layouts/HalamanModul.vue'
import DaftarSistem from '../components/DaftarSistem.vue'

/** Pengaturan → Konfigurasi integrasi (tiket 1.9 fe 1): status, kredensial tulis-saja, uji koneksi per sistem. */
</script>

<template>
  <HalamanModul
    izin="pengaturan.integrasi.lihat"
    judul="Konfigurasi integrasi"
    uraian="Kredensial dan status BPJS VClaim, Aplicares, dan SATUSEHAT. Alamat sistem luar diatur di server; nilai rahasia tidak pernah ditampilkan."
  >
    <DaftarSistem />
  </HalamanModul>
</template>
