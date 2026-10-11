<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'
import { computed, nextTick, ref, useAttrs, useId, watch } from 'vue'
import { refDebounced } from '@/shared/composables/refDebounced'
import { daftarLokasi } from '../apiLokasi'
import { KUNCI_LOKASI, LABEL_JENIS_LOKASI } from '../lokasi'
import type { JenisLokasi, Lokasi } from '../tipeLokasi'

/**
 * Pemilih satu lokasi yang tidak ditutup dengan pencarian kode lengkap/nama (combobox WAI-ARIA, pola sama dengan
 * PemilihUnit). Dipakai untuk induk lokasi (pindah induk) dan ruang poliklinik. `jenis` = jenis yang boleh dipilih;
 * `kecuali` = lokasi beserta turunannya tidak ditawarkan (server tetap menolak 422 siklus/jenis_induk_tidak_sesuai).
 */
defineOptions({ inheritAttrs: false })
const props = withDefaults(defineProps<{ jenis: JenisLokasi[]; labelTerpilih?: string | null; kecuali?: string | null; opsiKosong?: string | null; placeholder?: string }>(), {
  labelTerpilih: null,
  kecuali: null,
  opsiKosong: null,
  placeholder: 'Ketik kode lengkap atau nama, mis. A-1',
})
const nilai = defineModel<string | null>({ required: true })
const emit = defineEmits<{ pilih: [lokasi: Lokasi | null] }>()
const attrs = useAttrs()

const idDaftar = `pemilih-lokasi-${useId()}`
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
// Satu jenis = filter server; beberapa jenis = disaring di klien dari 50 hasil teratas.
const hasil = useQuery({
  queryKey: computed(() => [...KUNCI_LOKASI.cari(kueriTunda.value.trim()), props.jenis.join(',')]),
  queryFn: async () =>
    (await daftarLokasi({ cari: kueriTunda.value.trim() || undefined, jenis: props.jenis.length === 1 ? props.jenis[0] : undefined }, null, 50)).data,
  enabled: buka,
  staleTime: 30_000,
})
const pilihan = computed<(Lokasi | null)[]>(() => {
  const k = props.kecuali
  const daftar = (hasil.data.value ?? []).filter(
    (l) => props.jenis.includes(l.jenis) && l.status !== 'ditutup' && (!k || (l.id !== k && !l.jalur.some((j) => j.id === k))),
  )
  return [...(props.opsiKosong && !kueri.value.trim() ? [null] : []), ...daftar.slice(0, 20)]
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
  label.value = u ? `${u.kode_lengkap} · ${u.nama}` : null
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
    <ul v-show="buka" :id="idDaftar" role="listbox" class="daftar" :aria-label="'Pilihan lokasi'">
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
          <span class="utama"><b>{{ u.kode_lengkap }}</b> {{ u.nama }}</span>
          <span class="ket">{{ LABEL_JENIS_LOKASI[u.jenis] }}<template v-if="u.status === 'renovasi'"> · renovasi</template><template v-if="u.jalur.length"> · {{ u.jalur.map((j) => j.nama).join(' › ') }}</template></span>
        </template>
        <span v-else class="utama">{{ opsiKosong }}</span>
      </li>
      <li v-if="hasil.isFetching.value && !pilihan.length" class="status" role="presentation">Mencari…</li>
      <li v-else-if="hasil.isError.value" class="status" role="presentation">Gagal memuat lokasi. Ketik lagi untuk mencoba.</li>
      <li v-else-if="!pilihan.length" class="status" role="presentation">Tidak ada lokasi yang cocok.</li>
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
