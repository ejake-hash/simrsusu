<script setup lang="ts">
import { computed, useAttrs } from 'vue'

/**
 * Tombol bersama (kelas dasar di dasar.css). `utama` satu per layar untuk aksi utama; `bahaya` untuk aksi yang
 * mengurangi akses atau tidak bisa dibatalkan; `tautan` untuk aksi sekunder bergaya teks. `besar` = 44 px
 * (formulir masuk, ponsel). `sibuk` menampilkan pemutar, `aria-busy`, dan menahan klik ganda.
 * Atribut lain (disabled, aria-*, form) diteruskan ke elemen button.
 */
const props = withDefaults(
  defineProps<{ varian?: 'utama' | 'kedua' | 'bahaya' | 'tautan'; ukuran?: 'sedang' | 'besar'; penuh?: boolean; sibuk?: boolean; type?: 'button' | 'submit' }>(),
  { varian: 'kedua', ukuran: 'sedang', penuh: false, sibuk: false, type: 'button' },
)
defineOptions({ inheritAttrs: false })
const attrs = useAttrs()
/** Sibuk selalu menahan klik (dan kirim formulir), apa pun nilai `disabled` dari pemakai. */
const nonaktif = computed(() => props.sibuk || (attrs.disabled !== undefined && attrs.disabled !== false))
</script>

<template>
  <button
    v-bind="$attrs"
    :type="type"
    :disabled="nonaktif"
    class="tombol"
    :class="[varian !== 'kedua' && `tombol-${varian}`, { 'tombol-besar': ukuran === 'besar', 'tombol-penuh': penuh, 'tombol-sibuk': sibuk }]"
    :aria-busy="sibuk || undefined"
  >
    <span v-if="sibuk" class="tombol-pemutar" aria-hidden="true" />
    <slot />
  </button>
</template>
