<script setup lang="ts">
import { computed, nextTick, ref, useId } from 'vue'
import SimpulPohonItem from './SimpulPohonItem.vue'
import type { SimpulPohon } from './pohon'

/**
 * Pohon data yang bisa dilipat (unit organisasi, lokasi bertingkat). Pola tree WAI-ARIA dengan satu tab stop:
 * panah atas/bawah pindah, kanan membuka/masuk anak, kiri melipat/ke induk, Home/End ke ujung, Enter/Spasi memilih.
 * Isi label bisa diganti lewat slot `label` ({ simpul }).
 */
const props = defineProps<{ simpul: SimpulPohon[]; label: string }>()
const terpilih = defineModel<string | null>('terpilih', { default: null })
const terbuka = defineModel<string[]>('terbuka', { default: () => [] })
const emit = defineEmits<{ pilih: [simpul: SimpulPohon] }>()
defineSlots<{ label?(p: { simpul: SimpulPohon }): unknown }>()

const awalan = `pohon-${useId()}`
const fokus = ref<string | null>(null)

interface Info {
  simpul: SimpulPohon
  induk: string | null
}
const indeks = computed(() => {
  const peta = new Map<string, Info>()
  const jalan = (daftar: SimpulPohon[], induk: string | null) => {
    for (const s of daftar) {
      peta.set(s.id, { simpul: s, induk })
      if (s.anak?.length) jalan(s.anak, s.id)
    }
  }
  jalan(props.simpul, null)
  return peta
})
const setTerbuka = computed(() => new Set(terbuka.value))

/** Simpul yang terlihat berurutan (induk terbuka). */
const terlihat = computed(() => {
  const hasil: string[] = []
  const jalan = (daftar: SimpulPohon[]) => {
    for (const s of daftar) {
      hasil.push(s.id)
      if (s.anak?.length && setTerbuka.value.has(s.id)) jalan(s.anak)
    }
  }
  jalan(props.simpul)
  return hasil
})

/** Tab stop: yang difokus terakhir, lalu yang terpilih, lalu simpul pertama. */
const tabStop = computed(() => {
  const ada = (id: string | null) => id !== null && terlihat.value.includes(id)
  if (ada(fokus.value)) return fokus.value
  if (ada(terpilih.value)) return terpilih.value
  return terlihat.value[0] ?? null
})

function ubahTerbuka(id: string, buka: boolean) {
  const s = new Set(terbuka.value)
  if (buka) s.add(id)
  else s.delete(id)
  terbuka.value = [...s]
}

async function fokuskan(id: string | undefined | null) {
  if (!id) return
  fokus.value = id
  await nextTick()
  document.getElementById(`${awalan}-${id}`)?.focus()
}

function pilih(id: string) {
  const info = indeks.value.get(id)
  if (!info) return
  terpilih.value = id
  emit('pilih', info.simpul)
}

function tekan(e: KeyboardEvent, id: string) {
  const info = indeks.value.get(id)
  if (!info) return
  const punyaAnak = !!info.simpul.anak?.length
  const i = terlihat.value.indexOf(id)
  const tangani = () => {
    e.preventDefault()
    e.stopPropagation()
  }
  switch (e.key) {
    case 'ArrowDown':
      tangani()
      return void fokuskan(terlihat.value[i + 1])
    case 'ArrowUp':
      tangani()
      return void fokuskan(terlihat.value[i - 1])
    case 'Home':
      tangani()
      return void fokuskan(terlihat.value[0])
    case 'End':
      tangani()
      return void fokuskan(terlihat.value[terlihat.value.length - 1])
    case 'ArrowRight':
      tangani()
      if (!punyaAnak) return
      if (!setTerbuka.value.has(id)) return ubahTerbuka(id, true)
      return void fokuskan(info.simpul.anak![0]!.id)
    case 'ArrowLeft':
      tangani()
      if (punyaAnak && setTerbuka.value.has(id)) return ubahTerbuka(id, false)
      return void fokuskan(info.induk)
    case 'Enter':
    case ' ':
      tangani()
      return pilih(id)
  }
}

defineExpose({ fokuskan })
</script>

<template>
  <ul role="tree" class="pohon" :aria-label="label">
    <SimpulPohonItem
      v-for="(s, i) in simpul"
      :key="s.id"
      :simpul="s"
      :tingkat="1"
      :posisi="i + 1"
      :jumlah="simpul.length"
      :awalan="awalan"
      :terbuka="setTerbuka"
      :terpilih="terpilih"
      :tab-stop="tabStop"
      @tekan="tekan"
      @pilih="(id) => (pilih(id), fokuskan(id))"
      @ubah-terbuka="ubahTerbuka"
    >
      <template #label="{ simpul: s }"><slot name="label" :simpul="s">{{ s.label }}</slot></template>
    </SimpulPohonItem>
  </ul>
</template>

<style scoped>
.pohon {
  list-style: none;
  margin: 0;
  padding: 0;
}
</style>
