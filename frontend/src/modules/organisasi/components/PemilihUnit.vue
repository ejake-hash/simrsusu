<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'
import { computed, nextTick, ref, useAttrs, useId, watch } from 'vue'
import { daftarUnit } from '../api'
import { refDebounced } from '@/shared/composables/refDebounced'
import { KUNCI_ORG, LABEL_JENIS, labelUnit, teksJalur } from '../organisasi'
import type { UnitOrganisasi } from '../tipe'

/**
 * Pemilih satu unit aktif dengan pencarian kode/nama (combobox WAI-ARIA, daftar di bawah isian, bukan popover agar
 * tetap di dalam dialog). Dipakai untuk induk unit dan cakupan penugasan. Atribut aksesibilitas dari KolomIsian
 * (id, aria-describedby, aria-invalid) dipasang di isian. `kecuali` = unit beserta turunannya tidak ditawarkan
 * (induk tidak boleh diri sendiri atau turunannya; server tetap menolak 422 siklus).
 */
defineOptions({ inheritAttrs: false })
const props = withDefaults(defineProps<{ labelTerpilih?: string | null; kecuali?: string | null; opsiKosong?: string | null; placeholder?: string }>(), {
  labelTerpilih: null,
  kecuali: null,
  opsiKosong: null,
  placeholder: 'Ketik kode atau nama unit',
})
const nilai = defineModel<string | null>({ required: true })
const emit = defineEmits<{ pilih: [unit: UnitOrganisasi | null] }>()
const attrs = useAttrs()

const idDaftar = `pemilih-unit-${useId()}`
const idOpsi = (i: number) => `${idDaftar}-${i}`
const buka = ref(false)
const kueri = ref('')
const aktif = ref(0)
const label = ref<string | null>(props.labelTerpilih)
watch(
  () => props.labelTerpilih,
  (l) => (label.value = l),
)

const kueriTunda = refDebounced(kueri, 250)
const hasil = useQuery({
  queryKey: computed(() => KUNCI_ORG.cariUnit(kueriTunda.value.trim())),
  queryFn: async () => (await daftarUnit({ cari: kueriTunda.value.trim() || undefined, status: 'aktif' }, null, 20)).data,
  enabled: buka,
  staleTime: 30_000,
})
const pilihan = computed<(UnitOrganisasi | null)[]>(() => {
  const k = props.kecuali
  const unit = (hasil.data.value ?? []).filter((u) => !k || (u.id !== k && !u.jalur.some((j) => j.id === k)))
  return [...(props.opsiKosong && !kueri.value.trim() ? [null] : []), ...unit]
})
watch(pilihan, () => (aktif.value = 0))
// Opsi aktif (panah atas/bawah) selalu terlihat di daftar yang bergulir.
watch(aktif, async (i) => {
  await nextTick()
  document.getElementById(idOpsi(i))?.scrollIntoView?.({ block: 'nearest' })
})

const tampil = computed(() => (buka.value ? kueri.value : nilai.value === null ? (props.opsiKosong ?? '') : (label.value ?? '')))

function bukaDaftar() {
  if (buka.value) return
  kueri.value = ''
  buka.value = true
}
function pilih(i: number) {
  const u = pilihan.value[i]
  if (u === undefined) return
  nilai.value = u?.id ?? null
  label.value = u ? labelUnit(u) : null
  buka.value = false
  emit('pilih', u)
}
function tekan(e: KeyboardEvent) {
  const n = pilihan.value.length
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault()
    if (!buka.value) return bukaDaftar()
    if (n) aktif.value = (aktif.value + (e.key === 'ArrowDown' ? 1 : -1) + n) % n
  } else if (e.key === 'Enter' && buka.value) {
    // Enter memilih, tidak mengirim formulir.
    e.preventDefault()
    pilih(aktif.value)
  } else if (e.key === 'Escape' && buka.value) {
    // Esc menutup daftar saja; dialog tidak ikut tertutup.
    e.preventDefault()
    e.stopPropagation()
    buka.value = false
  }
}
</script>

<template>
  <div class="pemilih">
    <input
      v-bind="attrs"
      class="isian"
      role="combobox"
      autocomplete="off"
      spellcheck="false"
      aria-autocomplete="list"
      :aria-expanded="buka"
      :aria-controls="idDaftar"
      :aria-activedescendant="buka && pilihan.length ? idOpsi(aktif) : undefined"
      :placeholder="placeholder"
      :value="tampil"
      @focus="bukaDaftar"
      @click="bukaDaftar"
      @input="((kueri = ($event.target as HTMLInputElement).value), (buka = true))"
      @keydown="tekan"
      @blur="buka = false"
    />
    <ul v-show="buka" :id="idDaftar" role="listbox" class="daftar" :aria-label="'Pilihan unit'">
      <li
        v-for="(u, i) in pilihan"
        :id="idOpsi(i)"
        :key="u?.id ?? 'kosong'"
        role="option"
        class="opsi"
        :class="{ aktif: i === aktif }"
        :aria-selected="(u?.id ?? null) === nilai"
        @mousedown.prevent="pilih(i)"
        @mousemove="aktif = i"
      >
        <template v-if="u">
          <span class="utama"><b>{{ u.kode }}</b> {{ u.nama }}</span>
          <span class="ket">{{ LABEL_JENIS[u.jenis] }}<template v-if="u.jalur.length"> · {{ teksJalur(u) }}</template></span>
        </template>
        <span v-else class="utama">{{ opsiKosong }}</span>
      </li>
      <li v-if="hasil.isFetching.value && !pilihan.length" class="status" role="presentation">Mencari…</li>
      <li v-else-if="hasil.isError.value" class="status" role="presentation">Gagal memuat unit. Ketik lagi untuk mencoba.</li>
      <li v-else-if="!pilihan.length" class="status" role="presentation">Tidak ada unit aktif yang cocok.</li>
    </ul>
  </div>
</template>

<style scoped>
.pemilih {
  position: relative;
}
.daftar {
  position: absolute;
  z-index: 5;
  inset: calc(100% + 4px) 0 auto;
  max-height: 248px;
  margin: 0;
  padding: 4px;
  overflow-y: auto;
  list-style: none;
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
  background: var(--surface);
  box-shadow: var(--shadow-laci);
}
.opsi {
  display: grid;
  gap: 1px;
  min-height: 40px;
  padding: 6px 8px;
  border-radius: 6px;
  cursor: pointer;
}
.opsi.aktif {
  background: var(--brand-50);
}
.utama {
  font-size: 13px;
}
.utama b {
  font-family: var(--font-mono);
  font-weight: var(--font-weight-semibold);
}
.ket,
.status {
  color: var(--muted);
  font-size: 12px;
}
.status {
  padding: 8px;
}
/* Kelas tablet: target sentuh 44 px (PRD 5.5 aturan 1). */
@media (min-width: 768px) and (max-width: 1279.98px) {
  .opsi {
    min-height: 44px;
  }
}
</style>
