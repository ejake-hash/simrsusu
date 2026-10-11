<script setup lang="ts">
/**
 * Tombol bersama (kelas dasar di dasar.css). `utama` satu per layar untuk aksi utama; `bahaya` untuk aksi yang
 * mengurangi akses atau tidak bisa dibatalkan; `tautan` untuk aksi sekunder bergaya teks. `besar` = 44 px
 * (formulir masuk, ponsel). `sibuk` menampilkan pemutar, `aria-busy`, dan menahan klik ganda.
 * Atribut lain (disabled, aria-*, form) diteruskan ke elemen button.
 */
withDefaults(
  defineProps<{ varian?: 'utama' | 'kedua' | 'bahaya' | 'tautan'; ukuran?: 'sedang' | 'besar'; penuh?: boolean; sibuk?: boolean; type?: 'button' | 'submit' }>(),
  { varian: 'kedua', ukuran: 'sedang', penuh: false, sibuk: false, type: 'button' },
)
</script>

<template>
  <button
    :type="type"
    class="tombol"
    :class="[varian !== 'kedua' && `tombol-${varian}`, { 'tombol-besar': ukuran === 'besar', 'tombol-penuh': penuh, 'tombol-sibuk': sibuk }]"
    :aria-busy="sibuk || undefined"
  >
    <span v-if="sibuk" class="tombol-pemutar" aria-hidden="true" />
    <slot />
  </button>
</template>
