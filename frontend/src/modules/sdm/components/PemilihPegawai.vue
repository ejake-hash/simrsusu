<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'
import { computed, nextTick, ref, useAttrs, useId, watch } from 'vue'
import { refDebounced } from '@/shared/composables/refDebounced'
import { daftarPegawai } from '../api'
import { KUNCI_SDM, LABEL_STATUS_PEGAWAI } from '../sdm'
import type { PegawaiRingkas } from '../tipe'

/**
 * Pemilih satu pegawai yang belum keluar (aktif atau akan keluar) dengan pencarian nama/NIP, untuk kepala unit dan
 * ketua KSM (kontrak v0.6.0). Combobox WAI-ARIA seperti PemilihUnit. Butuh sdm.pegawai.lihat (tidak ada izin silang
 * otomatis); pemakai menyembunyikannya bila izin itu tidak ada. Server tetap menolak pegawai keluar (422).
 */
defineOptions({ inheritAttrs: false })
const props = withDefaults(defineProps<{ labelTerpilih?: string | null; opsiKosong?: string | null; placeholder?: string }>(), {
  labelTerpilih: null,
  opsiKosong: null,
  placeholder: 'Ketik nama atau NIP pegawai',
})
const nilai = defineModel<string | null>({ required: true })
const attrs = useAttrs()

const idDaftar = `pemilih-pegawai-${useId()}`
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
  queryKey: computed(() => [...KUNCI_SDM.pegawai, 'cari', kueriTunda.value.trim()]),
  queryFn: async () => (await daftarPegawai({ cari: kueriTunda.value.trim() || undefined }, null, 20)).data,
  enabled: buka,
  staleTime: 30_000,
})
const pilihan = computed<(PegawaiRingkas | null)[]>(() => [
  ...(props.opsiKosong && !kueri.value.trim() ? [null] : []),
  ...(hasil.data.value ?? []).filter((p) => p.status !== 'keluar'),
])
watch(pilihan, () => (aktif.value = 0))
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
  const p = pilihan.value[i]
  if (p === undefined) return
  nilai.value = p?.id ?? null
  label.value = p?.nama ?? null
  buka.value = false
}
function tekan(e: KeyboardEvent) {
  const n = pilihan.value.length
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault()
    if (!buka.value) return bukaDaftar()
    if (n) aktif.value = (aktif.value + (e.key === 'ArrowDown' ? 1 : -1) + n) % n
  } else if (e.key === 'Enter' && buka.value) {
    e.preventDefault()
    pilih(aktif.value)
  } else if (e.key === 'Escape' && buka.value) {
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
    <ul v-show="buka" :id="idDaftar" role="listbox" class="daftar" :aria-label="'Pilihan pegawai'">
      <li
        v-for="(p, i) in pilihan"
        :id="idOpsi(i)"
        :key="p?.id ?? 'kosong'"
        role="option"
        class="opsi"
        :class="{ aktif: i === aktif }"
        :aria-selected="(p?.id ?? null) === nilai"
        @mousedown.prevent="pilih(i)"
        @mousemove="aktif = i"
      >
        <template v-if="p">
          <span class="utama">{{ p.nama }}</span>
          <span class="ket">{{ [p.nip, p.jabatan, p.unit?.nama, p.status === 'akan_keluar' ? LABEL_STATUS_PEGAWAI.akan_keluar : null].filter(Boolean).join(' · ') }}</span>
        </template>
        <span v-else class="utama">{{ opsiKosong }}</span>
      </li>
      <li v-if="hasil.isFetching.value && !pilihan.length" class="status" role="presentation">Mencari…</li>
      <li v-else-if="hasil.isError.value" class="status" role="presentation">Gagal memuat pegawai. Ketik lagi untuk mencoba.</li>
      <li v-else-if="!pilihan.length" class="status" role="presentation">Tidak ada pegawai aktif yang cocok.</li>
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
