<script setup lang="ts">
import Ikon from '@/design-system/ikon/Ikon.vue'

/**
 * Pengganti isi data berlabel sensitivitas yang tidak boleh dibuka akun ini (lapis 4, PRD 9.2.2; tiket 1.5 fe 2).
 * Sengaja tidak menyebut labelnya (mis. HIV, psikiatri): nama label sendiri sudah data sensitif. Tidak ada tombol
 * "minta akses" di sini; akses diberikan lewat izin label + relasi perawatan, bukan dari layar data.
 */
withDefaults(defineProps<{ ringkas?: boolean }>(), { ringkas: false })
</script>

<template>
  <span class="terbatas" role="note" aria-label="Terbatas: data sensitif, hanya untuk tenaga yang berwenang dan merawat pasien">
    <Ikon nama="gembok" :ukuran="12" />
    <span aria-hidden="true">Terbatas</span>
    <span v-if="!ringkas" class="terbatas-uraian" aria-hidden="true">Data sensitif; hanya untuk tenaga yang berwenang dan merawat pasien.</span>
  </span>
</template>

<style scoped>
.terbatas {
  display: inline-flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  max-width: 100%;
  padding: 2px 8px;
  border: 1px dashed var(--border-input);
  border-radius: 999px;
  background: var(--bg);
  color: var(--text);
  font-size: 12.5px;
  font-weight: 600;
}
.terbatas-uraian {
  font-weight: 400;
  color: var(--muted);
}
</style>
