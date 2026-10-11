<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useMenuSaya } from '@/app/menu'
import Dialog from '@/design-system/components/Dialog.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { cariMenu, indeksPencarian } from '@/modules/beranda/beranda'
import { pencarianTerbuka } from './pencarianMenu'

/**
 * Pencarian menu (Ctrl/Cmd+K): hanya menu dan sub-menu yang dikirim /saya/menu, jadi yang tidak diizinkan tidak
 * pernah muncul. Pola combobox WAI-ARIA: panah atas/bawah memilih, Enter membuka, Esc menutup.
 */
const router = useRouter()
const menu = useMenuSaya()
const id = `cari-menu-${useId()}`

const kueri = ref('')
const aktif = ref(0)
const kolom = ref<HTMLInputElement | null>(null)

const indeks = computed(() => (menu.data.value ? indeksPencarian(menu.data.value.grup) : []))
const hasil = computed(() => cariMenu(indeks.value, kueri.value))

watch(pencarianTerbuka, async (buka) => {
  if (!buka) return
  kueri.value = ''
  aktif.value = 0
  await nextTick()
  kolom.value?.focus()
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
  <Dialog v-model:open="pencarianTerbuka" judul="Cari menu" uraian="Hanya menu yang boleh Anda buka." lebar="lebar">
    <div class="cari">
      <Ikon nama="cari" :ukuran="16" />
      <input
        ref="kolom"
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
      <template v-if="!kueri.trim()">Ketik untuk mencari. Panah atas/bawah memilih, Enter membuka, Esc menutup.</template>
      <template v-else-if="hasil.length === 0">Tidak ada menu yang cocok dengan "{{ kueri }}" di antara menu Anda.</template>
      <template v-else>{{ hasil.length }} menu cocok.</template>
    </p>
  </Dialog>
</template>

<style scoped>
.cari {
  position: relative;
}
.cari svg {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--subtle);
}
.cari .isian {
  padding-left: 36px;
}
.hasil {
  list-style: none;
  margin: var(--space-2) 0 0;
  padding: 0;
}
.opsi {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  min-height: 44px;
  padding: 0 var(--space-3);
  border-radius: var(--radius-input);
  cursor: pointer;
}
.opsi.aktif {
  background: var(--brand-50);
  color: var(--brand-800);
}
.label {
  font-weight: var(--font-weight-medium);
}
.induk {
  font-size: 12.5px;
  color: var(--muted);
}
.opsi .pil {
  margin-left: auto;
}
.status {
  margin: var(--space-3) 0 0;
  font-size: 12.5px;
  color: var(--muted);
}
</style>
