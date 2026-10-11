<script setup lang="ts">
import Ikon from '@/design-system/ikon/Ikon.vue'
import { bisaDibuka, type SimpulPohon } from './pohon'

/** Satu treeitem PohonData beserta anak-anaknya (rekursif). Logika keyboard ada di PohonData. */
const props = defineProps<{
  simpul: SimpulPohon
  tingkat: number
  posisi: number
  jumlah: number
  awalan: string
  terbuka: ReadonlySet<string>
  terpilih: string | null
  tabStop: string | null
}>()
const emit = defineEmits<{ tekan: [e: KeyboardEvent, id: string]; pilih: [id: string]; ubahTerbuka: [id: string, buka: boolean] }>()
defineSlots<{ label(p: { simpul: SimpulPohon }): unknown }>()

const punyaAnak = () => bisaDibuka(props.simpul)
const buka = () => props.terbuka.has(props.simpul.id)
</script>

<template>
  <li
    :id="`${awalan}-${simpul.id}`"
    role="treeitem"
    class="simpul"
    :aria-labelledby="simpul.keterangan ? `${awalan}-${simpul.id}-label ${awalan}-${simpul.id}-ket` : `${awalan}-${simpul.id}-label`"
    :aria-level="tingkat"
    :aria-posinset="posisi"
    :aria-setsize="jumlah"
    :aria-expanded="punyaAnak() ? buka() : undefined"
    :aria-selected="terpilih === simpul.id"
    :aria-busy="simpul.memuat || undefined"
    :tabindex="tabStop === simpul.id ? 0 : -1"
    @keydown="emit('tekan', $event, simpul.id)"
    @click.stop="emit('pilih', simpul.id)"
  >
    <div class="baris" :class="{ terpilih: terpilih === simpul.id }" :style="{ '--tingkat': tingkat - 1 }">
      <span v-if="punyaAnak()" class="lipat" aria-hidden="true" @click.stop="emit('ubahTerbuka', simpul.id, !buka())">
        <span v-if="simpul.memuat" class="tombol-pemutar" />
        <Ikon v-else :nama="buka() ? 'panah_bawah' : 'panah_kanan'" :ukuran="16" />
      </span>
      <span v-else class="lipat" />
      <!-- Nama treeitem = label dan keterangan baris ini saja, bukan seluruh teks anaknya. -->
      <span :id="`${awalan}-${simpul.id}-label`" class="label"><slot name="label" :simpul="simpul">{{ simpul.label }}</slot></span>
      <span v-if="simpul.keterangan" :id="`${awalan}-${simpul.id}-ket`" class="keterangan">{{ simpul.keterangan }}</span>
    </div>
    <ul v-if="simpul.anak?.length && buka()" role="group" class="grup">
      <SimpulPohonItem
        v-for="(a, i) in simpul.anak"
        :key="a.id"
        :simpul="a"
        :tingkat="tingkat + 1"
        :posisi="i + 1"
        :jumlah="simpul.anak!.length"
        :awalan="awalan"
        :terbuka="terbuka"
        :terpilih="terpilih"
        :tab-stop="tabStop"
        @tekan="(e, id) => emit('tekan', e, id)"
        @pilih="(id) => emit('pilih', id)"
        @ubah-terbuka="(id, b) => emit('ubahTerbuka', id, b)"
      >
        <template #label="{ simpul: s }"><slot name="label" :simpul="s" /></template>
      </SimpulPohonItem>
    </ul>
  </li>
</template>

<style scoped>
.simpul {
  list-style: none;
}
.simpul:focus {
  outline: none;
}
.simpul:focus-visible > .baris {
  box-shadow: var(--ring);
}
.grup {
  margin: 0;
  padding: 0;
}
.baris {
  display: flex;
  align-items: center;
  gap: 6px;
  min-height: 36px;
  padding: 2px var(--space-2) 2px calc(var(--space-2) + var(--tingkat) * 20px);
  border-radius: var(--radius-input);
  cursor: pointer;
}
.baris:hover {
  background: var(--bg);
}
.baris.terpilih {
  background: var(--brand-50);
  color: var(--brand-800);
  font-weight: var(--font-weight-medium);
}
.lipat {
  flex: none;
  display: grid;
  place-items: center;
  width: 24px;
  height: 24px;
  color: var(--muted);
}
.label {
  min-width: 0;
}
.keterangan {
  margin-left: auto;
  font-family: var(--font-mono);
  font-size: 11.5px;
  color: var(--muted);
}
/* Kelas tablet: baris dan tombol lipat 44 px (satu-satunya cara melipat dengan sentuhan). */
@media (min-width: 768px) and (max-width: 1279.98px) {
  .baris {
    min-height: 44px;
  }
  .lipat {
    width: 44px;
    height: 44px;
    margin: -2px 0 -2px -10px;
  }
}
</style>
