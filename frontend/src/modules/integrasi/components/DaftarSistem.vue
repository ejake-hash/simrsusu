<script setup lang="ts">
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed } from 'vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { daftarSistem } from '../api'
import { KUNCI_INTEGRASI } from '../integrasi'
import type { SistemIntegrasi, StatusSistemIntegrasi } from '../tipe'
import KartuSistem from './KartuSistem.vue'

/** Kartu status sistem integrasi dari GET /integrasi/sistem; `hanya` membatasi ke sistem satu sub-menu pemantauan. */
const props = defineProps<{ hanya?: SistemIntegrasi[] }>()
const qc = useQueryClient()
const kueri = useQuery({ queryKey: KUNCI_INTEGRASI.sistem, queryFn: daftarSistem, staleTime: 0, refetchInterval: 60_000 })
const daftar = computed(() => (kueri.data.value ?? []).filter((s) => !props.hanya || props.hanya.includes(s.kode)))
function berubah(baru: StatusSistemIntegrasi) {
  qc.setQueryData(KUNCI_INTEGRASI.sistem, (lama: StatusSistemIntegrasi[] | undefined) => (lama ?? []).map((s) => (s.kode === baru.kode ? baru : s)))
  void qc.invalidateQueries({ queryKey: KUNCI_INTEGRASI.semua, predicate: (q) => q.queryKey[1] !== 'sistem' })
}
defineExpose({ daftar })
</script>

<template>
  <div class="daftar-sistem">
    <p v-if="kueri.isPending.value" class="kartu kartu-isi" role="status">Memuat status integrasi…</p>
    <div v-else-if="kueri.isError.value" class="kartu kartu-isi" role="alert">
      <p class="catatan catatan-bahaya">{{ pesanError(kueri.error.value) }}</p>
      <button type="button" class="tombol" @click="kueri.refetch()">Coba lagi</button>
    </div>
    <KartuSistem v-for="s in daftar" v-else :key="s.kode" :status="s" @berubah="berubah" />
  </div>
</template>

<style scoped>
.daftar-sistem {
  display: grid;
  gap: var(--space-4);
  margin-bottom: var(--space-4);
}
</style>
