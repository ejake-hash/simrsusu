<script setup lang="ts">
import { boleh } from '@/shared/composables/usePermission'

/** Merender isi hanya bila akun punya izin `izin`; slot `tolak` (opsional) bila tidak. */
defineProps<{ izin: string }>()
</script>

<template>
  <slot v-if="boleh(izin)" />
  <slot v-else name="tolak" />
</template>
