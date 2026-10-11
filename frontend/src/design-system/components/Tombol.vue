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
/** `disabled` dari pemakai = benar-benar nonaktif. */
const nonaktif = computed(() => attrs.disabled !== undefined && attrs.disabled !== false)
/** Atribut selain onClick (klik ditangani sendiri agar sibuk bisa menahannya tanpa melepas fokus). */
const atributLain = computed(() => Object.fromEntries(Object.entries(attrs).filter(([k]) => k !== 'onClick' && k !== 'disabled')))

/**
 * Sibuk tanpa `disabled`: tombol tetap fokus (aria-disabled), klik dan kirim formulir ditahan. Melepas `disabled`
 * pada tombol yang sedang difokus membuat fokus jatuh ke body, jadi sibuk tidak memakai atribut itu.
 */
function klik(e: MouseEvent) {
  if (props.sibuk) {
    e.preventDefault()
    e.stopImmediatePropagation()
    return
  }
  const h = attrs.onClick as ((e: MouseEvent) => void) | ((e: MouseEvent) => void)[] | undefined
  if (Array.isArray(h)) h.forEach((f) => f(e))
  else h?.(e)
}
</script>

<template>
  <button
    v-bind="atributLain"
    :type="type"
    :disabled="nonaktif || undefined"
    :aria-disabled="sibuk && !nonaktif ? 'true' : undefined"
    class="tombol"
    :class="[varian !== 'kedua' && `tombol-${varian}`, { 'tombol-besar': ukuran === 'besar', 'tombol-penuh': penuh, 'tombol-sibuk': sibuk }]"
    :aria-busy="sibuk || undefined"
    @click="klik"
  >
    <span v-if="sibuk" class="tombol-pemutar" aria-hidden="true" />
    <slot />
  </button>
</template>
