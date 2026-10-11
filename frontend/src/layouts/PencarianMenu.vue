<script setup lang="ts">
import { PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'reka-ui'
import { computed, ref, useId, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useMenuSaya } from '@/app/menu'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { cariMenu, indeksPencarian } from '@/modules/beranda/beranda'
import { labelPintasan, pencarianTerbuka } from './pencarianMenu'

/**
 * Pencarian menu (tombol header dan Ctrl/Cmd+K): popover non-modal di bawah tombol, bukan modal (PRD 5.1 butir 6:
 * modal hanya untuk konfirmasi tindakan berisiko). Hanya menu dan sub-menu yang dikirim /saya/menu, jadi yang tidak
 * diizinkan tidak pernah muncul. Pola combobox WAI-ARIA: panah atas/bawah memilih, Enter membuka, Esc menutup dan
 * mengembalikan fokus ke tombol.
 */
const router = useRouter()
const menu = useMenuSaya()
const id = `cari-menu-${useId()}`
const pintasan = labelPintasan()

const kueri = ref('')
const aktif = ref(0)

const indeks = computed(() => (menu.data.value ? indeksPencarian(menu.data.value.grup) : []))
const cari = computed(() => cariMenu(indeks.value, kueri.value))
const hasil = computed(() => cari.value.hasil)

watch(pencarianTerbuka, (buka) => {
  if (!buka) return
  kueri.value = ''
  aktif.value = 0
})
watch(hasil, () => {
  aktif.value = 0
})

function geser(arah: 1 | -1) {
  if (hasil.value.length === 0) return
  aktif.value = (aktif.value + arah + hasil.value.length) % hasil.value.length
}

async function buka(i: number) {
  const item = hasil.value[i]
  if (!item) return
  pencarianTerbuka.value = false
  await router.push(item.rute)
}
</script>

<template>
  <PopoverRoot v-model:open="pencarianTerbuka">
    <PopoverTrigger as-child>
      <!-- aria-label tetap: di ponsel teksnya disembunyikan dan tombol hanya ikon. -->
      <button type="button" class="tombol-cari-menu" aria-label="Cari menu" :aria-keyshortcuts="pintasan === '⌘K' ? 'Meta+K' : 'Control+K'">
        <Ikon nama="kisi" :ukuran="16" />
        <span class="teks-cari-menu">Cari menu</span>
        <kbd class="kbd">{{ pintasan }}</kbd>
      </button>
    </PopoverTrigger>
    <PopoverPortal>
      <PopoverContent class="panel-cari-menu" align="end" side="bottom" :side-offset="8" :collision-padding="16" :aria-labelledby="`${id}-judul`">
        <p :id="`${id}-judul`" class="judul">Cari menu <span>· hanya menu yang boleh Anda buka</span></p>
        <div class="cari">
          <Ikon nama="cari" :ukuran="16" />
          <input
            v-model="kueri"
            class="isian"
            type="search"
            role="combobox"
            aria-label="Nama menu atau sub-menu"
            aria-autocomplete="list"
            :aria-expanded="hasil.length > 0"
            :aria-controls="`${id}-daftar`"
            :aria-activedescendant="hasil.length ? `${id}-${aktif}` : undefined"
            placeholder="Ketik nama menu, mis. akun atau peran"
            autocomplete="off"
            @keydown.down.prevent="geser(1)"
            @keydown.up.prevent="geser(-1)"
            @keydown.enter.prevent="buka(aktif)"
          />
        </div>
        <ul v-show="hasil.length" :id="`${id}-daftar`" role="listbox" aria-label="Hasil pencarian menu" class="hasil">
          <li
            v-for="(item, i) in hasil"
            :id="`${id}-${i}`"
            :key="item.kode"
            role="option"
            :aria-selected="i === aktif"
            class="opsi"
            :class="{ aktif: i === aktif }"
            @mousedown.prevent
            @mousemove="aktif = i"
            @click="buka(i)"
          >
            <span class="label">{{ item.label }}</span>
            <span v-if="item.induk" class="induk">{{ item.induk }}</span>
            <span v-if="item.segera" class="pil pil-netral">Segera hadir</span>
          </li>
        </ul>
        <p class="status" role="status">
          <template v-if="!menu.data.value">Memuat menu…</template>
          <template v-else-if="!kueri.trim()">Ketik untuk mencari. Panah atas/bawah memilih, Enter membuka, Esc menutup.</template>
          <template v-else-if="cari.total === 0">Tidak ada menu yang cocok dengan "{{ kueri }}" di antara menu Anda.</template>
          <template v-else-if="cari.total > hasil.length">{{ cari.total }} menu cocok, {{ hasil.length }} teratas ditampilkan. Persempit pencarian.</template>
          <template v-else>{{ cari.total }} menu cocok.</template>
        </p>
      </PopoverContent>
    </PopoverPortal>
  </PopoverRoot>
</template>

<style scoped>
.tombol-cari-menu {
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
.tombol-cari-menu:hover {
  background: var(--bg);
  color: var(--text);
}
.tombol-cari-menu:focus-visible {
  outline: none;
  border-color: var(--brand-600);
  box-shadow: var(--ring);
}
@media (min-width: 768px) and (max-width: 1279.98px) {
  .tombol-cari-menu {
    height: 44px;
  }
}
@media (max-width: 767.98px) {
  .tombol-cari-menu {
    width: 44px;
    height: 44px;
    justify-content: center;
    padding: 0;
  }
  .teks-cari-menu,
  .tombol-cari-menu .kbd {
    display: none;
  }
}
</style>

<style>
/* Tidak scoped: konten di-teleport ke body. */
.panel-cari-menu {
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
.panel-cari-menu:focus {
  outline: none;
}
.panel-cari-menu .judul {
  margin: 0 0 var(--space-2);
  font-size: 13px;
  font-weight: var(--font-weight-semibold);
}
.panel-cari-menu .judul span {
  color: var(--muted);
  font-weight: var(--font-weight-regular);
}
.panel-cari-menu .cari {
  position: relative;
}
.panel-cari-menu .cari svg {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--subtle);
}
.panel-cari-menu .cari .isian {
  padding-left: 36px;
}
.panel-cari-menu .hasil {
  max-height: min(50vh, 360px);
  overflow-y: auto;
  margin: var(--space-2) 0 0;
  padding: 0;
  list-style: none;
}
.panel-cari-menu .opsi {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  min-height: 44px;
  padding: 0 var(--space-3);
  border-radius: var(--radius-input);
  cursor: pointer;
}
.panel-cari-menu .opsi.aktif {
  background: var(--brand-50);
  color: var(--brand-800);
}
.panel-cari-menu .label {
  font-weight: var(--font-weight-medium);
}
.panel-cari-menu .induk {
  font-size: 12.5px;
  color: var(--muted);
}
.panel-cari-menu .opsi .pil {
  margin-left: auto;
}
.panel-cari-menu .status {
  margin: var(--space-2) 0 0;
  font-size: 12.5px;
  color: var(--muted);
}
</style>
