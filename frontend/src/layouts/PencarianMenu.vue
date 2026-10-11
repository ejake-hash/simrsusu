<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useMenuSaya } from '@/app/menu'
import PopoverPencarian, { type ItemPencarian } from '@/design-system/components/PopoverPencarian.vue'
import { cariMenu, indeksPencarian } from '@/modules/beranda/beranda'
import { labelPintasan, pencarianTerbuka } from './pencarianMenu'

/**
 * Pencarian menu (tombol header dan Ctrl/Cmd+K) di atas PopoverPencarian: hanya menu dan sub-menu yang dikirim
 * /saya/menu, jadi yang tidak diizinkan tidak pernah muncul.
 */
const router = useRouter()
const menu = useMenuSaya()
const pintasan = labelPintasan()
const kueri = ref('')

const cari = computed(() => cariMenu(menu.data.value ? indeksPencarian(menu.data.value.grup) : [], kueri.value))
const item = computed<ItemPencarian[]>(() => cari.value.hasil.map((i) => ({ kode: i.kode, label: i.label, keterangan: i.induk, pil: i.segera ? 'Segera hadir' : null })))
const status = computed(() => {
  if (!menu.data.value) return 'Memuat menu…'
  if (!kueri.value.trim()) return 'Ketik untuk mencari. Panah atas/bawah memilih, Enter membuka, Esc menutup.'
  if (cari.value.total === 0) return `Tidak ada menu yang cocok dengan "${kueri.value}" di antara menu Anda.`
  if (cari.value.total > cari.value.hasil.length) return `${cari.value.total} menu cocok, ${cari.value.hasil.length} teratas ditampilkan. Persempit pencarian.`
  return `${cari.value.total} menu cocok.`
})

async function buka(it: ItemPencarian) {
  const tujuan = cari.value.hasil.find((i) => i.kode === it.kode)
  if (tujuan) await router.push(tujuan.rute)
}
</script>

<template>
  <PopoverPencarian
    v-model:open="pencarianTerbuka"
    v-model:kueri="kueri"
    label="Cari menu"
    uraian="hanya menu yang boleh Anda buka"
    label-isian="Nama menu atau sub-menu"
    placeholder="Ketik nama menu, mis. akun atau peran"
    ikon="kisi"
    :item="item"
    :status="status"
    :pintasan="pintasan"
    :aria-pintasan="pintasan === '⌘K' ? 'Meta+K' : 'Control+K'"
    @pilih="buka"
  />
</template>
