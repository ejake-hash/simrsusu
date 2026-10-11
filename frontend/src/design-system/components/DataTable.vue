<script setup lang="ts" generic="T">
import { computed, ref } from 'vue'
import KeadaanKosong from './KeadaanKosong.vue'
import Tombol from './Tombol.vue'

/**
 * Tabel daftar (PRD 5.4, 14.1.1). Data dari endpoint berkursor: tombol "Muat lagi" saat `adaLagi`, tidak ada
 * nomor halaman. Kolom berprioritas: 1 selalu tampil, 2 disembunyikan di tablet tegak (< 1024 px), 3 di tablet
 * (< 1280 px); halaman tidak bergulir ke samping, tabel boleh di dalam kotaknya (PRD 5.5 aturan 3).
 * `virtual` + `tinggiBaris` merender hanya baris yang terlihat untuk daftar ribuan baris di dalam kotak setinggi
 * `tinggiMaks`. Isi sel lewat slot `sel-<kunci>` ({ baris }); baris bisa dipilih dengan klik atau Enter bila
 * `@pilih` dipasang.
 */
export interface KolomTabel {
  kunci: string
  label: string
  prioritas?: 1 | 2 | 3
  rata?: 'kiri' | 'kanan'
  lebar?: string
}
const props = withDefaults(
  defineProps<{
    kolom: KolomTabel[]
    baris: T[]
    kunciBaris: (baris: T) => string
    label: string
    memuat?: boolean
    memuatLagi?: boolean
    error?: string | null
    adaLagi?: boolean
    terpilih?: string | null
    dapatDipilih?: boolean
    virtual?: boolean
    tinggiBaris?: number
    tinggiMaks?: number
    teksKosong?: string
  }>(),
  {
    memuat: false,
    memuatLagi: false,
    error: null,
    adaLagi: false,
    terpilih: null,
    dapatDipilih: false,
    virtual: false,
    tinggiBaris: 44,
    tinggiMaks: 560,
    teksKosong: 'Belum ada data.',
  },
)
const emit = defineEmits<{ muatLagi: []; cobaLagi: []; pilih: [baris: T] }>()

const posisiGulir = ref(0)
const CADANGAN = 8
const jendela = computed(() => {
  if (!props.virtual) return { mulai: 0, akhir: props.baris.length }
  const mulai = Math.max(0, Math.floor(posisiGulir.value / props.tinggiBaris) - CADANGAN)
  const muat = Math.ceil(props.tinggiMaks / props.tinggiBaris) + CADANGAN * 2
  return { mulai, akhir: Math.min(props.baris.length, mulai + muat) }
})
const barisTampil = computed(() => props.baris.slice(jendela.value.mulai, jendela.value.akhir).map((b, i) => ({ b, i: jendela.value.mulai + i })))
const ruangAtas = computed(() => jendela.value.mulai * props.tinggiBaris)
const ruangBawah = computed(() => (props.baris.length - jendela.value.akhir) * props.tinggiBaris)

function gulir(e: Event) {
  posisiGulir.value = (e.target as HTMLElement).scrollTop
}
function nilai(b: T, kunci: string): unknown {
  return (b as Record<string, unknown>)[kunci]
}
</script>

<template>
  <div class="tabel-data">
    <KeadaanKosong v-if="memuat" jenis="memuat" :judul="`Memuat ${label.toLowerCase()}…`" />
    <KeadaanKosong v-else-if="error" jenis="error" judul="Data gagal dimuat" :uraian="error">
      <template #aksi><Tombol @click="emit('cobaLagi')">Coba lagi</Tombol></template>
    </KeadaanKosong>
    <slot v-else-if="baris.length === 0" name="kosong"><KeadaanKosong :judul="teksKosong" /></slot>
    <template v-else>
      <div
        class="gulir"
        role="region"
        :aria-label="label"
        tabindex="0"
        :style="virtual ? { maxHeight: `${tinggiMaks}px` } : undefined"
        @scroll="virtual ? gulir($event) : undefined"
      >
        <table class="tbl" :aria-rowcount="virtual ? baris.length + 1 : undefined">
          <thead>
            <tr>
              <th v-for="k in kolom" :key="k.kunci" scope="col" :class="[`p${k.prioritas ?? 1}`, k.rata === 'kanan' && 'kanan']" :style="k.lebar ? { width: k.lebar } : undefined">
                {{ k.label }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="ruangAtas" aria-hidden="true" :style="{ height: `${ruangAtas}px` }"><td :colspan="kolom.length" /></tr>
            <tr
              v-for="{ b, i } in barisTampil"
              :key="kunciBaris(b)"
              :aria-rowindex="virtual ? i + 2 : undefined"
              :class="{ terpilih: terpilih === kunciBaris(b), dapat: dapatDipilih }"
              :style="virtual ? { height: `${tinggiBaris}px` } : undefined"
              :tabindex="dapatDipilih ? 0 : undefined"
              :aria-selected="dapatDipilih ? terpilih === kunciBaris(b) : undefined"
              @click="dapatDipilih && emit('pilih', b)"
              @keydown.enter="dapatDipilih && emit('pilih', b)"
            >
              <td v-for="k in kolom" :key="k.kunci" :class="[`p${k.prioritas ?? 1}`, k.rata === 'kanan' && 'kanan']">
                <slot :name="`sel-${k.kunci}`" :baris="b">{{ nilai(b, k.kunci) ?? '—' }}</slot>
              </td>
            </tr>
            <tr v-if="ruangBawah" aria-hidden="true" :style="{ height: `${ruangBawah}px` }"><td :colspan="kolom.length" /></tr>
          </tbody>
        </table>
      </div>
      <div v-if="adaLagi" class="muat-lagi">
        <Tombol :sibuk="memuatLagi" :disabled="memuatLagi" @click="emit('muatLagi')">{{ memuatLagi ? 'Memuat…' : 'Muat lagi' }}</Tombol>
      </div>
    </template>
  </div>
</template>

<style scoped>
.gulir {
  overflow: auto;
}
.gulir:focus-visible {
  outline: none;
  box-shadow: inset var(--ring);
}
.tbl {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--text-table);
}
.tbl th {
  position: sticky;
  top: 0;
  z-index: 1;
  padding: 10px var(--space-3);
  border-bottom: 1px solid var(--border);
  background: var(--bg);
  color: var(--muted);
  font-size: 12px;
  font-weight: var(--font-weight-semibold);
  text-align: left;
  white-space: nowrap;
}
.tbl td {
  padding: var(--space-2) var(--space-3);
  border-bottom: 1px solid var(--border);
  vertical-align: middle;
}
.kanan {
  text-align: right !important;
  font-variant-numeric: tabular-nums;
}
tr.dapat {
  cursor: pointer;
}
tr.dapat:hover td {
  background: var(--bg);
}
tr.terpilih td {
  background: var(--brand-25);
}
tr.dapat:focus-visible {
  outline: none;
}
tr.dapat:focus-visible td {
  box-shadow: inset 0 2px 0 var(--brand-600), inset 0 -2px 0 var(--brand-600);
}
.muat-lagi {
  padding: var(--space-2) var(--space-3) var(--space-3);
}
@media (max-width: 1279.98px) {
  .p3 {
    display: none;
  }
}
@media (max-width: 1023.98px) {
  .p2 {
    display: none;
  }
}
</style>
