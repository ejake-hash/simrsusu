<script setup lang="ts">
import { computed } from 'vue'
import Ikon from '@/design-system/ikon/Ikon.vue'

/**
 * Kartu angka ringkas (PRD 5.4). Hanya dari data asli: `nilai` null = belum ada data, tampil "—" dengan keterangan,
 * tidak pernah angka contoh. Angka diformat Indonesia (titik ribuan). `status` memberi penekanan teks + ikon.
 */
const props = withDefaults(
  defineProps<{
    label: string
    nilai: number | string | null
    satuan?: string
    keterangan?: string
    status?: 'normal' | 'peringatan' | 'bahaya'
    memuat?: boolean
  }>(),
  { satuan: undefined, keterangan: undefined, status: 'normal', memuat: false },
)
const FORMAT = new Intl.NumberFormat('id-ID')
const teksNilai = computed(() => (typeof props.nilai === 'number' ? FORMAT.format(props.nilai) : (props.nilai ?? '—')))
</script>

<template>
  <section class="kpi" :class="`kpi-${status}`" :aria-busy="memuat || undefined">
    <p class="kpi-label">
      <Ikon v-if="status !== 'normal'" nama="peringatan" :ukuran="14" />
      {{ label }}
    </p>
    <p v-if="memuat" class="kpi-nilai kpi-memuat" role="status"><span class="sr-only">Memuat {{ label }}</span></p>
    <p v-else class="kpi-nilai">
      <!-- Spasi lewat interpolasi: spasi literal di awal elemen dibuang kompiler template. -->
      {{ teksNilai }}<small v-if="satuan && nilai !== null">{{ ` ${satuan}` }}</small>
    </p>
    <p v-if="!memuat && nilai === null" class="kpi-keterangan">Data belum tersedia</p>
    <p v-else-if="keterangan && !memuat" class="kpi-keterangan">{{ keterangan }}</p>
  </section>
</template>

<style scoped>
.kpi {
  min-width: 0;
  padding: var(--space-4) 18px;
  border: 1px solid var(--border);
  border-radius: var(--radius-card);
  background: var(--surface);
}
.kpi-peringatan {
  border-color: var(--accent);
  background: var(--accent-bg);
}
.kpi-bahaya {
  border-color: var(--danger);
  background: var(--danger-bg);
}
.kpi-label {
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 0;
  font-size: 13px;
  color: var(--muted);
}
.kpi-peringatan .kpi-label {
  color: var(--accent-ink);
}
.kpi-bahaya .kpi-label {
  color: var(--danger);
}
.kpi-nilai {
  margin: var(--space-2) 0 var(--space-1);
  font-size: 26px;
  font-weight: var(--font-weight-bold);
  font-variant-numeric: tabular-nums;
  line-height: 1.1;
}
.kpi-nilai small {
  font-size: 14px;
  font-weight: var(--font-weight-regular);
  color: var(--muted);
}
.kpi-memuat {
  width: 60%;
  height: 28px;
  border-radius: 6px;
  background: var(--bg);
}
.kpi-keterangan {
  margin: 0;
  font-size: 12.5px;
  color: var(--muted);
}
</style>
