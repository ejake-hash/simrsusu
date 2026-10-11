<script setup lang="ts">
import Ikon from '@/design-system/ikon/Ikon.vue'

/**
 * Keadaan kosong, error, atau memuat untuk daftar dan panel. Kalimat menyebut apa yang terjadi dan langkah
 * berikutnya; tanpa angka atau data tiruan. Aksi (Coba lagi, Tambah …) lewat slot `aksi`.
 * `memuat` = role status (dibacakan sopan); `error` = role alert.
 */
const props = withDefaults(defineProps<{ judul: string; uraian?: string; jenis?: 'kosong' | 'error' | 'memuat'; ikon?: string }>(), {
  uraian: undefined,
  jenis: 'kosong',
  ikon: undefined,
})
const IKON = { kosong: 'info', error: 'peringatan', memuat: 'jam' } as const
</script>

<template>
  <div class="keadaan" :class="`keadaan-${jenis}`" :role="jenis === 'error' ? 'alert' : jenis === 'memuat' ? 'status' : undefined" :aria-busy="jenis === 'memuat' || undefined">
    <span class="keadaan-ikon"><Ikon :nama="props.ikon ?? IKON[jenis]" :ukuran="20" /></span>
    <p class="keadaan-judul">{{ judul }}</p>
    <p v-if="uraian" class="keadaan-uraian">{{ uraian }}</p>
    <div v-if="$slots.aksi" class="keadaan-aksi"><slot name="aksi" /></div>
  </div>
</template>

<style scoped>
.keadaan {
  display: grid;
  justify-items: center;
  gap: var(--space-1);
  padding: var(--space-6) var(--space-4);
  text-align: center;
  color: var(--muted);
}
.keadaan-ikon {
  display: grid;
  place-items: center;
  width: 40px;
  height: 40px;
  margin-bottom: var(--space-1);
  border-radius: 50%;
  background: var(--bg);
  color: var(--subtle);
}
.keadaan-error .keadaan-ikon {
  background: var(--danger-bg);
  color: var(--danger);
}
.keadaan-judul {
  margin: 0;
  color: var(--text);
  font-weight: var(--font-weight-semibold);
}
.keadaan-uraian {
  max-width: 48ch;
  margin: 0;
  font-size: 13px;
}
.keadaan-aksi {
  margin-top: var(--space-2);
}
.keadaan-memuat .keadaan-ikon {
  animation: keadaan-denyut 1.2s ease-in-out infinite;
}
@keyframes keadaan-denyut {
  50% {
    opacity: 0.4;
  }
}
@media (prefers-reduced-motion: reduce) {
  .keadaan-memuat .keadaan-ikon {
    animation: none;
  }
}
</style>
