<script setup lang="ts">
import Ikon from '@/design-system/ikon/Ikon.vue'

/**
 * Lencana/pil status (PRD 5.1 butir 4: status tidak hanya warna). Teks wajib lewat slot; ikon bawaan per jenis
 * menegaskan makna untuk pengguna buta warna. `tanpaIkon` untuk penanda netral di tabel padat.
 */
const props = withDefaults(defineProps<{ jenis?: 'ok' | 'peringatan' | 'bahaya' | 'info' | 'netral' | 'merek'; tanpaIkon?: boolean }>(), {
  jenis: 'netral',
  tanpaIkon: false,
})
const IKON: Partial<Record<NonNullable<typeof props.jenis>, string>> = { ok: 'centang', peringatan: 'peringatan', bahaya: 'peringatan', info: 'info' }
</script>

<template>
  <span class="pil" :class="`pil-${jenis}`">
    <Ikon v-if="!tanpaIkon && IKON[jenis]" :nama="IKON[jenis]!" :ukuran="12" />
    <slot />
  </span>
</template>
