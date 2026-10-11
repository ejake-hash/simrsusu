<script setup lang="ts">
import { computed } from 'vue'
import { isiIkon } from './ikon'

const props = withDefaults(defineProps<{ nama: string; ukuran?: number }>(), { ukuran: 18 })
const isi = computed(() => isiIkon(props.nama))
</script>

<template>
  <!-- Dekoratif: makna selalu dari teks di sebelahnya atau aria-label tombolnya. -->
  <svg
    :width="ukuran"
    :height="ukuran"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.8"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
    v-html="isi"
  />
</template>
