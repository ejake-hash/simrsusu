<script setup lang="ts">
import { PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'reka-ui'
import { ref, useId, watch } from 'vue'
import Ikon from '@/design-system/ikon/Ikon.vue'

/**
 * Pencarian dalam popover non-modal di bawah tombol pemicu (PRD 5.1 butir 6: modal hanya untuk konfirmasi tindakan
 * berisiko). Pola combobox WAI-ARIA: panah atas/bawah memilih, Enter memilih, Esc menutup dan mengembalikan fokus ke
 * elemen yang aktif sebelum dibuka (tombol, kartu, atau tempat pintasan ditekan). Data dan pencarian milik pemakai.
 */
export interface ItemPencarian {
  kode: string
  label: string
  keterangan?: string | null
  pil?: string | null
}
const props = withDefaults(
  defineProps<{ label: string; uraian?: string; placeholder?: string; labelIsian: string; item: ItemPencarian[]; status: string; pintasan?: string; ariaPintasan?: string; ikon?: string }>(),
  { uraian: undefined, placeholder: undefined, pintasan: undefined, ariaPintasan: undefined, ikon: 'cari' },
)
const buka = defineModel<boolean>('open', { required: true })
const kueri = defineModel<string>('kueri', { required: true })
const emit = defineEmits<{ pilih: [item: ItemPencarian] }>()

const id = `pencarian-${useId()}`
const aktif = ref(0)
let asal: HTMLElement | null = null

watch(buka, (b) => {
  if (!b) return
  // Elemen asal untuk dikembalikan fokusnya saat Esc (pemicu bisa tombol lain atau pintasan papan ketik).
  asal = document.activeElement instanceof HTMLElement && document.activeElement !== document.body ? document.activeElement : null
  kueri.value = ''
  aktif.value = 0
})
watch(
  () => props.item,
  () => {
    aktif.value = 0
  },
)

function geser(arah: 1 | -1) {
  if (props.item.length === 0) return
  aktif.value = (aktif.value + arah + props.item.length) % props.item.length
}
function pilih(i: number) {
  const it = props.item[i]
  if (!it) return
  buka.value = false
  emit('pilih', it)
}
function kembalikanFokus(e: Event) {
  if (asal && asal.isConnected && !asal.closest('[data-pemicu-pencarian]')) {
    e.preventDefault()
    asal.focus()
  }
  asal = null
}
</script>

<template>
  <PopoverRoot v-model:open="buka">
    <PopoverTrigger as-child>
      <!-- aria-label tetap: di ponsel teksnya disembunyikan dan tombol hanya ikon. -->
      <button type="button" class="pemicu" data-pemicu-pencarian :aria-label="label" :aria-keyshortcuts="ariaPintasan">
        <Ikon :nama="ikon" :ukuran="16" />
        <span class="teks-pemicu">{{ label }}</span>
        <kbd v-if="pintasan" class="kbd">{{ pintasan }}</kbd>
      </button>
    </PopoverTrigger>
    <PopoverPortal>
      <PopoverContent class="panel-pencarian" align="end" side="bottom" :side-offset="8" :collision-padding="16" :aria-labelledby="`${id}-judul`" @close-auto-focus="kembalikanFokus">
        <p :id="`${id}-judul`" class="judul">{{ label }}<span v-if="uraian"> · {{ uraian }}</span></p>
        <div class="cari">
          <Ikon nama="cari" :ukuran="16" />
          <input
            v-model="kueri"
            class="isian"
            type="search"
            role="combobox"
            :aria-label="labelIsian"
            aria-autocomplete="list"
            :aria-expanded="item.length > 0"
            :aria-controls="`${id}-daftar`"
            :aria-activedescendant="item.length ? `${id}-${aktif}` : undefined"
            :placeholder="placeholder"
            autocomplete="off"
            @keydown.down.prevent="geser(1)"
            @keydown.up.prevent="geser(-1)"
            @keydown.enter.prevent="pilih(aktif)"
          />
        </div>
        <ul v-show="item.length" :id="`${id}-daftar`" role="listbox" :aria-label="`Hasil ${label.toLowerCase()}`" class="hasil">
          <li
            v-for="(it, i) in item"
            :id="`${id}-${i}`"
            :key="it.kode"
            role="option"
            :aria-selected="i === aktif"
            class="opsi"
            :class="{ aktif: i === aktif }"
            @mousedown.prevent
            @mousemove="aktif = i"
            @click="pilih(i)"
          >
            <span class="label">{{ it.label }}</span>
            <span v-if="it.keterangan" class="keterangan">{{ it.keterangan }}</span>
            <span v-if="it.pil" class="pil pil-netral">{{ it.pil }}</span>
          </li>
        </ul>
        <p class="status" role="status">{{ status }}</p>
      </PopoverContent>
    </PopoverPortal>
  </PopoverRoot>
</template>

<style scoped>
.pemicu {
  box-sizing: border-box;
  flex: none;
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  height: 38px;
  padding: 0 10px;
  border: 1px solid var(--border-input);
  border-radius: 10px;
  background: var(--surface);
  color: var(--muted);
  font: inherit;
  font-size: 13px;
  cursor: pointer;
}
.pemicu:hover {
  background: var(--bg);
  color: var(--text);
}
.pemicu:focus-visible {
  outline: none;
  border-color: var(--brand-600);
  box-shadow: var(--ring);
}
@media (min-width: 768px) and (max-width: 1279.98px) {
  .pemicu {
    height: 44px;
  }
}
@media (max-width: 767.98px) {
  .pemicu {
    width: 44px;
    height: 44px;
    justify-content: center;
    padding: 0;
  }
  .teks-pemicu,
  .pemicu .kbd {
    display: none;
  }
}
</style>

<style>
/* Tidak scoped: konten di-teleport ke body. */
.panel-pencarian {
  z-index: 40;
  box-sizing: border-box;
  width: min(480px, calc(100vw - 32px));
  padding: var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius-card);
  background: var(--surface);
  box-shadow: var(--shadow-laci);
  color: var(--text);
}
.panel-pencarian:focus {
  outline: none;
}
.panel-pencarian .judul {
  margin: 0 0 var(--space-2);
  font-size: 13px;
  font-weight: var(--font-weight-semibold);
}
.panel-pencarian .judul span {
  color: var(--muted);
  font-weight: var(--font-weight-regular);
}
.panel-pencarian .cari {
  position: relative;
}
.panel-pencarian .cari svg {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--subtle);
}
.panel-pencarian .cari .isian {
  padding-left: 36px;
}
.panel-pencarian .hasil {
  max-height: min(50vh, 360px);
  overflow-y: auto;
  margin: var(--space-2) 0 0;
  padding: 0;
  list-style: none;
}
.panel-pencarian .opsi {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  min-height: 44px;
  padding: 0 var(--space-3);
  border-radius: var(--radius-input);
  cursor: pointer;
}
.panel-pencarian .opsi.aktif {
  background: var(--brand-50);
  color: var(--brand-800);
}
.panel-pencarian .label {
  font-weight: var(--font-weight-medium);
}
.panel-pencarian .keterangan {
  font-size: 12.5px;
  color: var(--muted);
}
.panel-pencarian .opsi .pil {
  margin-left: auto;
}
.panel-pencarian .status {
  margin: var(--space-2) 0 0;
  font-size: 12.5px;
  color: var(--muted);
}
</style>
