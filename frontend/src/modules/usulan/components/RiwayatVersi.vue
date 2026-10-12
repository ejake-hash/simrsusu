<script setup lang="ts">
import { useInfiniteQuery } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { formatTanggal, formatTanggalWaktu } from '@/shared/utils/waktu'
import { riwayatVersi } from '../api'
import { KUNCI_USULAN } from '../usulan'
import type { ObjekDataInduk } from '../tipe'

/**
 * Riwayat versi satu objek data induk (tiket 1.8c): setiap perubahan, lewat usulan maupun langsung, dengan isian yang
 * berubah (`tampilan`), tanggal berlaku, dasar, dan tautan ke usulannya. Dimuat saat lipatan dibuka.
 */
const props = defineProps<{ jenis: ObjekDataInduk; objekId: string }>()
const buka = ref(false)
const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_USULAN.versi(props.jenis, props.objekId)),
  queryFn: ({ pageParam }) => riwayatVersi(props.jenis, props.objekId, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
  enabled: buka,
})
const versi = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
</script>

<template>
  <details class="riwayat-versi" @toggle="buka = ($event.target as HTMLDetailsElement).open">
    <summary>Riwayat versi</summary>
    <p v-if="kueri.isPending.value && buka" class="ket" role="status">Memuat riwayat…</p>
    <p v-else-if="kueri.isError.value" class="ket" role="alert">{{ pesanError(kueri.error.value) }}</p>
    <p v-else-if="buka && !versi.length" class="ket">Belum ada perubahan tercatat sejak riwayat versi tersedia.</p>
    <ol v-else class="daftar">
      <li v-for="v in versi" :key="v.id">
        <div class="kepala">
          <b>Versi {{ v.versi }}</b>
          <span class="ket">berlaku {{ formatTanggal(`${v.berlaku_dari}T00:00:00`) }} · {{ v.diubah_oleh?.nama ?? 'Sistem' }}, {{ formatTanggalWaktu(v.diubah_pada) }}</span>
          <RouterLink v-if="v.usulan" :to="{ path: '/master-data/usulan', query: { usulan: v.usulan.id } }">{{ v.usulan.nomor }}</RouterLink>
        </div>
        <ul class="isian">
          <li v-for="t in v.tampilan" :key="t.isian">{{ t.label_isian }}: <span class="lama">{{ t.lama ?? '—' }}</span> → <b>{{ t.baru ?? '—' }}</b></li>
        </ul>
        <p class="ket">Dasar: {{ v.dasar }}</p>
      </li>
    </ol>
    <button v-if="kueri.hasNextPage.value" type="button" class="tombol" :disabled="kueri.isFetchingNextPage.value" @click="kueri.fetchNextPage()">Muat lagi</button>
  </details>
</template>

<style scoped>
.riwayat-versi {
  margin-top: var(--space-3);
}
summary {
  min-height: 32px;
  color: var(--muted);
  font-size: 13px;
  font-weight: var(--font-weight-semibold);
  cursor: pointer;
}
summary:focus-visible {
  outline: none;
  box-shadow: var(--ring);
  border-radius: 4px;
}
@media (min-width: 768px) and (max-width: 1279.98px) {
  summary {
    min-height: 44px;
    line-height: 44px;
  }
}
.daftar {
  display: grid;
  gap: var(--space-2);
  margin: var(--space-2) 0 0;
  padding: 0;
  list-style: none;
}
.kepala {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  align-items: baseline;
}
.isian {
  margin: 2px 0 0;
  padding-left: var(--space-4);
  font-size: 13px;
}
.lama {
  color: var(--muted);
  text-decoration: line-through;
}
.ket {
  margin: 2px 0 0;
  color: var(--muted);
  font-size: 12.5px;
}
</style>
