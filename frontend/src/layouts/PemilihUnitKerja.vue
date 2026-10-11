<script setup lang="ts">
import { computed, watch } from 'vue'
import { pilihUnitKerja, useUnitKerja } from '@/app/unitKerja'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { labelUnit, useUnitSaya } from '@/modules/organisasi/organisasi'

/**
 * Pemilih unit kerja di header: tampil hanya bila akun punya penugasan ber-cakupan unit (GET
 * /unit-organisasi?milik_saya=true). Satu unit = label saja; lebih dari satu = pilihan. Gagal dimuat = disembunyikan
 * (bukan syarat kerja).
 */
const unit = useUnitSaya()
const aktif = useUnitKerja()
const daftar = computed(() => unit.data.value ?? [])

watch(
  daftar,
  (d) => {
    if (!d.some((u) => u.id === aktif.value)) pilihUnitKerja(d[0]?.id ?? null)
  },
  { immediate: true },
)
const terpilih = computed(() => daftar.value.find((u) => u.id === aktif.value) ?? null)
</script>

<template>
  <div v-if="daftar.length" class="unit-kerja">
    <Ikon nama="umum" :ukuran="16" />
    <template v-if="daftar.length === 1 && terpilih">
      <span class="sr-only">Unit kerja: </span>
      <span class="teks" :title="labelUnit(terpilih)">{{ terpilih.nama }}</span>
    </template>
    <template v-else>
      <label class="sr-only" for="pilih-unit-kerja">Unit kerja</label>
      <select id="pilih-unit-kerja" class="pilih" :value="aktif ?? ''" @change="pilihUnitKerja(($event.target as HTMLSelectElement).value || null)">
        <option v-for="u in daftar" :key="u.id" :value="u.id">{{ u.nama }}</option>
      </select>
    </template>
  </div>
</template>

<style scoped>
.unit-kerja {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
  max-width: 220px;
  padding: 0 var(--space-2);
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
  background: var(--surface);
  color: var(--muted);
  font-size: 12.5px;
}
.unit-kerja svg {
  flex: none;
}
.teks {
  min-height: 34px;
  overflow: hidden;
  color: var(--text);
  line-height: 34px;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.pilih {
  min-width: 0;
  min-height: 34px;
  border: 0;
  background: transparent;
  color: var(--text);
  font: inherit;
  text-overflow: ellipsis;
  cursor: pointer;
}
.pilih:focus-visible {
  outline: none;
}
.unit-kerja:focus-within {
  box-shadow: var(--ring);
}
@media (min-width: 768px) and (max-width: 1279.98px) {
  .teks,
  .pilih {
    min-height: 44px;
    line-height: 44px;
  }
}
@media (max-width: 599.98px) {
  .unit-kerja {
    max-width: 132px;
  }
}
</style>
